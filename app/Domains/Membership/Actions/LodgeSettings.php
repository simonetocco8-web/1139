<?php

namespace App\Domains\Membership\Actions;

use App\Domains\Identity\Audit;
use App\Domains\Identity\Authorization\TenantContext;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Organization\Models\Lodge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class LodgeSettings
{
    /** @param array<string,mixed> $input */
    public function save(int $lodgeId, array $input): void
    {
        app(TenantContext::class)->assertTenant($lodgeId, 'administration');
        DB::transaction(function () use ($lodgeId, $input): void {
            $lodge = Lodge::query()->whereKey($lodgeId)->lockForUpdate()->firstOrFail();
            app(TenantContext::class)->assertTenant($lodgeId, 'administration');
            $values = Validator::make($input, ['name' => ['required', 'string', 'max:160'], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'], 'address' => ['nullable', 'string', 'max:255'], 'administrative_email' => ['nullable', 'email', 'max:255'], 'reminder_days' => ['required', 'integer', 'between:0,30'], 'brothers_treasury_visible' => ['required', 'boolean']])->validate();
            // Organization identifiers, status, roles and bank details are never accepted here.
            $current = hash('sha256', json_encode($lodge->only(array_keys($values)), JSON_THROW_ON_ERROR));
            abort_unless(isset($input['revision']) && is_string($input['revision']) && hash_equals($current, $input['revision']), 409, 'Impostazioni cambiate. Ricarica la pagina.');
            $lodge->forceFill($values)->save();
            Audit::record('membership.settings.updated', VerifiedSession::user()->id, $lodgeId);
        }, 3);
    }

    public function revision(Lodge $lodge): string
    {
        return hash('sha256', json_encode($lodge->only(['name', 'email', 'phone', 'address', 'administrative_email', 'reminder_days', 'brothers_treasury_visible']), JSON_THROW_ON_ERROR));
    }
}
