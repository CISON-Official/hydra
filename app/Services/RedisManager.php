<?php

namespace App\Services;

use DateTimeInterface;
use Illuminate\Support\Facades\Redis;

class RedisManager
{
    protected int $defaultTtl;

    /**
     * Pass configuration defaults via constructor.
     */
    public function __construct(int $defaultTtl = 300)
    {
        $this->defaultTtl = $defaultTtl;
    }

    /**
     * Cache certificate validity status.
     */
    public function cacheCertificateStatus(
        string $certificateId, 
        bool $isValid, 
        DateTimeInterface $expiresAt, 
        ?array $data = null, 
        ?int $ttl = null
    ): void {
        $ttl = $ttl ?? $this->defaultTtl;
        
        // Ensure data exists, appending validation context if needed
        $payload = $data ?? [
            'is_valid' => $isValid,
            'expires_at' => $expiresAt->format(DateTimeInterface::ATOM)
        ];

        // setex sets value with TTL in seconds
        Redis::setex("cert:status:{$certificateId}", $ttl, json_encode($payload));
    }

    /**
     * Get cached certificate status.
     */
    public function getCertificateStatus(string $certificateId): ?array
    {
        $data = Redis::get("cert:status:{$certificateId}");
        
        if ($data) {
            return json_decode($data, true);
        }

        return null;
    }

    /**
     * Invalidate all cache entries for a certificate.
     */
    public function invalidateCertificate(string $certificateId): void
    {
        Redis::del("cert:status:{$certificateId}");
    }

    /**
     * Precise Sliding Window Rate Limiter.
     * Uses Redis Sorted Sets (ZSET) to block API bursts.
     */
    public function checkRateLimit(string $key, int $maxRequests, int $windowSeconds): bool
    {
        $now = microtime(true); // High resolution timestamp float
        $windowKey = "rate_limit:{$key}";
        
        // 1. Remove old logged request scores outside the current time window sliding range
        Redis::zremrangebyscore($windowKey, 0, $now - $windowSeconds);

        // 2. Count current active records in the set
        $currentCount = Redis::zcard($windowKey);

        if ($currentCount >= $maxRequests) {
            return false;
        }

        // 3. Log the current request timestamp (using a unique member string value)
        // syntax: zadd(key, score, member)
        Redis::zadd($windowKey, $now, (string)$now);
        
        // 4. Ensure the key auto-destructs after the window expires
        Redis::expire($windowKey, $windowSeconds);

        return true;
    }
}