<?php

namespace App\Domains\Identity;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class RecentVerificationRequired extends HttpException
{
    public function __construct()
    {
        parent::__construct(403, 'Per questa operazione è necessario un OTP verificato negli ultimi 5 minuti. Esci e accedi nuovamente con password e OTP, poi ripeti l’operazione.');
    }
}
