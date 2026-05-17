<?php

namespace App\Services\Crypto;

class HMACHandler
{
    protected string $secretKey;

    /**
     * Pass your application's private signing key via the constructor.
     */
    public function __construct(string $secretKey)
    {
        $this->secretKey = $secretKey;
    }

    /**
     * Generate HMAC signature for QR URL.
     * Matches Python: hmac.new(key, payload, hashlib.sha256).hexdigest()
     */
    public function generateSignature(string $certificateId, string $nonce): string
    {
        $payload = "{$certificateId}|{$nonce}";

        return hash_hmac('sha256', $payload, $this->secretKey);
    }

    /**
     * Verify HMAC signature securely using a constant-time comparison algorithm.
     * Matches Python: hmac.compare_digest()
     */
    public function verifySignature(string $certificateId, string $nonce, string $signature): bool
    {
        $expected = $this->generateSignature($certificateId, $nonce);

        return hash_equals($expected, $signature);
    }

    /**
     * Generate a cryptographically secure, URL-safe nonce for QR codes.
     * Matches Python: secrets.token_urlsafe(32)
     */
    public function generateNonce(): string
    {
        // 32 bytes of raw randomness equals roughly 43 characters when Base64URL encoded
        $bytes = random_bytes(32);
        
        // Convert to URL-safe Base64 (strip padding and swap safe URL characters)
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}