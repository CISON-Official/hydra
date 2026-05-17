<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\VerifyCertificateRequest;
use App\Models\Certificate;
use App\Services\AuditService;
use App\Services\Crypto\HMACHandler;
use App\Services\Crypto\TokenManager;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CertificateVerificationController extends Controller
{
    protected HMACHandler $hmacHandler;
    protected TokenManager $tokenManager;
    protected AuditService $auditService;

    public function __construct(
        HMACHandler $hmacHandler,
        TokenManager $tokenManager,
        AuditService $auditService
    ) {
        $this->hmacHandler = $hmacHandler;
        $this->tokenManager = $tokenManager;
        $this->auditService = $auditService;
    }

    /**
     * QR Code verification endpoint.
     * Validates HMAC, tracks verification attempts, and forwards to the layout viewer.
     */
    public function verifyAndRedirect(VerifyCertificateRequest $request, string $id): mixed
    {
        $clientIp = $request->ip() ?? 'unknown';
        $userAgent = $request->userAgent() ?? 'unknown';
        $format = $request->input('format', 'html');

        // Step 1: Resolve Model with 5-minute cache wrapper layer
        $certData = Cache::remember("certificate:status:{$id}", 300, function () use ($id) {
            $cert = Certificate::find($id);
            if (!$cert)
                return null;

            return [
                'id' => $cert->id,
                'title' => $cert->certificate_title,
                'holder_name' => $cert->holder_name,
                'holder_email' => $cert->holder_email,
                'expires_at' => $cert->expires_at->toIso8601String(),
                'revoked_at' => $cert->revoked_at?->toIso8601String(),
                'current_version' => $cert->current_version,
                'qr_nonce' => $cert->qr_nonce,
            ];
        });

        if (!$certData) {
            $this->auditService->logAction($id, 'verify', 'verifier', $clientIp, $userAgent, false, ['error' => 'Certificate not found']);
            abort(404, "Certificate not found");
        }

        // Evaluate validation timestamps
        $expiresAt = Carbon::parse($certData['expires_at']);
        $isRevoked = !is_null($certData['revoked_at']);
        $isValid = !$isRevoked && $expiresAt->isAfter(now());

        // Step 2: Validate Cryptographic HMAC signature 
        $nonce = $certData['qr_nonce'];
        if (!$nonce) {
            abort(400, "QR code not configured for this certificate");
        }

        $isValidHmac = $this->hmacHandler->verifySignature($id, $nonce, $request->input('hmac'));

        if (!$isValidHmac) {
            $this->auditService->logAction($id, 'verify', 'verifier', $clientIp, $userAgent, false, ['error' => 'Invalid HMAC signature']);
            abort(401, "Invalid QR code signature");
        }

        // Step 3: Check certificate validity bounds
        if (!$isValid) {
            $this->auditService->logAction($id, 'verify', 'verifier', $clientIp, $userAgent, false, ['error' => 'Certificate expired or revoked']);

            if ($format === 'json') {
                return response()->json([
                    'error' => 'Certificate expired or revoked',
                    'certificate_id' => $id,
                    'expires_at' => $expiresAt->toIso8601String(),
                    'is_valid' => false,
                ], 410);
            }

            return response()->view('certificates.expired', [
                'certificate_id' => $id,
                'expires_at' => $expiresAt,
                'certificate_title' => $certData['title'],
            ]);
        }

        // Step 4: Generate short-lived state session token for downlinks
        [$sessionToken, $ttlSeconds] = $this->tokenManager->createSessionToken($id, $expiresAt);

        // Step 5: Log verification completion audit trace
        $this->auditService->logAction($id, 'verify', 'verifier', $clientIp, $userAgent, true, ['method' => 'qr_code', 'format' => $format]);

        // Step 6: Route based on runtime interface format
        if ($format === 'json') {
            return response()->json([
                'success' => true,
                'certificate_id' => $id,
                'certificate_title' => $certData['title'],
                'holder_name' => $certData['holder_name'],
                'expires_at' => $expiresAt->toIso8601String(),
                'is_valid' => true,
                'session_token' => $sessionToken,
                'download_url' => "/api/certificates/{$id}/download",
                'stream_url' => "/api/certificates/{$id}/stream",
                'token_expires_in' => $ttlSeconds,
            ]);
        }

        if ($format === 'direct') {
            return redirect("/api/certificates/{$id}/download?token={$sessionToken}");
        }

        return response()->view('certificates.viewer', [
            'certificate_id' => $id,
            'certificate_title' => $certData['title'],
            'holder_name' => $certData['holder_name'],
            'issued_to' => $certData['holder_email'],
            'expires_at' => $expiresAt,
            'session_token' => $sessionToken,
            'token_expires_in' => $ttlSeconds,
            'verification_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get structural file summary statistics without rendering direct download payloads.
     */
    public function getCertificateInfo(Request $request, string $id): JsonResponse
    {
        $request->validate(['hmac' => 'required|string']);
        $cert = Certificate::findOrFail($id);

        if (!$this->hmacHandler->verifySignature($id, $cert->qr_nonce, $request->query('hmac'))) {
            abort(401, "Invalid HMAC signature");
        }

        $isValid = is_null($cert->revoked_at) && $cert->expires_at->isAfter(now());

        return response()->json([
            'certificate_id' => $cert->id,
            'title' => $cert->certificate_title,
            'holder_name' => $cert->holder_name,
            'holder_email' => $cert->holder_email,
            'issued_at' => $cert->created_at->toIso8601String(),
            'expires_at' => $cert->expires_at->toIso8601String(),
            'is_valid' => $isValid,
            'current_version' => $cert->current_version,
            'file_size_bytes' => $cert->file_size_bytes,
            'revoked' => !is_null($cert->revoked_at),
        ]);
    }

    /**
     * Streams the cloud document resource chunk-by-chunk directly down to client channels.
     */
    public function streamCertificate(Request $request, string $id): StreamedResponse
    {
        $request->validate(['token' => 'required|string']);
        [$certId, $tokenExpiry, $isValidToken] = $this->tokenManager->validateSessionToken($request->query('token'));

        if (!$isValidToken || $certId !== $id) {
            abort(401, "Invalid or expired session token");
        }

        $cert = Certificate::findOrFail($id);
        if (!is_null($cert->revoked_at) || $cert->expires_at->isPast()) {
            abort(410, "Certificate expired or revoked");
        }

        $this->auditService->logAction($id, 'stream', 'verifier', $request->ip(), $request->userAgent(), true, ['streaming' => true]);

        return Storage::disk('s3')->response($cert->s3_key, null, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'no-cache',
            'X-Certificate-ID' => $id,
        ]);
    }

    /**
     * Download the certificate file asset container.
     */
    public function downloadCertificate(Request $request, string $id): Response
    {
        $request->validate([
            'token' => 'required|string',
            'inline' => 'nullable|boolean'
        ]);

        [$certId, $tokenExpiry, $isValidToken] = $this->tokenManager->validateSessionToken($request->query('token'));

        if (!$isValidToken || $certId !== $id) {
            abort(401, "Invalid or expired session token. Please scan QR code again.");
        }

        $cert = Certificate::findOrFail($id);
        $isCertValid = is_null($cert->revoked_at) && $cert->expires_at->isAfter(now());

        if (!$isCertValid) {
            abort(410, "Certificate expired or revoked. Cannot download.");
        }

        try {
            $fileContent = Storage::disk('s3')->get($cert->s3_key);
            $inline = $request->boolean('inline', true);
            $disposition = $inline ? 'inline' : 'attachment';
            $filename = "certificate_{$id}.pdf";

            $this->auditService->logAction($id, 'download', 'verifier', $request->ip(), $request->userAgent(), true, [
                'via' => 'qr_code',
                'token_valid' => true,
                'file_size' => strlen($fileContent)
            ]);

            return response($fileContent, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "{$disposition}; filename=\"{$filename}\"",
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
                'X-Certificate-ID' => $id,
                'X-Valid-Until' => $cert->expires_at->toIso8601String(),
                'X-Session-Expires' => $tokenExpiry ? $tokenExpiry->toIso8601String() : 'N/A'
            ]);

        } catch (\Exception $e) {
            $this->auditService->logAction($id, 'download', 'verifier', $request->ip(), $request->userAgent(), false, ['error' => $e->getMessage()]);
            abort(500, "Failed to retrieve certificate: {$e->getMessage()}");
        }
    }
}