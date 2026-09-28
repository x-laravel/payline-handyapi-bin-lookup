<?php

namespace XLaravel\Payline\BinLookup\HandyApi;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use XLaravel\Payline\Contracts\BinLookupProvider;
use XLaravel\Payline\DTOs\CardProfile;
use XLaravel\Payline\Enums\CardCategory;
use XLaravel\Payline\Enums\CardScheme;
use XLaravel\Payline\Enums\CardType;

class HandyApiBinLookup implements BinLookupProvider
{
    private const string BASE_URL = 'https://data.handyapi.com';

    private const string FOUND = 'SUCCESS';

    private const int CACHE_TTL = 7776000;

    private const string CACHE_PREFIX = 'payline:bin-lookup:handyapi:';

    private const int TIMEOUT = 5;

    private readonly string $baseUrl;

    private readonly ?string $apiKey;

    private readonly int $cacheTtl;

    private readonly ?string $cacheStore;

    private readonly int $timeout;

    public function __construct(array $config = [])
    {
        $this->baseUrl = rtrim($config['base_url'] ?? self::BASE_URL, '/');
        $this->apiKey = $config['api_key'] ?? null;
        $this->cacheTtl = (int) ($config['cache_ttl'] ?? self::CACHE_TTL);
        $this->cacheStore = $config['cache_store'] ?? null;
        $this->timeout = (int) ($config['timeout'] ?? self::TIMEOUT);
    }

    public function lookup(string $bin): ?CardProfile
    {
        $bin = substr($bin, 0, 8);

        if ($this->cacheTtl <= 0) {
            return $this->map($bin, $this->fetch($bin));
        }

        $cache = Cache::store($this->cacheStore);
        $key = self::CACHE_PREFIX . $bin;
        $cached = $cache->get($key);

        if (is_array($cached)) {
            return $this->map($bin, $cached);
        }

        $body = $this->fetch($bin);
        $profile = $this->map($bin, $body);

        if ($profile !== null) {
            $cache->put($key, $body, $this->cacheTtl);
        }

        return $profile;
    }

    private function fetch(string $bin): ?array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders($this->apiKey !== null ? ['x-api-key' => $this->apiKey] : [])
                ->get($this->baseUrl . '/bin/' . $bin);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->json();

        return is_array($body) ? $body : null;
    }

    private function map(string $bin, ?array $body): ?CardProfile
    {
        if ($body === null || ($body['Status'] ?? null) !== self::FOUND) {
            return null;
        }

        $tier = $this->text($body['CardTier'] ?? null);

        return new CardProfile(
            bin: $bin,
            scheme: CardScheme::parse($body['Scheme'] ?? null),
            type: CardType::parse($body['Type'] ?? null),
            category: CardCategory::parse($tier),
            productType: $tier,
            issuer: $this->text($body['Issuer'] ?? null),
            issuerCountry: $this->text($body['Country']['A2'] ?? null),
            source: 'handyapi',
            raw: $body,
        );
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
