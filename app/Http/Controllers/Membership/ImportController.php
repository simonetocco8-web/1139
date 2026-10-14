<?php

namespace App\Http\Controllers\Membership;

use App\Domains\Identity\Authorization\TenantContext;
use App\Domains\Membership\Actions\CsvImport;
use App\Domains\Membership\Actions\Members;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController
{
    public function index(Members $members): View
    {
        $lodge = app(TenantContext::class)->resolve('administration');
        $members->guard($lodge->id, 'administration');

        return view('membership.import', ['lodge' => $lodge]);
    }

    public function preview(Request $request, int $lodge, CsvImport $import): RedirectResponse
    {
        app(Members::class)->guard($lodge, 'administration');
        $request->validate(['csv' => ['required', 'file', 'max:256']]);
        $file = $request->file('csv');
        abort_unless($file instanceof UploadedFile, 422);
        $csv = file_get_contents($file->getRealPath());
        abort_if($csv === false, 422);
        $preview = $import->preview($lodge, $csv);

        return redirect()->route('members.import.preview', [$lodge, $preview['token']]);
    }

    public function show(int $lodge, string $token, CsvImport $import): View
    {
        return view('membership.preview', ['lodge' => app(Members::class)->guard($lodge, 'administration'), 'batch' => $import->find($lodge, $token), 'token' => $token]);
    }

    public function confirm(Request $request, int $lodge, string $token, CsvImport $import): RedirectResponse
    {
        app(Members::class)->guard($lodge, 'administration');
        $request->validate(['confirmation' => ['required', 'accepted']]);
        $count = $import->confirm($lodge, $token);

        return redirect()->route('members.index')->with('status', $count.' iscritti importati.');
    }

    public function template(Members $members): StreamedResponse
    {
        $lodge = app(TenantContext::class)->resolve('administration');
        $members->guard($lodge->id, 'administration');

        return response()->streamDownload(function (): void {
            $stream = fopen('php://output', 'wb');
            if ($stream !== false) {
                fputcsv($stream, CsvImport::HEADERS, ',', '"', '');
                fputcsv($stream, ['DEMO_001', 'NomeFittizio', 'CognomeFittizio', '', 'esempio@example.invalid', '', '', now()->toDateString(), '', 'active', '0', ''], ',', '"', '');
                fclose($stream);
            }
        }, 'modello-iscritti.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
