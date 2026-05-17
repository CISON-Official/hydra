<?php

namespace App\Services\Crypto;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;

class TokenManager
{
    protected string $secretKey;
    protected int $maxTtlSeconds;

    public function __construct(string $secretKey, int $maxTtlSeconds = 900)
    {
        if (empty($secretKey)) {
            throw new InvalidArgumentException("Secret key cannot be empty.");
        }
        $this->secretKey = $secretKey;
        $this->maxTtlSeconds = $maxTtlSeconds;
    }

    /**
     * Create short-lived session token with real-time expiry bound.
     * * @return array{0: string, 1: int} Returns [token, ttl_seconds]
     */
    public function createSessionToken(string $certificateId, DateTimeImmutable $certificateExpiry): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // Calculate remaining certificate lifetime in seconds
        $remaining = $certificateExpiry->getTimestamp() - $now->getTimestamp();

        // Token TTL = min(max_ttl, remaining certificate lifetime). Ensure it's at least 1.
        $ttlSeconds = max(1, min($this->maxTtlSeconds, $remaining));
        $tokenExpiry = $now->modify("+{$ttlSeconds} seconds");

        // Format ISO string, replacing '+' with '_' to match your Python token string syntax perfectly
        $expiryIso = str_replace('+', '_', $tokenExpiry->format(DateTimeImmutable::ATOM));

        $payload = "{$certificateId}|{$expiryIso}";
        $signature = hash_hmac('sha256', $payload, $this->secretKey);

        $token = "{$payload}|{$signature}";

        return [$token, $ttlSeconds];
    }

    /**
     * Validate session token and extract parameters.
     * * @return array{0: ?string, 1: ?DateTimeImmutable, 2: bool} Returns [certificate_id, token_expiry, is_valid]
     */
    public function validateSessionToken(string $token): array
    {
        try {
            $parts = explode('|', $token);
            if (count($parts) !== 3) {
                return [null, null, false];
            }

            [$certificateId, $expiryIso, $signature] = $parts;

            // Revert token-safe formatting back to proper ISO format
            $normalizedIso = str_replace('_', '+', $expiryIso);
            $tokenExpiry = new DateTimeImmutable($normalizedIso, new DateTimeZone('UTC'));

            // Verify HMAC signature
            $payload = "{$certificateId}|{$expiryIso}";
            $expectedSignature = hash_hmac('sha256', $payload, $this->secretKey);

            if (!hash_equals($expectedSignature, $signature)) {
                return [null, null, false];
            }

            // Check if the token itself has expired chronologically
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            if ($tokenExpiry < $now) {
                return [null, null, false];
            }

            return [$certificateId, $tokenExpiry, true];

        } catch (Exception $e) {
            return [null, null, false];
        }
    }
}