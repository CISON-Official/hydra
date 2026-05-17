<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Jobs\SaveAuditLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class AuditService
{
    /**
     * Log an audit security event payload record.
     */
    public function logAction(
        string $action,
        string $actorRole,
        ?string $actorId = null,
        ?string $certificateId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        bool $success = true,
        ?array $details = null,
        bool $asyncMode = true
    ): void {
        $attributes = [
            'action' => $action,
            'actor_role' => $actorRole,
            'actor_id' => $actorId,
            'certificate_id' => $certificateId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'success' => $success,
            'details' => $details ?? [],
        ];

        if ($asyncMode) {
            // Dispatches to execution queues (Redis/Database background processes)
            SaveAuditLog::dispatch($attributes);
        } else {
            // Synchronous inline flow processing 
            try {
                AuditLog::create($attributes);
            } catch (Exception $e) {
                Log::error("Failed to save foreground audit log: " . $e->getMessage());
            }
        }
    }

    /**
     * Retrieve chronological audit trails for a specific certificate entity ID.
     */
    public function getCertificateAuditTrail(string $certificateId, int $limit = 100, int $offset = 0): array
    {
        return AuditLog::where('certificate_id', $certificateId)
            ->orderBy('created_at', 'desc')
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->map(function ($log) {
                return [
                    "id" => $log->id,
                    "action" => $log->action,
                    "actor_role" => $log->actor_role,
                    "actor_id" => $log->actor_id,
                    "ip_address" => $log->ip_address,
                    "success" => $log->success,
                    "details" => $log->details,
                    "timestamp" => $log->created_at->toIso8601String()
                ];
            })
            ->all();
    }

    /**
     * Extract verification tracking statistics maps for dashboard generation profiles.
     */
    public function getVerificationStats(string $certificateId, int $days = 30): array
    {
        $cutoff = Carbon::now('UTC')->subDays($days);

        // Run targeted analytical aggregate queries against structural logging history indices
        $totalVerifications = AuditLog::where('certificate_id', $certificateId)
            ->where('action', 'verify')
            ->where('created_at', '>=', $cutoff)
            ->where('success', true)
            ->count();

        $uniqueVerifiers = AuditLog::where('certificate_id', $certificateId)
            ->where('action', 'verify')
            ->where('created_at', '>=', $cutoff)
            ->distinct('ip_address')
            ->count('ip_address');

        $failedAttempts = AuditLog::where('certificate_id', $certificateId)
            ->where('action', 'verify')
            ->where('created_at', '>=', $cutoff)
            ->where('success', false)
            ->count();

        $totalAttempts = $totalVerifications + $failedAttempts;
        $successRate = $totalAttempts > 0 ? ($totalVerifications / $totalAttempts) * 100 : 0;

        return [
            "certificate_id" => $certificateId,
            "period_days" => $days,
            "total_verifications" => $totalVerifications,
            "unique_verifiers" => $uniqueVerifiers,
            "failed_attempts" => $failedAttempts,
            "success_rate" => round($successRate, 2)
        ];
    }

    /**
     * Database cleanup maintenance logic routine.
     */
    public function pruneOldLogs(int $retentionDays = 90): array
    {
        $cutoff = Carbon::now('UTC')->subDays($retentionDays);

        $deletedCount = AuditLog::where('created_at', '<', $cutoff)->delete();

        return ["deleted_count" => $deletedCount];
    }
}