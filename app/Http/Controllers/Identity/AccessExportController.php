<?php

namespace App\Http\Controllers\Identity;

use App\Domains\Identity\Audit;
use App\Domains\Identity\Authorization\TenantContext;
use App\Domains\Identity\VerifiedSession;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccessExportController
{
    public function __invoke(TenantContext $context): StreamedResponse
    {
        $lodge = $context->resolve('export');
        $context->assertTenant($lodge->id, 'administration');
        $actor = VerifiedSession::user();
        Audit::record('access.exported', $actor->id, $lodge->id);

        return response()->streamDownload(function () use ($lodge, $context): void {
            $context->assertTenant($lodge->id, 'export');
            $context->assertTenant($lodge->id, 'administration');
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                return;
            }
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['Nome', 'Email', 'Ruolo', 'Accesso attivo'], ';', '"', '');
            $rows = DB::table('lodge_user_roles')->join('lodge_user', function ($join): void {
                $join->on('lodge_user.lodge_id', '=', 'lodge_user_roles.lodge_id')->on('lodge_user.user_id', '=', 'lodge_user_roles.user_id');
            })
                ->join('users', 'users.id', '=', 'lodge_user.user_id')->where('lodge_user.lodge_id', $lodge->id)
                ->orderBy('users.id')->select(['users.name', 'users.email', 'lodge_user_roles.role', 'lodge_user.is_active'])->cursor();
            foreach ($rows as $row) {
                fputcsv($stream, [$this->cell($row->name), $this->cell($row->email), __('identity.roles.'.$row->role), $row->is_active ? 'Sì' : 'No'], ';', '"', '');
            }
            fclose($stream);
        }, 'accessi.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    private function cell(string $value): string
    {
        return preg_match('/^[\s]*[=+@\-\t\r\n]/u', $value) === 1 ? "'".$value : $value;
    }
}
