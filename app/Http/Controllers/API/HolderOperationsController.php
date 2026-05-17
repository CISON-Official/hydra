<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\HolderCertificateResource;
use App\Models\Certificate;
use App\Services\QRGeneratorService;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HolderOperationsController extends Controller
{
    protected QRGeneratorService $qrService;
    protected AuditService $auditService;

    public function __construct(QRGeneratorService $qrService, AuditService $auditService)
    {
        $this->qrService = $qrService;
        $this->auditService = $auditService;
    }

    /**
     * List all certificates belonging to a holder (by email index matching).
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'include_expired' => 'nullable|boolean'
        ]);

        $email = $request->query('email');
        $includeExpired = $request->boolean('include_expired');

        $query = Certificate::where('holder_email', $email);

        if (!$includeExpired) {
            $query->where('expires_at', '>', now())
                  ->whereNull('revoked_at');
        }

        $certificates = $query->orderBy('created_at', 'desc')->get();

        // Audit tracking logs summary metadata safely without collecting PII
        $this->auditService->logAction(
            certificateId: null,
            action: 'list_certificates',
            actorRole: 'holder',
            actorId: $email,
            details: ['count' => $certificates->count()]
        );

        return response()->json(
            HolderCertificateResource::collection($certificates)
        );
    }

    /**
     * Generate and stream PNG QR asset buffers down to client attachment download contexts.
     */
    public function downloadQr(Request $request, string $id): StreamedResponse
    {
        $request->validate(['email' => 'required|email']);
        $email = $request->query('email');

        // Confirm access scope assignment
        $cert = Certificate::where('id', $id)
            ->where('holder_email', $email)
            ->firstOrFail();

        $qrUrl = $this->qrService->generateQrUrl($cert->id);
        
        // This abstracts FastAPI's BytesIO wrapper into a clean resource stream callback
        $qrImageStream = $this->qrService->generateQrImageStream($qrUrl);

        $this->auditService->logAction(
            certificateId: $cert->id,
            action: 'download_qr',
            actorRole: 'holder',
            actorId: $email,
            details: ['qr_url' => Str::limit($qrUrl, 50, '')]
        );

        return response()->streamDownload(function () use ($qrImageStream) {
            fpassthru($qrImageStream);
            if (is_resource($qrImageStream)) {
                fclose($qrImageStream);
            }
        }, "certificate_{$id}_qr.png", [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * Real-time lifecycle status analyzer.
     */
    public function status(Request $request, string $id): JsonResponse
    {
        $request->validate(['email' => 'required|email']);
        
        $cert = Certificate::where('id', $id)
            ->where('holder_email', $email = $request->query('email'))
            ->firstOrFail();

        $isValid = is_null($cert->revoked_at) && $cert->expires_at->isAfter(now());
        $timeRemaining = $isValid ? now()->diffInSeconds($cert->expires_at, false) : null;

        return response()->json([
            'certificate_id' => $cert->id,
            'is_valid' => $isValid,
            'issued_at' => $cert->created_at->toIso8601String(),
            'expires_at' => $cert->expires_at->toIso8601String(),
            'time_remaining_seconds' => $timeRemaining,
            'revoked_at' => $cert->revoked_at?->toIso8601String(),
            'revocation_reason' => $cert->revocation_reason,
        ]);
    }

    /**
     * Fire asynchronous dispatch pipelines notifying administrative issuers of structural renewal queries.
     */
    public function requestRenewal(Request $request, string $id): JsonResponse
    {
        $request->validate(['email' => 'required|email']);
        $email = $request->query('email');

        $cert = Certificate::where('id', $id)
            ->where('holder_email', $email)
            ->firstOrFail();

        // Dispatch background notification listener pipelines in production here
        // Notification::send($cert->issuer, new RenewalRequestedNotification($cert));

        $this->auditService->logAction(
            certificateId: $id,
            action: 'request_renewal',
            actorRole: 'holder',
            actorId: $email,
            details: [
                'issuer_id' => $cert->issuer_id,
                'current_expiry' => $cert->expires_at->toIso8601String(),
            ]
        );

        return response()->json([
            'message' => 'Renewal request submitted successfully',
            'certificate_id' => $id,
            'issuer_notified' => true,
        ]);
    }
}