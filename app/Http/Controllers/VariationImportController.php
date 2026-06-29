<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessVariationImportJob;
use App\Jobs\SyncVariationsToWooCommerceJob;
use App\Models\InventoryImport;
use App\Models\ProductVariation;
use App\Services\ImportHeaderValidator;
use App\Services\Inventory\InventoryImportMode;
use App\Services\Inventory\QueueWorkerStarter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class VariationImportController extends Controller
{
    public function show(): View
    {
        $activeImport = InventoryImport::query()
            ->where('import_mode', InventoryImportMode::VARIATIONS)
            ->whereIn('status', [InventoryImport::STATUS_PENDING, InventoryImport::STATUS_PROCESSING])
            ->latest()
            ->first();

        return view('variations.import', [
            'imports'        => InventoryImport::query()
                ->where('import_mode', InventoryImportMode::VARIATIONS)
                ->latest()
                ->limit(10)
                ->get(),
            'activeImportId' => $activeImport?->id,
        ]);
    }

    public function store(Request $request, ImportHeaderValidator $headerValidator): JsonResponse
    {
        $request->validate([
            'excel_file' => ['required', 'file', 'mimes:xlsx,xls', 'max:51200'],
        ]);

        $file = $request->file('excel_file');

        $headerError = $headerValidator->validate($file, 'variations');
        if ($headerError) {
            return response()->json(['message' => $headerError], 422);
        }

        $filename = 'variation-import-' . now()->format('Ymd-His') . '-' . $file->getClientOriginalName();
        $stored   = 'variation-imports/' . $filename;

        Storage::disk('local')->put($stored, $file->getContent());

        $import = InventoryImport::create([
            'original_filename' => $file->getClientOriginalName(),
            'stored_path'       => $stored,
            'disk'              => 'local',
            'import_mode'       => InventoryImportMode::VARIATIONS,
            'status'            => InventoryImport::STATUS_PENDING,
        ]);

        ProcessVariationImportJob::dispatch($import->id);

        app(QueueWorkerStarter::class)->ensureRunning();

        return response()->json([
            'message' => 'Archivo recibido. El procesamiento continúa en segundo plano.',
            'import'  => $this->importPayload($import),
        ], 202);
    }

    public function status(InventoryImport $import): JsonResponse
    {
        abort_unless($import->import_mode === InventoryImportMode::VARIATIONS, 404);

        return response()->json([
            'import' => $this->importPayload($import->fresh()),
        ]);
    }

    public function sync(Request $request): JsonResponse
    {
        $pendientes = ProductVariation::where('wc_status', 'pending')->count();

        if ($pendientes === 0) {
            return response()->json(['message' => 'No hay variaciones pendientes de sincronizar.'], 422);
        }

        // Crear un InventoryImport de tipo variations para usar como log en pantalla
        $import = InventoryImport::create([
            'original_filename' => 'sync-woocommerce-' . now()->format('Ymd-His'),
            'stored_path'       => '',
            'disk'              => 'local',
            'import_mode'       => InventoryImportMode::VARIATIONS,
            'status'            => InventoryImport::STATUS_PROCESSING,
            'started_at'        => now(),
            'total_rows'        => $pendientes,
        ]);

        SyncVariationsToWooCommerceJob::dispatch($import->id, $request->integer('import_id') ?: null);

        app(QueueWorkerStarter::class)->ensureRunning();

        return response()->json([
            'message' => "{$pendientes} variaciones encoladas para sync con WooCommerce.",
            'import'  => $this->importPayload($import),
        ], 202);
    }

    public function downloadLog(InventoryImport $import): \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\Response
    {
        abort_unless($import->import_mode === InventoryImportMode::VARIATIONS, 404);

        $logPath = storage_path("logs/imports/import_{$import->id}.log");

        abort_unless(file_exists($logPath), 404, 'Log no disponible para esta importación.');

        return response()->download(
            $logPath,
            "log-variaciones-import-{$import->id}.log",
            ['Content-Type' => 'text/plain; charset=UTF-8']
        );
    }

    public function downloadReport(InventoryImport $import): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless($import->import_mode === InventoryImportMode::VARIATIONS, 404);

        $reportPath = ($import->checkpoint ?? [])['error_report'] ?? null;
        abort_unless($reportPath, 404);

        return Storage::disk($import->disk)->download(
            $reportPath,
            'errores-variaciones-' . $import->id . '.csv'
        );
    }

    private function importPayload(InventoryImport $import): array
    {
        $stats      = $import->stats ?? $import->partial_stats ?? [];
        $checkpoint = $import->checkpoint ?? [];

        return [
            'id'                  => $import->id,
            'original_filename'   => $import->original_filename,
            'status'              => $import->status,
            'status_label'        => $import->statusLabel(),
            'total_rows'          => $import->total_rows,
            'processed_rows'      => $import->processed_rows,
            'progress_percent'    => $import->progressPercent(),
            'successful_products' => $stats['successful'] ?? 0,
            'failed_products'     => $stats['failed'] ?? 0,
            'skipped_products'    => $stats['skipped'] ?? 0,
            'log_entries'         => $import->log_entries ?? [],
            'error_message'       => $import->error_message,
            'error_report_url'    => isset($checkpoint['error_report'])
                ? route('variations.import.report', $import)
                : null,
            'is_finished'         => $import->isFinished(),
            'started_at'          => $import->started_at?->toIso8601String(),
            'completed_at'        => $import->completed_at?->toIso8601String(),
            'log_download_url'    => file_exists(storage_path("logs/imports/import_{$import->id}.log"))
                ? route('variations.import.log', $import)
                : null,
        ];
    }
}
