<?php

namespace App\Http\Controllers\Identity;

use Laravel\Fortify\Contracts\PasswordResetResponse;

class ResetCompletedResponse implements PasswordResetResponse
{
    public function toResponse($request)
    {
        $message = 'Password aggiornata. Accedi con password e secondo fattore.';

        return $request->wantsJson() ? response()->json(['message' => $message]) : redirect()->route('login')->with('status', $message);
    }
}
