<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ExportProductsXmlJob;
use App\Services\Export\ProductXmlExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductXmlExportController extends Controller
{
    public function store(Request $request, ProductXmlExportService $exportService): JsonResponse
    {
        $async = $request->boolean('async', false);

        if ($async) {
            $userId = $request->user()?->id ?? 'guest';
            ExportProductsXmlJob::dispatch("api:user:{$userId}")->afterCommit();

            return response()->json([
                'status' => 'queued',
                'message' => 'Exportación XML encolada. Consulta GET /api/products/export-xml/latest cuando termine el worker.',
            ], 202);
        }

        $result = $exportService->generate('api:user:'.($request->user()?->id ?? 'guest'));

        return response()->json([
            'status' => 'completed',
            'message' => 'XML generado correctamente.',
            'export' => $result,
        ]);
    }

    public function latest(ProductXmlExportService $exportService): JsonResponse
    {
        $manifest = $exportService->latestManifest();

        if ($manifest === null) {
            return response()->json([
                'status' => 'missing',
                'message' => 'Aún no hay ningún XML generado. Usa POST /api/products/export-xml.',
            ], 404);
        }

        return response()->json([
            'status' => 'ok',
            'export' => $manifest,
        ]);
    }
}
