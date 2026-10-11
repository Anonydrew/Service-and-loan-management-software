<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Reporting\Reports;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends Controller
{
    public function __construct(private readonly Reports $reports) {}

    public function show(Request $request, string $report): Response
    {
        if ($report === 'catalogue') {
            return response()->json(['data' => collect(Reports::CATALOGUE)
                ->filter(fn ($r) => ! $r[2] || $request->user()->hasPermission('reports.financial'))
                ->map(fn ($r, $slug) => ['slug' => $slug, 'title' => $r[0], 'description' => $r[1], 'financial' => $r[2]])
                ->values()]);
        }

        abort_unless(array_key_exists($report, Reports::CATALOGUE), 404);

        if (Reports::CATALOGUE[$report][2] && ! $request->user()->hasPermission('reports.financial')) {
            return response()->json(['message' => 'This report requires the financial reports permission.', 'error_code' => 'forbidden'], 403);
        }

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'integer'],
            'format' => ['nullable', 'in:json,csv'],
        ]);

        $result = $this->reports->run(
            $report,
            $data['from'] ?? now()->startOfMonth()->toDateString(),
            $data['to'] ?? now()->toDateString(),
            $data['branch_id'] ?? null,
        );

        if (($data['format'] ?? 'json') !== 'csv') {
            return response()->json($result);
        }

        AuditLogger::log('report.exported', null, "Exported {$result['title']} report", ['report' => $report]);

        return response()->streamDownload(function () use ($result): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_column($result['columns'], 'label'));
            foreach ($result['rows'] as $row) {
                fputcsv($out, array_map(function ($col) use ($row) {
                    $value = $row[$col['key']] ?? '';

                    return $col['type'] === 'money' ? number_format(((int) $value) / 100, 2, '.', '') : $value;
                }, $result['columns']));
            }
            fclose($out);
        }, "{$report}-{$result['from']}-{$result['to']}.csv", ['Content-Type' => 'text/csv']);
    }
}
