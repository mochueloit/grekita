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

    public function isStillProcessing(array $response): bool
    {
        return ($response['should_continue'] ?? false) === true;
    }

    public function isImportFinished(array $response): bool
    {
        if ($this->isStillProcessing($response)) {
            return false;
        }

        if ($this->shouldRetryAfterFailure($response)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array{
     *     should_continue?: bool,
     *     http_status?: int,
     *     api_status?: int|null,
     *     message?: string
     * }  $response
     */
    public function shouldRetryAfterFailure(array $response): bool
    {
        if (($response['should_continue'] ?? false) === true) {
            return false;
        }

        $httpStatus = (int) ($response['http_status'] ?? 0);
        $message = strtolower((string) ($response['message'] ?? ''));

        if (in_array($httpStatus, [408, 429, 502, 503, 504, 524], true)) {
            return true;
        }

        if (str_contains($message, 'error code: 524')
            || str_contains($message, 'error code 524')
            || str_contains($message, 'gateway time-out')
            || str_contains($message, 'gateway timeout')) {
            return true;
        }

        if ($httpStatus === 0) {
            return str_contains($message, 'timed out')
                || str_contains($message, 'curl error 28')
                || str_contains($message, 'connection refused')
                || str_contains($message, 'could not resolve')
                || str_contains($message, 'failed to connect')
                || str_contains($message, 'operation timed out');
        }

        return false;
    }

    /**
     * @return array{
     *     url: string,
     *     action: string,
     *     http_status: int,
     *     api_status: int|null,
     *     message: string,
     *     body: string,
     *     should_continue: bool,
     *     should_retry: bool
     * }
     */
    public function call(string $action, ?InventoryImport $import = null, ?int $timeout = null): array
    {
        $url = $this->buildUrl($action, $import);
        $timeout ??= (int) config('wp_all_import.http_timeout_seconds', 120);

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->get($url);
        } catch (\Throwable $exception) {
            $result = [
                'url' => $url,
                'action' => $action,
                'http_status' => 0,
                'api_status' => null,
                'message' => $exception->getMessage(),
                'body' => '',
                'should_continue' => false,
            ];
            $result['should_retry'] = $this->shouldRetryAfterFailure($result);

            return $result;
        }

        $body = (string) $response->body();
        $decoded = json_decode($body, true);
        $apiStatus = is_array($decoded) && isset($decoded['status']) ? (int) $decoded['status'] : null;
        $message = is_array($decoded) && isset($decoded['message'])
            ? (string) $decoded['message']
            : mb_substr(trim($body), 0, 500);

        $result = [
            'url' => $url,
            'action' => $action,
            'http_status' => $response->status(),
            'api_status' => $apiStatus,
            'message' => $message,
            'body' => mb_substr($body, 0, 2000),
            'should_continue' => $apiStatus === 200,
        ];
        $result['should_retry'] = $this->shouldRetryAfterFailure($result);

        return $result;
    }
}
