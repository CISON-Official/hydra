<?php

namespace App\Services;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AdminService
{
    protected AuditService $auditService;

    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    /**
     * Get the currently active admin.
     */
    public function getActiveAdmin(): ?User
    {
        return User::where('role', 'admin')
            ->where('is_active', true)
            ->first();
    }

    /**
     * Check if any admin exists.
     */
    public function isAdminExists(bool $includeInactive = false): bool
    {
        $query = User::where('role', 'admin');

        if (!$includeInactive) {
            $query->where('is_active', true);
        }

        return $query->exists();
    }

    /**
     * Create the first admin user (only allowed if NO admin exists at all).
     * * @return array{0: User, 1: string} Returns [User object, plaintext API key]
     */
    public function createFirstAdmin(string $email, string $name, ?string $apiKey = null): array
    {
        // Check if any admin already exists (including inactive ones)
        if ($this->isAdminExists(true)) {
            abort(403, 'Admin already exists. Cannot create another admin. Use admin creation token or transfer role.');
        }

        $apiKey = $apiKey ?? $this->generateApiKey();
        $apiKeyHash = $this->hashApiKey($apiKey);

        return DB::transaction(function () use ($email, $name, $apiKey, $apiKeyHash) {
            $admin = User::create([
                'email' => strtolower($email),
                'name' => $name,
                'role' => 'admin',
                'api_key_hash' => $apiKeyHash,
                'is_active' => true,
            ]);

            // Log admin creation via audit worker pipeline
            $this->auditService->logAction(
                action: 'create_first_admin',
                actorRole: 'system',
                actorId: 'initial_setup',
                details: [
                    'admin_email' => $email,
                    'admin_name' => $name,
                    'method' => 'initial_setup',
                ]
            );

            return [$admin, $apiKey];
        });
    }

    /**
     * Create a new admin using a one-time creation token.
     * * @return array{0: User, 1: string}
     */
    public function createAdminWithToken(
        string $email, 
        string $name, 
        string $creationToken, 
        string $expectedToken
    ): array {
        // Constant-time secure string match comparison
        if (!hash_equals($expectedToken, $creationToken)) {
            abort(401, 'Invalid creation token');
        }

        return DB::transaction(function () use ($email, $name) {
            $currentAdmin = $this->getActiveAdmin();

            // Deactivate current active admin if one exists
            if ($currentAdmin) {
                $currentAdmin->update([
                    'is_active' => false,
                    'last_login' => Carbon::now('UTC')
                ]);
            }

            $apiKey = $this->generateApiKey();
            $apiKeyHash = $this->hashApiKey($apiKey);

            $newAdmin = User::create([
                'email' => strtolower($email),
                'name' => $name,
                'role' => 'admin',
                'api_key_hash' => $apiKeyHash,
                'is_active' => true,
            ]);

            $this->auditService->logAction(
                action: 'create_admin_with_token',
                actorRole: $currentAdmin ? 'admin' : 'system',
                actorId: $currentAdmin ? (string)$currentAdmin->id : 'token_creation',
                details: [
                    'new_admin_email' => $email,
                    'new_admin_name' => $name,
                    'previous_admin_deactivated' => $currentAdmin ? (string)$currentAdmin->id : null,
                    'method' => 'creation_token',
                ]
            );

            return [$newAdmin, $apiKey];
        });
    }

    /**
     * Create a new admin while being an existing admin.
     * * @return array{0: User, 1: string}
     */
    public function createAdminAsCurrentAdmin(
        string $email, 
        string $name, 
        string $currentAdminId, 
        bool $deactivateSelf = false
    ): array {
        // Verify caller credentials are valid and active
        $currentAdmin = User::where('id', $currentAdminId)
            ->where('role', 'admin')
            ->where('is_active', true)
            ->first();

        if (!$currentAdmin) {
            abort(403, 'Only active admins can create new admins');
        }

        return DB::transaction(function () use ($email, $name, $currentAdmin, $deactivateSelf) {
            $existingUser = User::where('email', strtolower($email))->first();
            $apiKey = $this->generateApiKey();
            $apiKeyHash = $this->hashApiKey($apiKey);

            if ($existingUser) {
                if ($existingUser->role === 'admin') {
                    abort(409, 'User is already an admin');
                }

                // Promote existing database entity profile user to admin status
                $existingUser->update([
                    'role' => 'admin',
                    'api_key_hash' => $apiKeyHash,
                    'is_active' => true
                ]);
                $newAdmin = $existingUser;
            } else {
                $newAdmin = User::create([
                    'email' => strtolower($email),
                    'name' => $name,
                    'role' => 'admin',
                    'api_key_hash' => $apiKeyHash,
                    'is_active' => true,
                ]);
            }

            if ($deactivateSelf) {
                $currentAdmin->update([
                    'is_active' => false,
                    'last_login' => Carbon::now('UTC')
                ]);
            }

            $this->auditService->logAction(
                action: 'create_admin_by_admin',
                actorRole: 'admin',
                actorId: (string)$currentAdmin->id,
                details: [
                    'new_admin_email' => $email,
                    'new_admin_name' => $name,
                    'current_admin_deactivated' => $deactivateSelf,
                    'was_existing_user' => $existingUser !== null,
                ]
            );

            return [$newAdmin, $apiKey];
        });
    }

    /**
     * EMERGENCY ONLY: Create an admin overriding state safety traps.
     * * @return array{0: User, 1: string}
     */
    public function createSuperAdminEmergency(string $email, string $name, string $emergencyCode, string $systemSecret): array
    {
        $expectedCode = config('app.emergency_admin_code', 'NOT_SET');
        $expectedSecret = config('app.system_secret', 'CHANGE_ME');

        if (!hash_equals($expectedCode, $emergencyCode) || !hash_equals($expectedSecret, $systemSecret)) {
            abort(401, 'Invalid security validation codes provided');
        }

        return DB::transaction(function () use ($email, $name) {
            // Force-deactivate all alternative historical admin entities down the pipe
            User::where('role', 'admin')->update([
                'is_active' => false,
                'last_login' => Carbon::now('UTC')
            ]);

            $apiKey = $this->generateApiKey();
            $apiKeyHash = $this->hashApiKey($apiKey);

            $emergencyAdmin = User::create([
                'email' => strtolower($email),
                'name' => $name,
                'role' => 'admin',
                'api_key_hash' => $apiKeyHash,
                'is_active' => true,
            ]);

            $this->auditService->logAction(
                action: 'emergency_admin_creation',
                actorRole: 'emergency_system',
                actorId: 'emergency_procedure',
                details: [
                    'admin_email' => $email,
                    'admin_name' => $name,
                    'previous_admins_deactivated' => true,
                ]
            );

            return [$emergencyAdmin, $apiKey];
        });
    }

    /**
     * Transfer admin role from current admin to another user.
     */
    public function transferAdminRole(string $currentAdminId, string $newAdminEmail, string $actorId): array
    {
        return DB::transaction(function () use ($currentAdminId, $newAdminEmail, $actorId) {
            $currentAdmin = User::where('id', $currentAdminId)
                ->where('role', 'admin')
                ->where('is_active', true)
                ->first();

            if (!$currentAdmin) {
                abort(404, 'Active admin not found');
            }

            $newAdmin = User::where('email', $newAdminEmail)->first();
            if (!$newAdmin) {
                abort(404, "User with email {$newAdminEmail} not found");
            }

            $currentAdmin->update(['is_active' => false]);
            $newAdmin->update([
                'role' => 'admin',
                'is_active' => true
            ]);

            $this->auditService->logAction(
                action: 'transfer_admin',
                actorRole: 'admin',
                actorId: $actorId,
                details: [
                    'from_admin' => (string)$currentAdmin->id,
                    'from_email' => $currentAdmin->email,
                    'to_admin' => (string)$newAdmin->id,
                    'to_email' => $newAdminEmail,
                ]
            );

            return [
                'message' => 'Admin role transferred successfully',
                'previous_admin_deactivated' => (string)$currentAdmin->id,
                'new_admin_activated' => (string)$newAdmin->id,
            ];
        });
    }

    /**
     * Deactivate an admin.
     */
    public function deactivateAdmin(string $adminId, string $actorId, bool $force = false): array
    {
        $admin = User::where('id', $adminId)->where('role', 'admin')->first();

        if (!$admin) {
            abort(404, 'Admin user not found');
        }

        if (!$admin->is_active) {
            abort(400, 'Admin is already deactivated');
        }

        // Aggregate count check constraints to avoid total administrative lockouts
        $otherActiveAdmins = User::where('role', 'admin')
            ->where('is_active', true)
            ->where('id', '!=', $adminId)
            ->get();

        if ($otherActiveAdmins->isEmpty() && !$force) {
            abort(400, 'Cannot deactivate the only active admin. Transfer role first or use force=true');
        }

        $admin->update(['is_active' => false]);

        $this->auditService->logAction(
            action: 'deactivate_admin',
            actorRole: 'admin',
            actorId: $actorId,
            details: ['deactivated_admin' => $adminId, 'force' => $force],
        );

        return [
            'message' => 'Admin deactivated successfully',
            'admin_id' => $adminId,
            'remaining_active_admins' => $otherActiveAdmins->count(),
        ];
    }

    /**
     * Get current global admin dashboard overview metadata tracking status.
     */
    public function getAdminStatus(): array
    {
        $activeAdmin = $this->getActiveAdmin();
        $allAdmins = User::where('role', 'admin')->get();

        return [
            'has_active_admin' => $activeAdmin !== null,
            'active_admin' => $activeAdmin ? [
                'id' => (string)$activeAdmin->id,
                'email' => $activeAdmin->email,
                'name' => $activeAdmin->name,
            ] : null,
            'total_admin_users' => $allAdmins->count(),
            'inactive_admins' => $allAdmins->where('is_active', false)->map(function ($admin) {
                return [
                    'id' => (string)$admin->id,
                    'email' => $admin->email,
                    'name' => $admin->name,
                    'deactivated_at' => $admin->last_login ? $admin->last_login->toIso8601String() : null,
                ];
            })->values()->all(),
        ];
    }

    /**
     * Generate a secure API key prefix pattern matching requirements.
     */
    protected function generateApiKey(): string
    {
        return 'cert_admin_' . bin2hex(random_bytes(32));
    }

    /**
     * Hash API key using SHA256 matches python's hexdigest string structure output.
     */
    function hashApiKey(string $apiKey): string
    {
        return hash('sha256', $apiKey);
    }

   
}