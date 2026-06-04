<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessInventoryImportJob;
use App\Models\InventoryImport;
use App\Services\Inventory\InventoryImageDownloadLogger;
use App\Services\Inventory\InventorySkippedRowExporter;
use App\Services\Inventory\InventorySkippedRowLogger;
use App\Services\Inventory\QueueWorkerStarter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryImportController extends Controller
{
    private const DISK = 'local';

    private const ALLOWED_EXTENSIONS = ['csv', 'txt', 'xlsx', 'xls'];

    public function show(Request $request): View
    {
        $activeImportId = $request->integer('import')
            ?: InventoryImport::query()
                ->whereIn('status', [InventoryImport::STATUS_PENDING, InventoryImport::STATUS_PROCESSING])
                ->latest()
                ->value('id');

        return view('inventory.import', [
            'imports' => InventoryImport::query()->latest()->limit(10)->get(),
            'activeImportId' => $activeImportId,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'csv_file' => [
                'required',
                'file',
                'max:51200',
                'mimes:csv,txt,xlsx,xls',
            ],
        ]);

        /** @var UploadedFile $uploaded */
        $uploaded = $validated['csv_file'];
        $extension = strtolower($uploaded->getClientOriginalExtension() ?: $uploaded->guessExtension() ?? '');

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return response()->json([
                'message' => 'Formato no soportado. Use CSV, XLS o XLSX.',
            ], 422);
        }

        $storedPath = 'imports/'.uniqid('import_', true).'.'.$extension;
        $disk = Storage::disk(self::DISK);

        if (! $disk->put($storedPath, $uploaded->getContent())) {
            return response()->json([
                'message' => 'No se pudo guardar el archivo subido.',
            ], 500);
        }

        $import = InventoryImport::query()->create([
            'original_filename' => $uploaded->getClientOriginalName(),
            'stored_path' => $storedPath,
            'disk' => self::DISK,
            'status' => InventoryImport::STATUS_PENDING,
        ]);

        ProcessInventoryImportJob::dispatch($import->id);

        app(QueueWorkerStarter::class)->ensureRunning();

        return response()->json([
            'message' => 'Archivo recibido. El procesamiento continúa en segundo plano.',
            'import' => $this->importPayload($import),
        ], 202);
    }

    public function status(InventoryImport $import): JsonResponse
    {
        return response()->json([
            'import' => $this->importPayload($import->fresh()),
        ]);
    }

    public function skipped(InventoryImport $import): JsonResponse
    {
        $rows = (new InventorySkippedRowLogger($import->id))->all();

        return response()->json([
            'import_id' => $import->id,
            'total' => count($rows),
            'rows' => $rows,
        ]);
    }

    public function downloadSkipped(InventoryImport $import, InventorySkippedRowExporter $exporter): StreamedResponse|BinaryFileResponse
    {
        $filename = sprintf('omitidas_import_%d_%s.csv', $import->id, $import->completed_at?->format('Y-m-d_His') ?? now()->format('Y-m-d_His'));

        if ($import->skipped_rows_csv_path && Storage::disk(self::DISK)->exists($import->skipped_rows_csv_path)) {
            return Storage::disk(self::DISK)->download($import->skipped_rows_csv_path, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        $rows = (new InventorySkippedRowLogger($import->id))->all();

        return $exporter->toCsvResponse($rows, $filename);
    }

    public function imageLog(InventoryImport $import): JsonResponse
    {
        $logger = new InventoryImageDownloadLogger($import->id);

        return response()->json([
            'import_id' => $import->id,
            'lines' => $logger->lines(120),
            'stats' => $logger->stats(),
            'download_url' => $logger->exists()
                ? route('inventory.import.images.log.download', $import)
                : null,
        ]);
    }

    public function downloadImageLog(InventoryImport $import): StreamedResponse|BinaryFileResponse
    {
        $logger = new InventoryImageDownloadLogger($import->id);

        abort_unless($logger->exists(), 404);

        $filename = sprintf('imagenes_import_%d_%s.log', $import->id, $import->completed_at?->format('Y-m-d_His') ?? now()->format('Y-m-d_His'));

        return Storage::disk(self::DISK)->download($logger->relativePath(), $filename, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function importPayload(InventoryImport $import): array
    {
        $liveStats = $import->liveStats();

        return [
            'id' => $import->id,
            'original_filename' => $import->original_filename,
            'status' => $import->status,
            'status_label' => $import->statusLabel(),
            'total_rows' => $import->total_rows,
            'processed_rows' => $import->processed_rows,
            'progress_percent' => $import->progressPercent(),
            'current_step' => $import->current_step,
            'import_phase' => $import->importPhase(),
            'import_phase_label' => $import->importPhaseLabel(),
            'checkpoint' => $import->checkpoint,
            'stats' => $import->stats,
            'partial_stats' => $liveStats,
            'log_entries' => $import->log_entries ?? [],
            'error_message' => $import->error_message,
            'started_at' => $import->started_at?->toIso8601String(),
            'completed_at' => $import->completed_at?->toIso8601String(),
            'last_activity_at' => $import->last_activity_at?->toIso8601String(),
            'created_at' => $import->created_at?->toIso8601String(),
            'is_finished' => $import->isFinished(),
            'is_stale' => $import->isStale(),
            'queued_jobs' => $import->queuedJobsCount(),
            'worker_hint' => $import->workerHint(),
            'skipped_count' => $import->skippedRowsCount(),
            'skipped_download_url' => $import->skippedRowsCount() > 0
                ? route('inventory.import.skipped.download', $import)
                : null,
            'skipped_csv_saved' => $import->skipped_rows_csv_path !== null
                && Storage::disk(self::DISK)->exists((string) $import->skipped_rows_csv_path),
            'skipped_view_url' => $import->skippedRowsCount() > 0
                ? route('inventory.import.skipped', $import)
                : null,
            'image_log_url' => route('inventory.import.images.log', $import),
            'image_log_download_url' => (new InventoryImageDownloadLogger($import->id))->exists()
                ? route('inventory.import.images.log.download', $import)
                : null,
            'image_downloads' => $this->imageDownloadStatsForImport($import->id),
        ];
    }

    /**
     * @return array{pending: int, downloading: int, completed: int, failed: int, total: int, finished: bool}
     */
    private function imageDownloadStatsForImport(int $importId): array
    {
        return (new InventoryImageDownloadLogger($importId))->stats();
    }
}
