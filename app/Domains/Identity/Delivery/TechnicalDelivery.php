<?php

namespace App\Domains\Identity\Delivery;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

final class TechnicalDelivery implements CodeTransport
{
    public function __construct(private FakeTransport $fake) {}

    public function available(string $channel): bool
    {
        if (config('identity.delivery_driver') === 'fake') {
            return $this->fake->available($channel);
        }
        if (config('identity.delivery_driver') !== 'configured') {
            return false;
        }
        if ($channel === 'email') {
            return (! app()->isProduction() || config('mail.mailers.smtp.scheme') === 'smtps' || config('mail.mailers.smtp.require_tls') === true)
                && config('mail.default') === 'smtp'
                && is_string(config('mail.mailers.smtp.host')) && config('mail.mailers.smtp.host') !== ''
                && is_string(config('mail.from.address')) && config('mail.from.address') !== '';
        }
        if ($channel === 'sms' && config('identity.sms.driver') === 'http') {
            $endpoint = config('identity.sms.endpoint');

            return is_string($endpoint) && str_starts_with($endpoint, 'https://')
                && is_string(config('identity.sms.token')) && config('identity.sms.token') !== '';
        }

        return false;
    }

    public function send(string $channel, string $destination, string $subject, string $body): void
    {
        if (! $this->available($channel)) {
            throw new DeliveryUnavailable('Canale non configurato. Nessun messaggio inviato.');
        }
        if (config('identity.delivery_driver') === 'fake') {
            $this->fake->send($channel, $destination, $subject, $body);

            return;
        }
        if ($channel === 'email') {
            Mail::mailer('smtp')->to($destination)->send(new TechnicalMessage($subject, $body));

            return;
        }
        $response = Http::withToken((string) config('identity.sms.token'))->acceptJson()->timeout(10)
            ->post((string) config('identity.sms.endpoint'), ['to' => $destination, 'text' => $body, 'sender' => config('identity.sms.sender')]);
        if (! $response->successful() || $response->json('accepted') !== true) {
            throw new DeliveryUnavailable('Il provider non ha accettato il messaggio.');
        }
    }
}
