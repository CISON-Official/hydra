<?php

namespace App\Services;

use App\Models\Certificate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;

class VerificationService
{
    protected string $secretKey;
    protected int $cacheTtl;

    public function __construct()
    {
        $this->secretKey = config('app.key');
        $this->cacheTtl = config('cache.certificate_ttl', 3600);
    }

    /**
     * Executes the primary cryptographic validation flow for an incoming scan.
     *
     * @throws HttpException
     */
    public function verifyCertificate(string $certificateId, string $signature): array
    {
        $cacheKey = "certificate:full:{$certificateId}";

        // 1. Fetch the certificate. Instead of querying twice if the status is cached,
        // we cache the full model payload to dramatically reduce DB IO operations.
        $cert = Cache::remember($cacheKey, $this->cacheTtl, function () use ($certificateId) {
            return Certificate::find($certificateId);
        });

        if (!$cert) {
            throw new HttpException(404, "Certificate not found");
        }

        // 2. Validate structural integrity state boundaries
        $now = Carbon::now('UTC');
        $isValid = ($cert->revoked_at === null) && ($cert->expires_at->isAfter($now));

        if (!$isValid) {
            throw new HttpException(410, "Certificate has expired or been revoked");
        }

        // 3. Cryptographic Signature Authentication check
        if (!$this->verifySignature($certificateId, $cert->qr_nonce, $signature)) {
            throw new HttpException(401, "Invalid signature");
        }

        // 4. Issue a secure, short-lived session token
        [$sessionToken, $ttlSeconds] = $this->createSessionToken($certificateId, $cert->expires_at);

        return [
            'session_token'       => $sessionToken,
            'expires_in_seconds'  => $ttlSeconds,
            'file_endpoint'       => "/api/v1/certificates/{$certificateId}/content",
            'certificate_title'   => $cert->certificate_title,
            'holder_name'         => $cert->holder_name,
            'expires_at'          => $cert->expires_at->toIso8601String(),
            'is_valid'            => true,
        ];
    }

    /**
     * Verify that the signature computed from the QR matches our application key.
     */
    protected function verifySignature(string $certificateId, string $nonce, string $signature): bool
    {
        $payload = "{$certificateId}:{$nonce}";
        $computedSignature = hash_hmac('sha256', $payload, $this->secretKey);

        return hash_equals($computedSignature, $signature);
    }

    /**
     * Create an encrypted session token tied to the certificate lifespan.
     */
    protected function createSessionToken(string $certificateId, Carbon $expiresAt): array
    {
        $now = Carbon::now('UTC');
        
        // Dynamic TTL: Caps token lifespan to the exact time the certificate expires,
        // or a default fallback threshold (e.g., 15 minutes), whichever is shorter.
        $maxTtlSeconds = 900; 
        $remainingCertLife = $now->diffInSeconds($expiresAt, false);
        $ttlSeconds = min($maxTtlSeconds, max(0, $remainingCertLife));

        // Package payload data tightly
        $tokenPayload = [
            'cert_id'    => $certificateId,
            'jti'        => Str::random(16),
            'expires_at' => $now->addSeconds($ttlSeconds)->timestamp
        ];

        // Encrypt symmetrically using AES-256-CBC via Laravel's native system key
        $sessionToken = Crypt::encrypt($tokenPayload);

        return [$sessionToken, $ttlSeconds];
    }
}