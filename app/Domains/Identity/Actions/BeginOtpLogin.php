<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Delivery\CodeTransport;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class BeginOtpLogin
{
    public function __construct(private OtpService $otp) {}

    public function handle(Request $request, Closure $next): RedirectResponse
    {
        $challenge = $this->otp->beginLogin($this->otp->credentials((string) $request->input('email'), (string) $request->input('password')));
        $notice = $challenge->delivery_state === 'failed'
            ? (app(CodeTransport::class)->available($challenge->channel) ? 'Invio del codice non confermato. Nessun accesso è stato autorizzato.' : 'Il servizio di verifica non è configurato. Nessun messaggio è stato inviato.')
            : 'Se i dati e il canale sono validi, riceverai un codice di verifica. L’accesso rimane incompleto.';

        return redirect()->route('otp.show')->with('status', $notice);
    }
}
