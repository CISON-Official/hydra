<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCertificateRequest;
use App\Http\Requests\ExtendCertificateRequest;
use App\Models\Certificate;
use App\Models\CertificateVersion;
use App\Models\AuditLog;
use App\Services\CertificateUpdateService;
use App\Services\QRGeneratorService;
use App\Services\RevocationService;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;

class CertificateAdministrationController extends Controller
{
    protected CertificateUpdateService $updateService;
    protected QRGeneratorService $qrService;
    protected RevocationService $revocationService;
    protected AuditService $auditService;

    public function __construct(
        CertificateUpdateService $updateService,
        QRGeneratorService $qrService,
        RevocationService $revocationService,
        AuditService $auditService
    ) {
        $this->updateService = $updateService;
        $this->qrService = $qrService;
        $this->revocationService = $revocationService;
        $this->auditService = $auditService;
    }

    /**
     * Upload a brand new certificate.
     */
    public function store(StoreCertificateRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $fileContent = file_get_contents($file->getRealPath());
        $fileHash = hash('sha256', $fileContent);

        // Check for duplicate by content fingerprint hash
        if (Certificate::where('file_hash', $fileHash)->exists()) {
            abort(409, "Certificate already exists with same content");
        }

        // Handle Cloud Storage path routing
        $certificateId = Str::uuid()->toString();
        $s3Key = "certificates/" . Str::uuid() . ".pdf";

        try {
            Storage::disk('s3')->put($s3Key, $fileContent);
        } catch (\Exception $e) {
            abort(500, "Failed to upload file to storage bucket");
        }

        $user = $request->user();
        $expiry = Carbon::parse($request->input('expires_at'))->utc();

        $certificate = Certificate::create([
            'id' => $certificateId,
            'issuer_id' => $user->id,
            'holder_email' => $request->input('holder_email'),
            'holder_name' => $request->input('holder_name'),
            'certificate_title' => $request->input('certificate_title'),
            'file_hash' => $fileHash,
            's3_key' => $s3Key,
            'file_size_bytes' => $file->getSize(),
            'expires_at' => $expiry,
            'qr_nonce' => !$request->boolean('regenerate_qr') ? Str::random(32) : null,
        ]);

        $qrUrl = $this->qrService->generateQrUrl($certificate->id, $request->boolean('regenerate_qr'));

        $this->auditService->logAction(
            action: 'upload',
            actorRole: $user->role,
            actorId: $user->id,
            certificateId: $certificate->id,
            details: [
                'title' => $certificate->certificate_title,
                'holder_email' => $certificate->holder_email,
                'file_size' => $certificate->file_size_bytes,
            ]
        );

        return response()->json([
            'certificate_id' => $certificate->id,
            'qr_code_url' => $qrUrl,
            'verification_url' => config('app.url') . "/verify/" . $certificate->id,
            'expires_at' => $expiry->toIso8601String(),
            'holder_name' => $certificate->holder_name,
            'certificate_title' => $certificate->certificate_title,
        ], 201);
    }

    /**
     * Show detailed internal metrics for a certificate.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $cert = Certificate::findOrFail($id);
        $user = $request->user();

        if ($user->role === 'issuer' && $cert->issuer_id !== $user->id) {
            abort(403, "Access Denied.");
        }

        $verificationCount = AuditLog::where('certificate_id', $id)
            ->whereIn('action', ['verify', 'view'])
            ->count();

        return response()->json([
            'id' => $cert->id,
            'holder_email' => $cert->holder_email,
            'holder_name' => $cert->holder_name,
            'certificate_title' => $cert->certificate_title,
            'created_at' => $cert->created_at->toIso8601String(),
            'expires_at' => $cert->expires_at->toIso8601String(),
            'revoked_at' => $cert->revoked_at?->toIso8601String(),
            'revocation_reason' => $cert->revocation_reason,
            'file_size_bytes' => $cert->file_size_bytes,
            'verification_count' => $verificationCount,
            'is_valid' => ($cert->revoked_at === null) && $cert->expires_at->isAfter(now()),
        ]);
    }

    /**
     * List certificates with pagination filters.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = $request->integer('limit', 100);
        $includeExpired = $request->boolean('include_expired', false);

        $query = Certificate::query();

        if ($user->role === 'issuer') {
            $query->where('issuer_id', $user->id);
        }

        if (!$includeExpired) {
            $query->where('expires_at', '>', now())->whereNull('revoked_at');
        }

        $certificates = $query->orderBy('created_at', 'desc')
            ->paginate($limit > 1000 ? 1000 : $limit);

        $transformed = collect($certificates->items())->map(function ($cert) {
            return [
                'id' => $cert->id,
                'certificate_title' => $cert->certificate_title,
                'holder_name' => $cert->holder_name,
                'created_at' => $cert->created_at->toIso8601String(),
                'expires_at' => $cert->expires_at->toIso8601String(),
                'is_valid' => ($cert->revoked_at === null) && $cert->expires_at->isAfter(now()),
            ];
        });

        return response()->json($transformed);
    }

    /**
     * Revoke a single operational certificate.
     */
    public function revoke(Request $request, string $id): JsonResponse
    {
        $request->validate(['reason' => 'required|string|max:1000']);
        $cert = Certificate::findOrFail($id);
        $user = $request->user();

        if ($user->role === 'issuer' && $cert->issuer_id !== $user->id) {
            abort(403, "Cannot revoke certificates from other issuers");
        }

        $success = $this->revocationService->revokeCertificate($id, $request->input('reason'), $user->id);

        if (!$success) {
            abort(400, "Certificate already revoked or expired");
        }

        $this->auditService->logAction('revoke', $user->role, $user->id, $id, ['reason' => $request->input('reason')]);

        return response()->json([
            'certificate_id' => $id,
            'revoked_at' => now()->toIso8601String(),
            'reason' => $request->input('reason'),
            'success' => true,
        ]);
    }

    /**
     * Rotate QR cryptographic validation nonce parameters.
     */
    public function rotateQr(Request $request, string $id): JsonResponse
    {
        $cert = Certificate::findOrFail($id);
        $user = $request->user();

        if ($user->role === 'issuer' && $cert->issuer_id !== $user->id) {
            abort(403, "Access Denied.");
        }

        $newUrl = $this->qrService->regenerateQr($id);

        $this->auditService->logAction('rotate_qr', $user->role, $user->id, $id, ['old_qr_invalidated' => true]);

        return response()->json([
            'certificate_id' => $id,
            'new_qr_code_url' => $newUrl,
            'old_qr_invalidated' => true,
            'regenerated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Modify and upload replacement certificate document files (with system versioning).
     */
    public function updateFile(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'new_file' => 'required|file|mimes:pdf|max:10240',
            'update_reason' => 'required|string|max:1000',
            'preserve_qr' => 'boolean',
            'notify_holder' => 'boolean',
        ]);

        $user = $request->user();

        if ($user->role === 'issuer') {
            $cert = Certificate::findOrFail($id);
            if ($cert->issuer_id !== $user->id) {
                abort(403, "Cannot update certificates from other issuers");
            }
        }

        $result = $this->updateService->updateCertificateFile(
            $id,
            $request->file('new_file'),
            $request->input('update_reason'),
            $user->id,
            $request->boolean('preserve_qr', true),
            $request->boolean('notify_holder', true)
        );

        return response()->json($result);
    }

    /**
     * Execute a historical rollback mutation.
     */
    public function rollback(Request $request, string $id, int $version): JsonResponse
    {
        $request->validate(['rollback_reason' => 'required|string|max:1000']);

        $result = $this->updateService->rollbackToVersion(
            $id,
            $version,
            $request->input('rollback_reason'),
            $request->user()->id
        );

        return response()->json($result);
    }

    /**
     * Pull full structural trace histories.
     */
    public function versions(Request $request, string $id): JsonResponse
    {
        $limit = $request->integer('limit', 50);
        $history = $this->updateService->getVersionHistory($id, $limit);

        return response()->json([
            'certificate_id' => $id,
            'total_versions' => count($history),
            'versions' => $history,
        ]);
    }

    /**
     * Compare distinct differences between specific versions.
     */
    public function compare(Request $request, string $id): JsonResponse
    {
        $versionA = $request->integer('version_a');
        $versionB = $request->integer('version_b');

        $comparison = $this->updateService->compareVersions($id, $versionA, $versionB);

        return response()->json($comparison);
    }

    /**
     * Mutate certificate lifetime constraints.
     */
    public function extend(ExtendCertificateRequest $request, string $id): JsonResponse
    {
        $result = $this->updateService->extendExpiryWithUpdate(
            $id,
            Carbon::parse($request->input('new_expiry_date')),
            $request->input('extension_reason'),
            $request->user()->id,
            $request->file('update_file')
        );

        return response()->json($result);
    }

    /**
     * Stream a raw historical version binary from storage.
     */
    public function downloadVersion(Request $request, string $id, int $version): Response
    {
        if ($version === 0) {
            $cert = Certificate::findOrFail($id);
            $s3Key = $cert->s3_key;
            $fileHash = $cert->file_hash;
        } else {
            $versionData = CertificateVersion::where('certificate_id', $id)
                ->where('version_number', $version)
                ->firstOrFail();

            $s3Key = $versionData->s3_key;
            $fileHash = $versionData->file_hash;
        }

        try {
            $fileContent = Storage::disk('s3')->get($s3Key);

            $this->auditService->logAction(
                'download_version',
                $request->user()->role,
                $request->user()->id,
                $id,
                ['version' => $version, 'file_hash' => substr($fileHash, 0, 8)]
            );

            return response($fileContent, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "inline; filename=\"certificate_{$id}_v{$version}.pdf\"",
                'X-File-Hash' => $fileHash,
                'X-Version' => $version,
            ]);
        } catch (\Exception $e) {
            abort(500, "Failed to download asset from core file storage driver system");
        }
    }
}