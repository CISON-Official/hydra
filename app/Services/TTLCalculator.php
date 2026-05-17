<?php

namespace App\Services;

use App\Enums\TTLStrategy;
use DateTimeImmutable;
use DateTimeZone;

class TTLCalculator
{
    protected int $defaultMaxTtl;
    protected int $defaultMinTtl;
    protected int $safetyMargin;

    public function __construct(
        int $defaultMaxTtl = 3600,       // 1 hour
        int $defaultMinTtl = 60,         // 1 minute
        int $safetyMarginSeconds = 300   // 5 minutes
    ) {
        $this->defaultMaxTtl = $defaultMaxTtl;
        $this->defaultMinTtl = $defaultMinTtl;
        $this->safetyMargin = $safetyMarginSeconds;
    }

    /**
     * Calculate session TTL based on certificate expiry.
     */
    public function calculateSessionTtl(
        DateTimeImmutable $certificateExpiry,
        ?int $maxAllowedTtl = null,
        ?int $minAllowedTtl = null
    ): int {
        $maxTtl = $maxAllowedTtl ?? 900;  // 15 minutes default
        $minTtl = $minAllowedTtl ?? 60;   // 1 minute default

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $remaining = $certificateExpiry->getTimestamp() - $now->getTimestamp();

        // Add safety margin
        $remaining -= $this->safetyMargin;

        if ($remaining <= 0) {
            return 0;
        }

        $ttl = min($maxTtl, $remaining);

        return max($minTtl, $ttl);
    }

    /**
     * Calculate cache TTL for certificate status using Strategy Enums.
     */
    public function calculateCacheTtl(
        ?DateTimeImmutable $certificateExpiry = null,
        TTLStrategy $strategy = TTLStrategy::DYNAMIC,
        ?int $customTtl = null
    ): int {
        if ($strategy === TTLStrategy::FIXED && $customTtl !== null) {
            return $customTtl;
        }

        if ($strategy === TTLStrategy::MIN) {
            return $this->defaultMinTtl;
        }

        if ($strategy === TTLStrategy::MAX) {
            return $this->defaultMaxTtl;
        }

        if ($strategy === TTLStrategy::DYNAMIC && $certificateExpiry !== null) {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $remaining = $certificateExpiry->getTimestamp() - $now->getTimestamp();

            // Cache for 20% of remaining time, bounded within min/max limits
            $dynamicTtl = max(
                $this->defaultMinTtl,
                min($this->defaultMaxTtl, (int) ($remaining * 0.2))
            );
            return $dynamicTtl;
        }

        // Default fallback to 5 minutes
        return 300;
    }

    /**
     * Cache duration for immutable long-term items (1 year).
     */
    public function calculateQrCodeTtl(): int
    {
        return 365 * 24 * 3600;
    }

    /**
     * Get TTL array variations split into multi-unit definitions.
     */
    public function getLayeredTtl(int $ttlSeconds): array
    {
        return [
            'seconds' => $ttlSeconds,
            'minutes' => round($ttlSeconds / 60, 2),
            'hours' => round($ttlSeconds / 3600, 2),
            'days' => round($ttlSeconds / 86400, 2),
            'human_readable' => $this->formatDuration($ttlSeconds)
        ];
    }

    /**
     * Format seconds into human-readable duration string.
     */
    protected function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds} seconds";
        }

        if ($seconds < 3600) {
            $minutes = intdiv($seconds, 60);
            $remainingSeconds = $seconds % 60;
            return "{$minutes} minute" . ($minutes !== 1 ? 's' : '') .
                ($remainingSeconds > 0 ? " {$remainingSeconds} seconds" : "");
        }

        if ($seconds < 86400) {
            $hours = intdiv($seconds, 3600);
            $remainingMinutes = intdiv($seconds % 3600, 60);
            return "{$hours} hour" . ($hours !== 1 ? 's' : '') .
                ($remainingMinutes > 0 ? " {$remainingMinutes} minute" . ($remainingMinutes !== 1 ? 's' : '') : "");
        }

        $days = intdiv($seconds, 86400);
        $remainingHours = intdiv($seconds % 86400, 3600);
        return "{$days} day" . ($days !== 1 ? 's' : '') .
            ($remainingHours > 0 ? " {$remainingHours} hour" . ($remainingHours !== 1 ? 's' : '') : "");
    }

    /**
     * Determine if cache window lifecycle needs a soft refresh step.
     */
    public function shouldRefreshCache(
        DateTimeImmutable $cachedAt,
        int $ttlSeconds,
        float $refreshThreshold = 0.7
    ): bool {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $elapsed = $now->getTimestamp() - $cachedAt->getTimestamp();

        if ($elapsed >= $ttlSeconds) {
            return true;
        }

        return ($elapsed / $ttlSeconds) >= $refreshThreshold;
    }
}