<?php

namespace App\Models;

use App\Domains\Identity\Delivery\CodeTransport;
use App\Domains\Identity\UtcDateTime;
use App\Domains\Organization\Models\Lodge;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property bool $is_active
 * @property int $session_version
 * @property CarbonImmutable|null $activated_at
 * @property string|null $phone
 * @property CarbonImmutable|null $phone_verified_at
 * @property string $otp_channel
 * @property bool $is_platform_admin
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'phone', 'session_version'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            'is_active' => 'boolean',
            'activated_at' => UtcDateTime::class,
            'session_version' => 'integer',
            'phone' => 'encrypted',
            'phone_verified_at' => UtcDateTime::class,
        ];
    }

    /**
     * Get the user's initials
     */
    /** @return BelongsToMany<Lodge, $this> */
    public function lodges(): BelongsToMany
    {
        return $this->belongsToMany(Lodge::class)->withPivot('is_active');
    }

    /** @return Attribute<string, string> */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => mb_strtolower(trim($value)));
    }

    public function sendPasswordResetNotification($token): void
    {
        app(CodeTransport::class)->send('email', $this->email,
            'Recupero credenziali', 'Per impostare una nuova password apri il link: '.route('password.reset', ['token' => $token, 'email' => $this->email])."\nIl link scade dopo 15 minuti. Il secondo fattore rimane necessario.");
    }

    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
