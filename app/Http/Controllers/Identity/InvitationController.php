<?php

namespace App\Http\Controllers\Identity;

use App\Domains\Identity\Actions\Invitations;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class InvitationController
{
    public function show(string $token, Invitations $invites): View
    {
        $invite = $invites->find($token);

        return view('identity.invitation', ['token' => $token, 'existing' => User::query()->where('email', $invite->email)->exists()]);
    }

    public function accept(Request $request, string $token, Invitations $invites): RedirectResponse
    {
        $invites->accept($token, $request->only('name', 'password', 'password_confirmation'));
        auth()->logout();
        session()->invalidate();
        session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Invito accettato. Per accedere occorrono password e secondo fattore.');
    }
}
