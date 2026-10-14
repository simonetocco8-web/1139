<?php

namespace App\Http\Controllers\Identity;

use App\Domains\Identity\Audit;
use App\Domains\Identity\Delivery\CodeTransport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class PasswordLinkController
{
    public function store(Request $request, CodeTransport $delivery): RedirectResponse
    {
        $input = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $message = 'Se la richiesta è valida e il servizio email è disponibile, riceverai le istruzioni. Un invio non confermato non concede alcun accesso. Il secondo fattore rimane necessario.';
        if (! $delivery->available('email')) {
            return back()->with('status', 'Il servizio email non è configurato. Nessun messaggio è stato inviato.');
        }
        try {
            Password::broker()->sendResetLink(['email' => mb_strtolower(trim($input['email'])), 'is_active' => true]);
        } catch (\Throwable) {
            Audit::record('password_link.delivery.failed');
        }

        return back()->with('status', $message);
    }
}
