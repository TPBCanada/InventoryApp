<?php
declare(strict_types=1);

/**
 * Lightweight Shopify Admin REST helpers for inventory tools.
 * Uses Guzzle (via composer) and environment/constant configuration.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../utils/config_init.php';
require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Resolve Shopify config values from constants or environment.
 */
function shopify_config(): array
{
    $domain = defined('SHOPIFY_STORE_DOMAIN') ? SHOPIFY_STORE_DOMAIN : getenv('SHOPIFY_STORE_DOMAIN');
    $api_version = defined('SHOPIFY_API_VERSION') ? SHOPIFY_API_VERSION : (getenv('SHOPIFY_API_VERSION') ?: '2024-04');
    $token = defined('SHOPIFY_ADMIN_ACCESS_TOKEN') ? SHOPIFY_ADMIN_ACCESS_TOKEN : getenv('SHOPIFY_ADMIN_ACCESS_TOKEN');

    if (empty($token) && getenv('SHOPIFY_ACCESS_TOKEN')) {
        $token = getenv('SHOPIFY_ACCESS_TOKEN');
    }

    if (!$domain) {
        throw new RuntimeException('SHOPIFY_STORE_DOMAIN is not configured');
    }
    if (!$token) {
        throw new RuntimeException('SHOPIFY_ADMIN_ACCESS_TOKEN is not configured');
    }

    return [
        'domain' => rtrim((string) $domain, '/'),
        'api_version' => (string) $api_version,
        'token' => (string) $token,
    ];
}

function shopify_store_domain(): string
{
    $cfg = shopify_config();
    return $cfg['domain'];
}

function shopify_base_url(): string
{
    $cfg = shopify_config();
    return "https://{$cfg['domain']}/admin/api/{$cfg['api_version']}";
}

function shopify_default_headers(): array
{
    $cfg = shopify_config();

    return [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-Shopify-Access-Token' => $cfg['token'],
    ];
}

/**
 * Minimal GraphQL helper for Shopify Admin API.
 */
function shopify_graphql(string $query, array $variables = []): array
{
    $url = shopify_base_url() . '/graphql.json';
    $resp = http_post($url, [
        'headers' => shopify_default_headers(),
        'json' => [
            'query' => $query,
            'variables' => $variables,
        ],
    ]);

    if (!$resp->successful()) {
        $error_body = $resp->body() ?? 'No response body.';
        error_log("Shopify GraphQL failed with status " . $resp->getStatusCode() . " Body: " . $error_body);
        throw new RuntimeException("Shopify GraphQL failed with status " . $resp->getStatusCode());
    }

    return $resp->json() ?? [];
}

class FetchResponse
{
    private int $status;
    private string $body;
    private $decoded;

    public function __construct(int $status, string $body)
    {
        $this->status = $status;
        $this->body = $body;
        $this->decoded = null;
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function json(): ?array
    {
        if ($this->decoded !== null) {
            return $this->decoded;
        }
        $decoded = json_decode($this->body, true);
        $this->decoded = is_array($decoded) ? $decoded : null;
        return $this->decoded;
    }
}

/**
 * Simple GET wrapper that accepts an options array which may contain:
 *  - 'headers' => [...] and/or any other keys treated as query params.
 */
function http_get(string $url, array $options = []): FetchResponse
{
    $client = new \GuzzleHttp\Client();

    $headers = $options['headers'] ?? [];
    $query = $options;
    unset($query['headers']);

    try {
        $guzzleOptions = [];
        if (!empty($headers)) {
            $guzzleOptions['headers'] = $headers;
        }
        if (!empty($query)) {
            $guzzleOptions['query'] = $query;
        }
        $resp = $client->request('GET', $url, $guzzleOptions);
        $body = (string) $resp->getBody();
        return new FetchResponse($resp->getStatusCode(), $body);
    } catch (\GuzzleHttp\Exception\RequestException $e) {
        $resp = $e->getResponse();
        if ($resp !== null) {
            $body = (string) $resp->getBody();
            return new FetchResponse($resp->getStatusCode(), $body);
        }
        throw $e;
    }
}

/**
 * Simple POST wrapper that accepts options with 'headers' and 'json'.
 */
function http_post(string $url, array $options = []): FetchResponse
{
    $client = new \GuzzleHttp\Client();

    $headers = $options['headers'] ?? [];
    $json = $options['json'] ?? null;

    try {
        $guzzleOptions = [];
        if (!empty($headers)) {
            $guzzleOptions['headers'] = $headers;
        }
        if ($json !== null) {
            $guzzleOptions['json'] = $json;
        }

        $resp = $client->request('POST', $url, $guzzleOptions);
        $body = (string) $resp->getBody();
        return new FetchResponse($resp->getStatusCode(), $body);
    } catch (\GuzzleHttp\Exception\RequestException $e) {
        $resp = $e->getResponse();
        if ($resp !== null) {
            $body = (string) $resp->getBody();
            return new FetchResponse($resp->getStatusCode(), $body);
        }
        throw $e;
    }
}

/**
 * Generic GET helper using http_get().
 */
function shopify_get(string $path, array $query = []): array
{
    $path = '/' . ltrim($path, '/');
    $url = shopify_base_url() . $path;

    $response = http_get($url, array_merge($query, [
        'headers' => shopify_default_headers(),
    ]));

    if (!$response->successful()) {
        $error_body = $response->body() ?? 'No response body.';
        error_log("Shopify GET {$path} failed with status " . $response->getStatusCode() . " Body: " . $error_body);

        throw new RuntimeException(
            "Shopify GET {$path} failed with status " . $response->getStatusCode() .
            ". Response: " . (is_string($error_body) ? $error_body : 'See error log.')
        );
    }

    return $response->json() ?? [];
}

/**
 * Generic POST helper using http_post().
 */
function shopify_post(string $path, array $body): array
{
    $path = '/' . ltrim($path, '/');
    $url = shopify_base_url() . $path;

    $response = http_post($url, [
        'headers' => shopify_default_headers(),
        'json' => $body,
    ]);

    if (!$response->successful()) {
        $error_body = $response->body() ?? 'No response body.';
        error_log("Shopify POST {$path} failed with status " . $response->getStatusCode() . " Body: " . $error_body);

        throw new RuntimeException(
            "Shopify POST {$path} failed with status " . $response->getStatusCode() .
            ". Response: " . (is_string($error_body) ? $error_body : 'See error log.')
        );
    }

    return $response->json() ?? [];
}

/**
 * Trim text to a maximum number of words.
 */
function shopify_trim_words(string $text, int $max_words): string
{
    $text = trim($text);
    if ($max_words <= 0) {
        return $text;
    }
    $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!$words) {
        return '';
    }
    if (count($words) <= $max_words) {
        return implode(' ', $words);
    }
    return implode(' ', array_slice($words, 0, $max_words)) . '...';
}

/**
 * Fetch a Shopify product by SKU or UPC via REST, with GraphQL fallback, returning key fields.
 */
function shopify_fetch_product_by_sku(string $sku, int $max_description_words = 0): ?array
{
    $sku = trim($sku);
    if ($sku === '') {
        return null;
    }

    $debug = [
        'rest_variants' => null,
        'rest_variants_barcode' => null,
        'graphql_variant' => null,
        'product_resp' => null,
    ];

    // Request a batch and then choose the exact SKU match (Shopify can return fuzzy/partial matches)
    $variant_resp = shopify_get('variants.json', [
        'sku' => $sku,
        'limit' => 250,
        'fields' => 'id,product_id,sku,barcode,image_id'
    ]);
    $variants = $variant_resp['variants'] ?? [];
    $debug['rest_variants'] = $variants;
    $variant = null;
    foreach ($variants as $v) {
        if (isset($v['sku']) && (string)$v['sku'] === $sku) {
            $variant = $v;
            break;
        }
    }

    // UPC/Barcode pass: only if no exact SKU match
    if (!$variant) {
        $barcode_resp = shopify_get('variants.json', [
            'barcode' => $sku,
            'limit' => 250,
            'fields' => 'id,product_id,sku,barcode,image_id'
        ]);
        $barcode_variants = $barcode_resp['variants'] ?? [];
        $debug['rest_variants_barcode'] = $barcode_variants;
        foreach ($barcode_variants as $v) {
            if (isset($v['barcode']) && (string)$v['barcode'] === $sku) {
                $variant = $v;
                break;
            }
        }
    }

    // GraphQL fallback if REST did not return a match
    if (!$variant) {
        try {
            $gql = <<<'GQL'
                query ($q: String!) {
                  productVariants(first: 1, query: $q) {
                    edges {
                      node {
                        id
                        sku
                        barcode
                        product {
                          id
                          title
                          handle
                          bodyHtml
                          featuredImage { url altText }
                          images(first: 10) { edges { node { url altText } } }
                        }
                      }
                    }
                  }
                }
            GQL;
            $data = shopify_graphql($gql, ['q' => 'sku:' . $sku . ' OR barcode:' . $sku]);
            $debug['graphql_variant'] = $data;
            $edge = $data['data']['productVariants']['edges'][0]['node'] ?? null;
            if ($edge) {
                $productNode = $edge['product'] ?? [];
                $imgUrl = $productNode['featuredImage']['url'] ?? null;
                if (!$imgUrl && !empty($productNode['images']['edges'][0]['node']['url'])) {
                    $imgUrl = $productNode['images']['edges'][0]['node']['url'];
                }
                $plain_description = trim(strip_tags((string) ($productNode['bodyHtml'] ?? '')));
                return [
                    'product_id' => $productNode['id'] ?? '',
                    'variant_id' => $edge['id'] ?? '',
                    'sku' => (string) ($edge['sku'] ?? $sku),
                    'title' => (string) ($productNode['title'] ?? ''),
                    'body_html' => (string) ($productNode['bodyHtml'] ?? ''),
                    'description' => $plain_description ? shopify_trim_words($plain_description, $max_description_words) : '',
                    'image_src' => $imgUrl,
                    'handle' => (string) ($productNode['handle'] ?? ''),
                    '_debug' => $debug,
                ];
            }
        } catch (\Throwable $e) {
            error_log('[shopify_fetch_product_by_sku] GraphQL fallback failed: ' . $e->getMessage());
        }
    }

    if (!$variant || empty($variant['product_id'])) {
        return null;
    }

    $product_resp = shopify_get("products/{$variant['product_id']}.json");
    $debug['product_resp'] = $product_resp;
    $product = $product_resp['product'] ?? null;
    if (!$product) {
        return null;
    }

    $image_src = null;
    if (!empty($product['image']['src'])) {
        $image_src = $product['image']['src'];
    } elseif (!empty($product['images'][0]['src'])) {
        $image_src = $product['images'][0]['src'];
    }

    $body_html = (string) ($product['body_html'] ?? '');
    $plain_description = trim(strip_tags($body_html));

    $result = [
        'product_id' => (int) $product['id'],
        'variant_id' => (int) ($variant['id'] ?? 0),
        'sku' => (string) ($variant['sku'] ?? $sku),
        'barcode' => isset($variant['barcode']) ? (string) $variant['barcode'] : '',
        'title' => (string) ($product['title'] ?? ''),
        'body_html' => $body_html,
        'description' => $plain_description ? shopify_trim_words($plain_description, $max_description_words) : '',
        'image_src' => $image_src,
        'handle' => (string) ($product['handle'] ?? ''),
        '_debug' => $debug,
    ];

    return $result;
}
