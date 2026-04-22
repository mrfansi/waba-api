<?php

namespace App\Waba\Outbound;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

class IdempotencyStore
{
    private Repository $cache;

    public function __construct()
    {
        $store = config('waba.outbound.idempotency.cache_store');
        $this->cache = $store ? Cache::store($store) : Cache::store();
    }

    /** @return array{message_id:string, request_hash:string}|null */
    public function find(string $apiKeyId, string $key): ?array
    {
        $value = $this->cache->get($this->key($apiKeyId, $key));

        return is_array($value) ? $value : null;
    }

    public function remember(string $apiKeyId, string $key, string $messageId, string $requestHash): void
    {
        $ttl = (int) config('waba.outbound.idempotency.ttl_hours', 24) * 3600;
        $this->cache->put(
            $this->key($apiKeyId, $key),
            ['message_id' => $messageId, 'request_hash' => $requestHash],
            $ttl,
        );
    }

    private function key(string $apiKeyId, string $key): string
    {
        return "waba:idem:{$apiKeyId}:{$key}";
    }
}
