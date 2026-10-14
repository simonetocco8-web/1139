<?php

namespace App\Http\Controllers\Identity;

use Laravel\Fortify\Contracts\FailedPasswordResetResponse;

class UniformResetFailure implements FailedPasswordResetResponse
{
    public function toResponse($request)
    {
        $message = 'Richiesta non valida o scaduta.';

        return $request->wantsJson() ? response()->json(['message' => $message], 422) : back()->withErrors(['email' => $message]);
    }
}
