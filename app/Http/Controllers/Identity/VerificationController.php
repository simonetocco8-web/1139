<?php

namespace App\Http\Controllers\Identity;

use App\Domains\Identity\Actions\OtpService;
use App\Domains\Identity\Delivery\CodeTransport;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VerificationController
{
    public function show(OtpService $otp): View|RedirectResponse
    {
        if ($otp->pending() === null) {
            return redirect()->route('login');
        }

        return view('identity.otp');
    }

    public function verify(Request $request, OtpService $otp): RedirectResponse
    {
        $input = $request->validate(['code' => ['required', 'string', 'regex:/^[0-9]{6}$/D']]);
        $purpose = $otp->verify($input['code']);
        if ($purpose === null) {
            return back()->withErrors(['code' => 'Codice non valido, scaduto o non utilizzabile.']);
        }

        return $purpose === 'login' ? redirect()->route('dashboard') : redirect()->route('login')->with('status', 'Recapito verificato. Accedi nuovamente con password e secondo fattore.');
    }

    public function resend(OtpService $otp): RedirectResponse
    {
        $challenge = $otp->resend();
        $message = $challenge?->delivery_state === 'failed'
            ? (app(CodeTransport::class)->available($challenge->channel) ? 'Invio del codice non confermato. Nessun accesso autorizzato.' : 'Il canale non è configurato. Nessun messaggio è stato inviato.')
            : 'Se la richiesta è valida e sono trascorsi 60 secondi, viene richiesto un nuovo codice. Il codice precedente non sarà più valido.';

        return back()->with('status', $message);
    }
}
