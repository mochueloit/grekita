<?php

namespace App\Services\WordPress;

use App\Models\InventoryImport;
use Illuminate\Support\Facades\Http;

class WpAllImportClient
{
    public function isEnabled(?InventoryImport $import = null): bool
    {
        return (bool) config('wp_all_import.enabled', false)
            && trim((string) config('wp_all_import.import_key', '')) !== ''
            && $this->resolveImportId($import) !== '';
    }

    public function resolveImportId(?InventoryImport $import = null): string
    {
        if ($import !== null) {
            $pipeline = ($import->checkpoint ?? [])['wp_pipeline'] ?? [];
            $stored = trim((string) ($pipeline['wp_import_id'] ?? ''));

            if ($stored !== '') {
                return $stored;
            }
        }

        if ($import?->isStockPriceMode()) {
            $stockPriceId = trim((string) config('wp_all_import.stock_price_import_id', ''));

            if ($stockPriceId !== '') {
                return $stockPriceId;
            }
        }

        return trim((string) config('wp_all_import.import_id', ''));
    }

    public function buildUrl(string $action, ?InventoryImport $import = null): string
    {
        $base = rtrim((string) config('wp_all_import.base_url', ''), '/');
        $query = http_build_query([
            'import_key' => (string) config('wp_all_import.import_key'),
            'import_id' => $this->resolveImportId($import),
            'action' => $action,
            'rand' => (string) random_int(1, 999999999),
        ]);

        return $base.'?'.$query;
    }

    /**
     * @return array{
     *     url: string,
     *     action: string,
     *     http_status: int,
     *     api_status: int|null,
     *     message: string,
     *     body: string,
     *     should_continue: bool
     * }
     */
    public function call(string $action, ?InventoryImport $import = null): array
    {
        $url = $this->buildUrl($action, $import);
        $timeout = (int) config('wp_all_import.http_timeout_seconds', 120);

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->get($url);
        } catch (\Throwable $exception) {
            return [
                'url' => $url,
                'action' => $action,
                'http_status' => 0,
                'api_status' => null,
                'message' => $exception->getMessage(),
                'body' => '',
                'should_continue' => false,
            ];
        }

        $body = (string) $response->body();
        $decoded = json_decode($body, true);
        $apiStatus = is_array($decoded) && isset($decoded['status']) ? (int) $decoded['status'] : null;
        $message = is_array($decoded) && isset($decoded['message'])
            ? (string) $decoded['message']
            : mb_substr(trim($body), 0, 500);

        return [
            'url' => $url,
            'action' => $action,
            'http_status' => $response->status(),
            'api_status' => $apiStatus,
            'message' => $message,
            'body' => mb_substr($body, 0, 2000),
            'should_continue' => $apiStatus === 200,
        ];
    }
}
