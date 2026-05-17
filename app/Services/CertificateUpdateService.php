<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\CertificateVersion;
use App\Services\AuditService;
use App\Services\RedisManager;
use App\Services\QRGeneratorService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class CertificateUpdateService
{
    protected RedisManager $redis;
    protected AuditService $audit;
    protected QRGeneratorService $qrService;

    public function __construct(
        RedisManager $redis,
        AuditService $audit,
        QRGeneratorService $qrService
    ) {
        $this->redis = $redis;
        $this->audit = $audit;
        $this->qrService = $qrService;
    }

    /**
     * Update certificate file while maintaining a strict ledger audit trail.
     */
    public function updateCertificateFile(
        string $certificateId,
        UploadedFile $newFile,
        string $updateReason,
        string $updatedBy,
        bool $preserveQr = true,
        bool $notifyHolder = true
    ): array {
        $cert = Certificate::findOrFail($certificateId);

        if ($cert->revoked_at !== null) {
            abort(400, "Cannot update revoked certificate. Unrevoke first.");
        }

        if ($cert->expires_at->isPast()) {
            abort(400, "Cannot update expired certificate. Extend expiry first.");
        }

        // Validate structural file boundaries
        if ($newFile->getSize() > 10 * 1024 * 1024) {
            abort(400, "File too large (max 10MB)");
        }

        if ($newFile->getMimeType() !== 'application/pdf') {
            abort(400, "Only PDF files allowed");
        }

        $fileContent = file_get_contents($newFile->getRealPath());
        $newFileHash = hash('sha256', $fileContent);

        if ($newFileHash === $cert->file_hash) {
            abort(400, "New file is identical to current version. No update needed.");
        }

        return DB::transaction(function () use ($cert, $fileContent, $newFile, $newFileHash, $updateReason, $updatedBy, $preserveQr, $notifyHolder) {
            // 1. Archive the current row parameters before altering them
            $this->archiveCurrentVersion($cert, $updatedBy, $updateReason);

            $nextVersionNumber = $cert->current_version + 1;
            $newS3Key = "certificates/{$cert->id}/v{$nextVersionNumber}.pdf";

            // 2. Stream payload up to configured storage filesystem (AWS S3)
            try {
                // Uses default cloud file disk configuration set inside config/filesystems.php
                Storage::disk('s3')->put($newS3Key, $fileContent);
                $fileSize = $newFile->getSize();
            } catch (\Exception $e) {
                abort(500, "Failed to upload new version: " . $e->getMessage());
            }

            $oldS3Key = $cert->s3_key;
            $oldVersion = $cert->current_version;

            // 3. Update primary pointer records
            $cert->update([
                'current_version' => $nextVersionNumber,
                'file_hash' => $newFileHash,
                's3_key' => $newS3Key,
                'file_size_bytes' => $fileSize,
                'updated_at' => Carbon::now('UTC'),
                'last_updated_by' => $updatedBy,
                'update_reason' => $updateReason,
            ]);

            $newQrUrl = null;
            if (!$preserveQr) {
                $newQrUrl = $this->qrService->regenerateQr($cert->id);
            }

            // 4. Flush Redis and commit the record to the audit ledger
            $this->redis->invalidateCertificate((string)$cert->id);

            $this->audit->logAction(
                action: 'update_certificate',
                actorRole: 'issuer',
                actorId: $updatedBy,
                certificateId: (string)$cert->id,
                details: [
                    'old_version' => $oldVersion,
                    'new_version' => $cert->current_version,
                    'old_hash' => substr($cert->file_hash, 0, 8),
                    'new_hash' => substr($newFileHash, 0, 8),
                    'update_reason' => $updateReason,
                    'qr_regenerated' => !$preserveQr,
                    'old_s3_key' => $oldS3Key,
                    'new_s3_key' => $newS3Key
                ]
            );

            if ($notifyHolder) {
                $this->notifyHolderOfUpdate($cert, $updateReason);
            }

            return [
                "certificate_id" => $cert->id,
                "updated" => true,
                "old_version" => $oldVersion,
                "new_version" => $cert->current_version,
                "update_reason" => $updateReason,
                "updated_at" => $cert->updated_at->toIso8601String(),
                "qr_code_updated" => !$preserveQr,
                "new_qr_url" => $newQrUrl
            ];
        });
    }

    /**
     * Rollback certificate to a previous version structure.
     */
    public function rollbackToVersion(
        string $certificateId,
        int $targetVersion,
        string $rollbackReason,
        string $rolledBackBy
    ): array {
        $cert = Certificate::findOrFail($certificateId);

        $targetVersionData = CertificateVersion::where('certificate_id', $certificateId)
            ->where('version_number', $targetVersion)
            ->first();

        if (!$targetVersionData) {
            abort(404, "Version {$targetVersion} not found for this certificate.");
        }

        return DB::transaction(function () use ($cert, $targetVersionData, $targetVersion, $rollbackReason, $rolledBackBy) {
            // Archive current parameters before applying historical structural points
            $this->archiveCurrentVersion($cert, $rolledBackBy, "Pre-rollback to v{$targetVersion}");

            $oldCurrentVersion = $cert->current_version;

            $cert->update([
                'current_version' => $cert->current_version + 1,
                'file_hash' => $targetVersionData->file_hash,
                's3_key' => $targetVersionData->s3_key,
                'file_size_bytes' => $targetVersionData->file_size_bytes,
                'updated_at' => Carbon::now('UTC'),
                'last_updated_by' => $rolledBackBy,
                'update_reason' => "Rollback to version {$targetVersion}: {$rollbackReason}"
            ]);

            $this->redis->invalidateCertificate((string)$cert->id);

            $this->audit->logAction(
                action: 'rollback_certificate',
                actorRole: 'issuer',
                actorId: $rolledBackBy,
                certificateId: (string)$cert->id,
                details: [
                    'from_version' => $oldCurrentVersion,
                    'to_version' => $targetVersion,
                    'reason' => $rollbackReason
                ]
            );

            return [
                "certificate_id" => $cert->id,
                "rolled_back" => true,
                "previous_version" => $oldCurrentVersion,
                "current_version" => $cert->current_version,
                "target_version_restored" => $targetVersion,
                "rollback_reason" => $rollbackReason
            ];
        });
    }

    /**
     * Get version history data maps for UI components.
     */
    public function getVersionHistory(string $certificateId, int $limit = 50): array
    {
        $current = Certificate::findOrFail($certificateId);
        
        $versions = CertificateVersion::where('certificate_id', $certificateId)
            ->orderBy('version_number', 'desc')
            ->limit($limit)
            ->get();

        $history = [];

        foreach ($versions as $version) {
            $history[] = [
                "version" => $version->version_number,
                "is_current" => false,
                "file_hash" => substr($version->file_hash, 0, 16) . "...",
                "file_size_bytes" => $version->file_size_bytes,
                "updated_by" => $version->updated_by,
                "update_reason" => $version->update_reason,
                "updated_at" => $version->created_at->toIso8601String()
            ];
        }

        // Prepend current live version metadata to index position 0
        array_unshift($history, [
            "version" => $current->current_version,
            "is_current" => true,
            "file_hash" => substr($current->file_hash, 0, 16) . "...",
            "file_size_bytes" => $current->file_size_bytes,
            "updated_by" => $current->last_updated_by,
            "update_reason" => $current->update_reason,
            "updated_at" => $current->updated_at->toIso8601String()
        ]);

        return $history;
    }

    /**
     * Compare structural metadata keys across two historic versions.
     */
    public function compareVersions(string $certificateId, int $versionA, int $versionB): array
    {
        $current = Certificate::findOrFail($certificateId);

        $fetchVersion = function ($versionNum) use ($current, $certificateId) {
            if ($versionNum === 0 || $versionNum === $current->current_version) {
                return $current;
            }
            return CertificateVersion::where('certificate_id', $certificateId)
                ->where('version_number', $versionNum)
                ->first();
        };

        $dataA = $fetchVersion($versionA);
        $dataB = $fetchVersion($versionB);

        if (!$dataA || !$dataB) {
            abort(404, "Target validation version profile keys not found.");
        }

        return [
            "certificate_id" => $certificateId,
            "version_a" => [
                "number" => $versionA,
                "file_hash" => $dataA->file_hash,
                "file_size" => $dataA->file_size_bytes,
                "updated_at" => isset($dataA->created_at) ? $dataA->created_at->toIso8601String() : $dataA->updated_at->toIso8601String()
            ],
            "version_b" => [
                "number" => $versionB,
                "file_hash" => $dataB->file_hash,
                "file_size" => $dataB->file_size_bytes,
                "updated_at" => isset($dataB->created_at) ? $dataB->created_at->toIso8601String() : $dataB->updated_at->toIso8601String()
            ],
            "identical" => $dataA->file_hash === $dataB->file_hash,
            "size_difference_bytes" => abs($dataA->file_size_bytes - $dataB->file_size_bytes)
        ];
    }

    /**
     * Extend certificate expiry metrics and optionally parse a replacement binary asset file.
     */
    public function extendExpiryWithUpdate(
        string $certificateId,
        Carbon $newExpiryDate,
        string $extensionReason,
        string $updatedBy,
        ?UploadedFile $updateFile = null
    ): array {
        $cert = Certificate::findOrFail($certificateId);
        $oldExpiry = $cert->expires_at;

        if ($updateFile) {
            // Forward processing requirements down to existing update function
            $fileUpdateResult = $this->updateCertificateFile(
                certificateId: $certificateId,
                newFile: $updateFile,
                updateReason: "Extension and file update: {$extensionReason}",
                updatedBy: $updatedBy,
                preserveQr: true,
                notifyHolder: true
            );
            
            // Sync live runtime object parameters for output response accuracy
            $cert->refresh();
            $cert->update(['expires_at' => $newExpiryDate]);
        } else {
            $cert->update([
                'expires_at' => $newExpiryDate,
                'updated_at' => Carbon::now('UTC'),
                'last_updated_by' => $updatedBy
            ]);

            $this->redis->invalidateCertificate((string)$cert->id);

            $this->audit->logAction(
                action: 'extend_expiry',
                actorRole: 'issuer',
                actorId: $updatedBy,
                certificateId: (string)$cert->id,
                details: [
                    'old_expiry' => $oldExpiry->toIso8601String(),
                    'new_expiry' => $newExpiryDate->toIso8601String(),
                    'reason' => $extensionReason
                ]
            );

            $fileUpdateResult = ["updated" => false];
        }

        return [
            "certificate_id" => $cert->id,
            "old_expiry" => $oldExpiry->toIso8601String(),
            "new_expiry" => $newExpiryDate->toIso8601String(),
            "file_updated" => $fileUpdateResult['updated'] ?? false,
            "new_version" => $fileUpdateResult['updated'] ? $cert->current_version : null,
            "extension_reason" => $extensionReason
        ];
    }

    /**
     * Save the current state parameters to history before applying changes.
     */
    protected function archiveCurrentVersion(Certificate $cert, string $updatedBy, string $updateReason): void
    {
        CertificateVersion::create([
            'certificate_id' => $cert->id,
            'version_number' => $cert->current_version,
            'file_hash' => $cert->file_hash,
            's3_key' => $cert->s3_key,
            'file_size_bytes' => $cert->file_size_bytes,
            'updated_by' => $updatedBy,
            'update_reason' => "Archived before update: {$updateReason}"
        ]);
    }

    /**
     * Send dispatch alert events down to notifications service layer blocks.
     */
    protected function notifyHolderOfUpdate(Certificate $cert, string $updateReason): void
    {
        // For production scale dispatch async notification events to Laravel queues here
        // e.g., event(new CertificateUpdatedEvent($cert, $updateReason));
    }
}