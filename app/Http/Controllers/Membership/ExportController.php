<?php

namespace App\Http\Controllers\Membership;

use App\Domains\Identity\Audit;
use App\Domains\Identity\Authorization\TenantContext;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Membership\Actions\Members;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController
{
    public function __invoke(Members $members): StreamedResponse
    {
        $lodge = app(TenantContext::class)->resolve('export');
        $members->guard($lodge->id, 'export');
        Audit::record('membership.exported', VerifiedSession::user()->id, $lodge->id);

        return response()->streamDownload(function () use ($members, $lodge): void {
            $members->guard($lodge->id, 'export');
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                return;
            }
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['Codice', 'Nome', 'Cognome', 'Inizio', 'Data Depennamento', 'Stato', 'Esenzione amministrativa'], ';', '"', '');
            foreach ($members->directory($lodge->id)->with('enrollments')->orderBy('id')->lazy(100) as $person) {
                $members->guard($lodge->id, 'export');
                foreach ($person->enrollments as $enrollment) {
                    fputcsv($stream, [$this->cell($person->reference), $this->cell($person->first_name), $this->cell($person->last_name), $enrollment->starts_on->format('Y-m-d'), $enrollment->ends_on?->format('Y-m-d'), __('membership.status.'.$enrollment->status), $enrollment->administrative_exemption ? 'Sì' : 'No'], ';', '"', '');
                }
            }
            fclose($stream);
        }, 'iscritti.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function cell(string $value): string
    {
        return preg_match('/^[\s]*[=+@\-\t\r\n]/u', $value) === 1 ? "'".$value : $value;
    }
}
