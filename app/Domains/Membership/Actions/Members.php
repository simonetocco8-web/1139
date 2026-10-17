<?php

namespace App\Domains\Membership\Actions;

use App\Domains\Capitations\Actions\ForecastSync;
use App\Domains\Identity\Audit;
use App\Domains\Identity\Authorization\TenantAccess;
use App\Domains\Identity\Authorization\TenantContext;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Membership\Models\Enrollment;
use App\Domains\Membership\Models\MemberHistory;
use App\Domains\Membership\Models\Person;
use App\Domains\Organization\Models\Lodge;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class Members
{
    public function guard(int $lodgeId, string $permission = 'read', bool $directory = true): Lodge
    {
        $lodge = app(TenantContext::class)->assertTenant($lodgeId, $permission);
        if ($directory) {
            abort_unless(app(TenantAccess::class)->fullRead(VerifiedSession::user(), $lodge->id), 403);
        }

        return $lodge;
    }

    /** @return Builder<Person> */
    public function directory(int $lodgeId): Builder
    {
        $this->guard($lodgeId);

        return Person::query()->where('lodge_id', $lodgeId);
    }

    public function ownPerson(int $lodgeId): Person
    {
        $this->guard($lodgeId, 'read', false);

        return Person::query()->where('lodge_id', $lodgeId)->where('user_id', VerifiedSession::user()->id)->firstOrFail();
    }

    // Future economic queries must filter BOTH lodge_id and this enrollment id.
    public function ownEnrollment(int $lodgeId, int $id): Enrollment
    {
        return $this->ownPerson($lodgeId)->enrollments()->whereKey($id)->firstOrFail();
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function personValues(int $lodgeId, array $input, ?int $id = null): array
    {
        $input['reference'] = strtoupper(trim((string) ($input['reference'] ?? '')));
        foreach (['birth_date', 'phone', 'address'] as $key) {
            if (($input[$key] ?? null) === '') {
                $input[$key] = null;
            }
        }
        $input['email'] = empty($input['email']) ? null : mb_strtolower(trim((string) $input['email']));

        return Validator::make($input, [
            'reference' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('member_people', 'reference')->where('lodge_id', $lodgeId)->ignore($id)],
            'first_name' => ['required', 'string', 'max:120'], 'last_name' => ['required', 'string', 'max:120'],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'], 'address' => ['nullable', 'string', 'max:255'],
        ])->validate();
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function enrollmentValues(array $input): array
    {
        if (($input['ends_on'] ?? null) === '') {
            $input['ends_on'] = null;
        }
        if (($input['status'] ?? null) === 'ended' && empty($input['ends_on'])) {
            $input['ends_on'] = now()->toDateString();
        }
        if (in_array($input['status'] ?? null, ['active', 'suspended'], true)) {
            $input['ends_on'] = null;
        }
        if (in_array($input['status'] ?? null, ['suspended', 'ended'], true)) {
            $input['administrative_exemption'] = true;
            $input['exemption_reason'] = $input['exemption_reason'] ?? 'Esenzione prevista dallo stato amministrativo.';
            if ($input['exemption_reason'] === '') {
                $input['exemption_reason'] = 'Esenzione prevista dallo stato amministrativo.';
            }
        }
        $values = Validator::make($input, [
            'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'status' => ['required', Rule::in(['active', 'suspended', 'ended'])],
            'administrative_exemption' => ['required', 'boolean'], 'exemption_reason' => ['nullable', 'string', 'max:1000', 'required_if:administrative_exemption,1'],
        ])->validate();
        if ($values['status'] === 'ended' && empty($values['ends_on'])) {
            throw ValidationException::withMessages(['ends_on' => 'Il depennamento richiede una data valida.']);
        }
        if ($values['status'] === 'ended' && $values['ends_on'] > now()->toDateString()) {
            throw ValidationException::withMessages(['ends_on' => 'La data di depennamento non può essere futura.']);
        }
        if (! $values['administrative_exemption']) {
            $values['exemption_reason'] = null;
        }

        return $values;
    }

    /** @param array<string,mixed> $input */
    public function create(int $lodgeId, array $input): Person
    {
        $this->guard($lodgeId, 'members.manage');

        return DB::transaction(function () use ($lodgeId, $input): Person {
            Lodge::query()->whereKey($lodgeId)->lockForUpdate()->firstOrFail();
            $this->guard($lodgeId, 'members.manage');
            $values = $this->personValues($lodgeId, $input);
            $enrollment = $this->enrollmentValues($input);
            $person = Person::query()->forceCreate($values + ['lodge_id' => $lodgeId, 'version' => 1]);
            $person->enrollments()->forceCreate($enrollment + ['lodge_id' => $lodgeId, 'version' => 1]);
            $this->history($person, 'created');

            return $person;
        }, 3);
    }

    /** @param array<string,mixed> $input */
    public function update(int $lodgeId, int $id, array $input): Person
    {
        $this->guard($lodgeId, 'members.manage');

        return DB::transaction(function () use ($lodgeId, $id, $input): Person {
            Lodge::query()->whereKey($lodgeId)->lockForUpdate()->firstOrFail();
            $person = $this->directory($lodgeId)->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->guard($lodgeId, 'members.manage');
            $this->version($person->version, $input['version'] ?? null);
            $values = $this->personValues($lodgeId, $input, $id);
            if ($person->email !== ($values['email'] ?? null)) {
                DB::table('identity_invitations')->where('lodge_id', $lodgeId)->where('person_id', $id)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now('UTC')]);
            }
            $person->fill($values);
            $person->version++;
            $person->save();
            $this->history($person, 'person.updated');

            return $person;
        }, 3);
    }

    /** @param array<string,mixed> $input */
    public function enrollment(int $lodgeId, int $personId, array $input, ?int $id = null): Enrollment
    {
        $this->guard($lodgeId, 'members.manage');

        return DB::transaction(function () use ($lodgeId, $personId, $input, $id): Enrollment {
            Lodge::query()->whereKey($lodgeId)->lockForUpdate()->firstOrFail();
            $person = $this->directory($lodgeId)->whereKey($personId)->lockForUpdate()->firstOrFail();
            $this->guard($lodgeId, 'members.manage');
            $enrollment = $id === null ? new Enrollment : $person->enrollments()->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($enrollment->exists && $enrollment->status === 'ended', 409, 'L’iscrizione conclusa resta immutabile.');
            if ($enrollment->exists) {
                $this->version($enrollment->version, $input['version'] ?? null);
            }
            $values = $this->enrollmentValues($input);
            $overlap = $person->enrollments()->when($id !== null, fn ($q) => $q->where('id', '!=', $id))
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $values['starts_on']));
            if (! empty($values['ends_on'])) {
                $overlap->where('starts_on', '<=', $values['ends_on']);
            }
            if ($overlap->exists()) {
                throw ValidationException::withMessages(['starts_on' => 'L’intervallo si sovrappone a un’iscrizione esistente.']);
            }
            $enrollment->fill($values)->forceFill(['lodge_id' => $lodgeId, 'person_id' => $personId, 'version' => $enrollment->exists ? $enrollment->version + 1 : 1])->save();
            if ($enrollment->status === 'ended') {
                DB::table('identity_invitations')->where('lodge_id', $lodgeId)->where('person_id', $personId)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now('UTC')]);
            }
            $this->history($person, $id === null ? 'enrollment.created' : 'enrollment.updated');

            return $enrollment;
        }, 3);
    }

    public function deactivate(int $lodgeId, int $personId, int $id, int $version, string $endsOn): void
    {
        $this->guard($lodgeId, 'members.manage');
        $enrollment = $this->directory($lodgeId)->whereKey($personId)->firstOrFail()->enrollments()->whereKey($id)->firstOrFail();
        $this->enrollment($lodgeId, $personId, ['starts_on' => $enrollment->starts_on->format('Y-m-d'), 'ends_on' => $endsOn, 'status' => 'ended', 'version' => $version, 'administrative_exemption' => $enrollment->administrative_exemption, 'exemption_reason' => $enrollment->exemption_reason], $id);
    }

    public function history(Person $person, string $event): void
    {
        app(ForecastSync::class)->sync($person->lodge_id, VerifiedSession::user()->id, $person->id);
        MemberHistory::query()->create(['lodge_id' => $person->lodge_id, 'person_id' => $person->id, 'actor_id' => VerifiedSession::user()->id, 'event' => $event,
            'snapshot' => ['person' => $person->attributesToArray(), 'enrollments' => $person->enrollments()->get()->map(fn ($e) => $e->attributesToArray())->all()], 'occurred_at' => now('UTC')]);
        Audit::record('membership.'.$event, VerifiedSession::user()->id, $person->lodge_id, metadata: ['person_id' => $person->id]);
    }

    private function version(int $actual, mixed $submitted): void
    {
        abort_unless(is_numeric($submitted) && (int) $submitted === $actual, 409, 'Dati modificati da un’altra operazione. Ricarica la scheda.');
    }
}
