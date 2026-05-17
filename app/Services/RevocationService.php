<?php

namespace App\Services;

use App\Models\Certificate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Carbon\Carbon;

class RevocationService
{
    protected int $cacheTtl;

    public function __construct()
    {
        // Fetch TTL from configurations; defaults to 1 hour if unassigned
        $this->cacheTtl = config('cache.certificate_ttl', 3600);
    }

    /**
     * Revoke a unique certificate instance.
     * 
     * @return bool True if revoked successfully, false if already revoked/expired
     */
    public function revokeCertificate(string $certificateId, string $reason, string $revokedBy): bool
    {
        $cert = Certificate::findOrFail($certificateId);

        // Check if already revoked
        if ($cert->revoked_at !== null) {
            return false;
        }

        // Check if already expired
        if ($cert->expires_at->isPast()) {
            return false;
        }

        return DB::transaction(function () use ($cert, $reason) {
            $cert->update([
                'revoked_at' => Carbon::now('UTC'),
                'revocation_reason' => $reason
            ]);

            // Invalidate application cache
            $this->invalidateCache($cert->id);

            // Purge any active server session keys tied to this certificate
            $this->purgeActiveSessions($cert->id);

            return true;
        });
    }

    /**
     * Check if a certificate is valid, utilizing highly responsive cache stores.
     */
    public function checkCertificateStatus(string $certificateId): array
    {
        $cacheKey = "certificate:status:{$certificateId}";

        // Attempt reading directly from Redis via the cache driver abstraction Layer
        $cached = Cache::get($cacheKey);

        if ($cached) {
            return [
                "is_valid" => $cached['is_valid'],
                "expires_at" => $cached['expires_at'],
                "revoked_at" => $cached['revoked_at'] ?? null,
                "source" => "cache"
            ];
        }

        $cert = Certificate::find($certificateId);

        if (!$cert) {
            return [
                "is_valid" => false,
                "error" => "Certificate not found"
            ];
        }

        $now = Carbon::now('UTC');
        $isValid = ($cert->revoked_at === null) && ($cert->expires_at->isAfter($now));

        $cacheData = [
            'is_valid' => $isValid,
            'expires_at' => $cert->expires_at->toIso8601String(),
            'revoked_at' => $cert->revoked_at?->toIso8601String()
        ];

        // Store evaluation state inside Redis
        Cache::put($cacheKey, $cacheData, $this->cacheTtl);

        return array_merge($cacheData, [
            "revocation_reason" => $cert->revocation_reason,
            "source" => "database"
        ]);
    }

    /**
     * Revoke all active credentials mapped to a singular Issuer entity.
     * 
     * @return int Total number of rows altered during atomic bulk operation
     */
    public function bulkRevokeByIssuer(string $issuerId, string $reason): int
    {
        $now = Carbon::now('UTC');

        return DB::transaction(function () use ($issuerId, $reason, $now) {
            // Find targets matching valid runtime parameters
            $targetQuery = Certificate::where('issuer_id', $issuerId)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', $now);

            // Pluck matching targets into memory arrays to execute specific target flushes
            $revokedIds = $targetQuery->pluck('id')->toArray();

            if (empty($revokedIds)) {
                return 0;
            }

            // Perform rapid multi-row batch database update execution
            Certificate::whereIn('id', $revokedIds)->update([
                'revoked_at' => $now,
                'revocation_reason' => "Bulk revocation by issuer: {$reason}"
            ]);

            // Clean cache metrics across altered parameters
            foreach ($revokedIds as $id) {
                $this->invalidateCache($id);
            }

            return count($revokedIds);
        });
    }

    /**
     * Reinstates a closed or mistakenly revoked certificate profile back to operational use.
     */
    public function reinstateCertificate(string $certificateId): bool
    {
        return DB::transaction(function () use ($certificateId) {
            $affectedRows = Certificate::where('id', $certificateId)
                ->whereNotNull('revoked_at')
                ->update([
                    'revoked_at' => null,
                    'revocation_reason' => null
                ]);

            if ($affectedRows > 0) {
                $this->invalidateCache($certificateId);
                return true;
            }

            return false;
        });
    }

    /**
     * Centralized utility to handle multi-key structural cache resets.
     */
    protected function invalidateCache(string $certificateId): void
    {
        Cache::forget("certificate:status:{$certificateId}");
        Cache::forget("certificate:profile:{$certificateId}"); // Clear additional service tags if present
    }

    /**
     * Directly scan redis memory blocks to safely eliminate session traces.
     */
    protected function purgeActiveSessions(string $certificateId): void
    {
        $redisConnection = Redis::connection();
        $pattern = "session:*:[{$certificateId}]";

        // Utilize native non-blocking Redis SCAN cursor iteration profiles over keys loops
        $cursor = "0";
        do {
            [$cursor, $keys] = $redisConnection->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 100]);
            
            if (!empty($keys)) {
                // Strip the application prefix if configured inside database setups before calling delete
                $cleanKeys = array_map(function ($key) {
                    return preg_replace('/^' . config('database.redis.options.prefix') . '/', '', $key);
                }, $keys);

                $redisConnection->del($cleanKeys);
            }
        } while ($cursor !== "0");
    }
}