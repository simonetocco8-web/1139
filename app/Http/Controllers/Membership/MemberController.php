<?php

namespace App\Http\Controllers\Membership;

use App\Domains\Identity\Actions\Invitations;
use App\Domains\Identity\Authorization\TenantContext;
use App\Domains\Identity\Delivery\DeliveryUnavailable;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Membership\Actions\Members;
use App\Domains\Membership\Models\Person;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MemberController
{
    public function __construct(private Members $members) {}

    public function index(Request $request): View
    {
        $lodge = app(TenantContext::class)->resolve();
        $values = $request->validate(['search' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', 'in:active,suspended,ended']]);
        $query = $this->members->directory($lodge->id)->with('enrollments')->orderBy('last_name')->orderBy('first_name');
        if (! empty($values['search'])) {
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $values['search']);
            $query->where(fn ($q) => $q->where('first_name', 'like', '%'.$term.'%')->orWhere('last_name', 'like', '%'.$term.'%'));
        }
        if (! empty($values['status'])) {
            // Latest enrollment status; former ended periods must not match active members.
            $query->whereHas('enrollments', fn ($q) => $q->where('status', $values['status'])->whereRaw('member_enrollments.starts_on = (select max(e.starts_on) from member_enrollments e where e.person_id = member_enrollments.person_id and e.lodge_id = member_enrollments.lodge_id)'));
        }

        return view('membership.index', ['lodge' => $lodge, 'people' => $query->paginate(15)->withQueryString(), 'filters' => $values]);
    }

    public function create(int $lodge): View
    {
        $tenant = $this->members->guard($lodge, 'administration');

        return view('membership.create', ['lodge' => $tenant]);
    }

    public function store(Request $request, int $lodge): RedirectResponse
    {
        $this->confirmed($request);
        $person = $this->members->create($lodge, $request->all());

        return redirect()->route('members.show', [$lodge, $person->id])->with('status', 'Persona e iscrizione inserite.');
    }

    public function show(int $lodge, int $person): View
    {
        $tenant = $this->members->guard($lodge);
        $record = $this->members->directory($lodge)->whereKey($person)->with(['enrollments' => fn ($q) => $q->orderByDesc('starts_on')])->firstOrFail();

        return view('membership.show', ['lodge' => $tenant, 'person' => $record, 'history' => $record->history()->orderByDesc('id')->paginate(10)]);
    }

    public function update(Request $request, int $lodge, int $person): RedirectResponse
    {
        $this->confirmed($request);
        $this->members->update($lodge, $person, $request->all());

        return back()->with('status', 'Anagrafica aggiornata; storico conservato.');
    }

    public function enrollment(Request $request, int $lodge, int $person): RedirectResponse
    {
        $this->confirmed($request);
        $request->validate(['enrollment_id' => ['nullable', 'integer', 'min:1']]);
        $id = $request->filled('enrollment_id') ? $request->integer('enrollment_id') : null;
        $this->members->enrollment($lodge, $person, $request->all(), $id);

        return back()->with('status', 'Iscrizione aggiornata.');
    }

    public function deactivate(Request $request, int $lodge, int $person, int $enrollment): RedirectResponse
    {
        $this->confirmed($request);
        $request->validate(['version' => ['required', 'integer'], 'ends_on' => ['required', 'date_format:Y-m-d']]);
        $this->members->deactivate($lodge, $person, $enrollment, $request->integer('version'), $request->string('ends_on')->toString());

        return back()->with('status', 'Iscrizione conclusa. Dati e storico conservati.');
    }

    public function invite(Request $request, int $lodge, int $person, Invitations $invites): RedirectResponse
    {
        $this->confirmed($request);
        $this->members->guard($lodge, 'administration');
        $record = $this->members->directory($lodge)->whereKey($person)->firstOrFail();
        abort_if(empty($record->email), 422, 'Inserisci prima l’email anagrafica.');
        try {
            $invites->lodge($lodge, $record->email, ['brother'], personId: $record->id);
        } catch (DeliveryUnavailable $e) {
            return back()->withErrors(['invitation' => $e->getMessage()]);
        }

        return back()->with('status', 'Invito accettato dal canale email. Associazione solo dopo accettazione del destinatario.');
    }

    public function position(): View
    {
        $lodge = app(TenantContext::class)->resolve();
        $person = Person::query()->where('lodge_id', $lodge->id)->where('user_id', VerifiedSession::user()->id)->first();

        return view('membership.position', ['lodge' => $lodge, 'person' => $person, 'enrollments' => $person?->enrollments()->orderByDesc('starts_on')->get() ?? collect()]);
    }

    private function confirmed(Request $request): void
    {
        $request->validate(['confirmation' => ['required', 'accepted']]);
    }
}
