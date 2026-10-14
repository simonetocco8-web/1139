<?php

namespace App\Http\Controllers\Identity;

use App\Domains\Identity\Actions\AccountSecurity;
use App\Domains\Identity\Actions\SessionSecurity;
use App\Domains\Identity\VerifiedSession;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountController
{
    public function contact(Request $request, AccountSecurity $security): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:128'], 'channel' => ['required', 'in:email,sms'], 'target' => ['required', 'string', 'max:255']]);
        $security->changeContact($data['channel'], $data['target'], $data['password']);

        return redirect()->route('otp.show')->with('status', 'Il recapito cambierà soltanto dopo la verifica del codice. Se il canale non è disponibile, nessun messaggio è inviato.');
    }

    public function channel(Request $request, AccountSecurity $security): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:128'], 'channel' => ['required', 'in:email,sms']]);
        $security->setChannel($data['channel'], $data['password']);
        auth()->logout();
        session()->invalidate();
        session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Canale aggiornato. Accedi nuovamente.');
    }

    public function codes(Request $request, AccountSecurity $security): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:128']]);

        return back()->with('recovery_codes', $security->recoveryCodes($data['password']));
    }

    public function revoke(SessionSecurity $security): RedirectResponse
    {
        $user = VerifiedSession::recent();
        DB::transaction(fn () => $security->revoke(User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail()), 3);
        auth()->logout();
        session()->invalidate();
        session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Le sessioni sono state revocate.');
    }

    public function recover(Request $request, AccountSecurity $security): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'max:128'], 'recovery_code' => ['required', 'string', 'max:64'], 'channel' => ['required', 'in:email,sms'], 'target' => ['required', 'string', 'max:255']]);
        $security->recover($data['email'], $data['password'], $data['recovery_code'], $data['channel'], $data['target']);

        return redirect()->route('otp.show')->with('status', 'Se la richiesta è valida, riceverai un codice al nuovo recapito. Nessun accesso è stato autorizzato.');
    }
}
