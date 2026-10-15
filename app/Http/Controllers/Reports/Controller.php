<?php

namespace App\Http\Controllers\Reports;

use App\Domains\Capitations\Money;
use App\Domains\Identity\Audit;
use App\Domains\Identity\Authorization\TenantAccess;
use App\Domains\Identity\Authorization\TenantContext;
use App\Domains\Identity\VerifiedSession;
use App\Domains\Organization\Actions\ActiveLodge;
use App\Domains\Reports\Builder;
use App\Domains\Reports\Dashboards;
use App\Domains\Reports\Period;
use App\Domains\Reports\Runtime;
use App\Domains\Treasury\Actions\Reports as ExportText;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

final class Controller
{
    public function index(Dashboards $dashboards, Request $request): View
    {
        $user = VerifiedSession::user();
        if ($user->is_platform_admin) {
            return $this->platform();
        }
        $lodge = app(ActiveLodge::class)->current();
        if ($lodge === null) {
            return view('reports.entry');
        }

        return $this->tenant($dashboards, $request, $lodge->id);
    }

    public function platform(): View
    {
        return view('reports.platform', ['data' => app(Runtime::class)->platform()]);
    }

    public function tenant(Dashboards $dashboards, Request $request, int $lodge): View
    {
        $selected = app(TenantContext::class)->assertTenant($lodge, 'read');
        $period = Period::make($request->only(['from', 'to']));
        $staff = app(TenantAccess::class)->fullRead(VerifiedSession::user(), $lodge);

        return $staff ? view('reports.staff', $dashboards->staff($selected->id, $period)) : view('reports.brother', $dashboards->brother($selected->id, $period));
    }

    public function show(Request $request, Builder $builder, int $lodge, string $kind): View
    {
        $document = $builder->make($lodge, $kind, $request->only(['from', 'to', 'year']));
        $selected = app(TenantContext::class)->assertTenant($lodge, $kind === 'personal' ? 'read' : 'reports');
        $page = $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $number = (int) ($page['page'] ?? 1);
        $rows = new LengthAwarePaginator(array_slice($document->rows, ($number - 1) * 20, 20), count($document->rows), 20, $number, ['path' => $request->url(), 'query' => $request->query()]);

        return view('reports.report', ['lodge' => $selected, 'kind' => $kind, 'document' => $document, 'rows' => $rows, 'canExport' => app(TenantAccess::class)->allows(VerifiedSession::user(), $lodge, 'export')]);
    }

    public function export(Request $request, Builder $builder, int $lodge, string $kind, string $format): Response
    {
        abort_unless(in_array($format, ['csv', 'pdf'], true), 404);
        app(TenantContext::class)->assertTenant($lodge, 'export');
        $document = $builder->make($lodge, $kind, $request->only(['from', 'to', 'year']));
        $selected = app(TenantContext::class)->assertTenant($lodge, $kind === 'personal' ? 'read' : 'reports');
        $limit = $format === 'pdf' ? 250 : 5000;
        abort_if(count($document->rows) > $limit, 422, 'Restringi il periodo: massimo '.$limit.' righe per questa esportazione.');
        Audit::record('reports.exported', VerifiedSession::user()->id, $lodge, metadata: ['kind' => $kind, 'format' => $format, 'from' => $document->period->from, 'to' => $document->period->to, 'rows' => count($document->rows)]);
        $headers = ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'attachment; filename="report-'.$kind.'.'.$format.'"'];
        if ($format === 'pdf') {
            $cache = storage_path('framework/cache/dompdf');
            File::ensureDirectoryExists($cache, 0700, true);
            $options = new Options;
            $options->set('fontCache', $cache);
            $options->set('tempDir', $cache);
            $options->set('defaultFont', 'DejaVu Sans');
            $options->set('isRemoteEnabled', false);
            $options->set('isPhpEnabled', false);
            $options->set('isJavascriptEnabled', false);
            $pdf = new Dompdf($options);
            $pdf->loadHtml(view('reports.pdf', ['lodge' => $selected, 'document' => $document])->render());
            $pdf->setPaper('A4', 'landscape');
            $pdf->render();

            return response($pdf->output(), 200, $headers + ['Content-Type' => 'application/pdf']);
        }
        $f = fopen('php://temp', 'r+');
        if ($f === false) {
            throw new \RuntimeException('Esportazione non disponibile.');
        }try {
            fwrite($f, "\xEF\xBB\xBF");
            $write = fn (array $cells) => fputcsv($f, $cells, ';', '"', '');
            $write([$document->title]);
            $write(['Loggia', ExportText::cell($selected->name)]);
            $write(['Periodo', Period::italian($document->period->from), Period::italian($document->period->to)]);
            $write(['Criterio', ExportText::cell($document->note)]);
            $write(['Indicatore', 'Valore EUR']);
            foreach ($document->metrics as $label => $cents) {
                $write([$label, str_replace('.', ',', Money::decimal($cents))]);
            }$write([]);
            $write(array_column($document->columns, 'label'));
            foreach ($document->rows as $row) {
                $cells = [];
                foreach ($document->columns as $key => $column) {
                    $value = $row[$key];
                    $cells[] = match ($column['type']) {
                        'money' => str_replace('.', ',', Money::decimal($this->money($value))),'date' => Period::italian((string) $value),default => ExportText::cell((string) $value)
                    };
                }$write($cells);
            }rewind($f);
            $body = stream_get_contents($f);
        } finally {
            fclose($f);
        }

        return response($body, 200, $headers + ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function money(int|string $value): int
    {
        if (! is_int($value)) {
            throw new \LogicException('Importo del report non tipizzato.');
        }

        return $value;
    }
}
