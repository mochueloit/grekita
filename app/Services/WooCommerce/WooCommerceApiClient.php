<?php

namespace App\Services\WooCommerce;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;

class WooCommerceApiClient
{
    private string $apiUrl;
    private string $consumerKey;
    private string $consumerSecret;
    private int $timeout;
    private int $maxRetries;
    private int $retryDelay;

    public function __construct()
    {
        $this->apiUrl        = rtrim((string) config('woocommerce.api_url', ''), '/');
        $this->consumerKey   = (string) config('woocommerce.consumer_key', '');
        $this->consumerSecret = (string) config('woocommerce.consumer_secret', '');
        $this->timeout       = (int) config('woocommerce.http_timeout_seconds', 30);
        $this->maxRetries    = (int) config('woocommerce.max_retries', 3);
        $this->retryDelay    = (int) config('woocommerce.retry_delay_seconds', 2);
    }

    public function isConfigured(): bool
    {
        return $this->consumerKey !== '' && $this->consumerSecret !== '';
    }

    // ------------------------------------------------------------------
    // Métodos públicos de la API
    // ------------------------------------------------------------------

    public function get(string $endpoint, array $params = []): array
    {
        return $this->request('GET', $endpoint, $params);
    }

    public function post(string $endpoint, array $data = []): array
    {
        return $this->request('POST', $endpoint, $data);
    }

    public function put(string $endpoint, array $data = []): array
    {
        return $this->request('PUT', $endpoint, $data);
    }

    // ------------------------------------------------------------------
    // Helpers de producto
    // ------------------------------------------------------------------

    /** Busca un producto por SKU. Devuelve el array del producto o null si no existe. */
    public function findProductBySku(string $sku): ?array
    {
        $result = $this->get('products', ['sku' => $sku]);

        if (!empty($result['data']) && is_array($result['data'])) {
            return $result['data'][0] ?? null;
        }

        return null;
    }

    /** Obtiene variaciones existentes de un producto variable. */
    public function getVariations(int $productId): array
    {
        $result = $this->get("products/{$productId}/variations", ['per_page' => 100]);

        return $result['data'] ?? [];
    }

    /** Pasa un producto a borrador antes de convertirlo. */
    public function setDraft(int $productId): array
    {
        return $this->put("products/{$productId}", ['status' => 'draft']);
    }

    /** Publica un producto después de conversión exitosa. */
    public function setPublish(int $productId): array
    {
        return $this->put("products/{$productId}", ['status' => 'publish']);
    }

    /** Convierte Simple → Variable y registra atributos en el padre. */
    public function convertToVariable(int $productId, array $attributes): array
    {
        return $this->put("products/{$productId}", [
            'type'       => 'variable',
            'attributes' => $attributes,
        ]);
    }

    /** Crea una variación nueva en un producto variable. */
    public function createVariation(int $productId, array $data): array
    {
        return $this->post("products/{$productId}/variations", $data);
    }

    /** Actualiza una variación existente. */
    public function updateVariation(int $productId, int $variationId, array $data): array
    {
        return $this->put("products/{$productId}/variations/{$variationId}", $data);
    }

    // ------------------------------------------------------------------
    // Motor HTTP con reintentos
    // ------------------------------------------------------------------

    private function request(string $method, string $endpoint, array $payload = []): array
    {
        $url      = $this->apiUrl . '/' . ltrim($endpoint, '/');
        $attempts = 0;

        while ($attempts <= $this->maxRetries) {
            try {
                $http = Http::timeout($this->timeout)
                    ->withBasicAuth($this->consumerKey, $this->consumerSecret)
                    ->acceptJson();

                $response = match (strtoupper($method)) {
                    'GET'  => $http->get($url, $payload),
                    'POST' => $http->post($url, $payload),
                    'PUT'  => $http->put($url, $payload),
                    default => throw new \InvalidArgumentException("Método HTTP no soportado: {$method}"),
                };

                // Rate limiting o error de servidor → reintento
                if (in_array($response->status(), [429, 500, 502, 503, 504], true)) {
                    $attempts++;
                    if ($attempts <= $this->maxRetries) {
                        sleep($this->retryDelay * $attempts);
                        continue;
                    }
                }

                return $this->parseResponse($response);

            } catch (\Throwable $e) {
                $attempts++;
                if ($attempts > $this->maxRetries) {
                    return [
                        'success' => false,
                        'status'  => 0,
                        'data'    => [],
                        'error'   => $e->getMessage(),
                    ];
                }
                sleep($this->retryDelay);
            }
        }

        return [
            'success' => false,
            'status'  => 0,
            'data'    => [],
            'error'   => 'Máximo de reintentos alcanzado.',
        ];
    }

    private function parseResponse(Response $response): array
    {
        $body    = (string) $response->body();
        $decoded = json_decode($body, true);

        return [
            'success' => $response->successful(),
            'status'  => $response->status(),
            'data'    => is_array($decoded) ? $decoded : [],
            'error'   => $response->successful() ? null : ($decoded['message'] ?? $body),
        ];
    }
}
