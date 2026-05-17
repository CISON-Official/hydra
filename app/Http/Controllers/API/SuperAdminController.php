<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\FirstAdminCreateRequest;
use App\Http\Requests\AdminCreateWithTokenRequest;
use App\Http\Requests\AdminCreateByAdminRequest;
use App\Http\Requests\EmergencyAdminCreateRequest;
use App\Services\AdminService;
use App\Services\AuditService;
use App\Mail\AdminCredentialsMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SuperAdminController extends Controller
{
    protected AdminService $adminService;
    protected AuditService $auditService;

    public function __construct(AdminService $adminService, AuditService $auditService)
    {
        $this->adminService = $adminService;
        $this->auditService = $auditService;
    }

    /**
     * Bootstrap setup operation to create the initial root admin user.
     */
    public function createFirstAdmin(FirstAdminCreateRequest $request): JsonResponse
    {
        $expectedSecret = config('auth.initial_admin_secret', env('INITIAL_ADMIN_SECRET'));

        // Guard against timing attacks using constant-time string analysis
        if (!hash_equals((string) $expectedSecret, $request->input('admin_secret'))) {
            throw new HttpException(401, "Invalid admin creation secret");
        }

        // Delegate transaction handling entirely to our underlying service domain
        [$admin, $apiKey] = $this->adminService->createFirstAdmin(
            $request->input('email'),
            $request->input('name')
        );

        // Queue background email delivery out-of-process natively
        Mail::to($admin->email)->queue(new AdminCredentialsMail($admin, $apiKey));

        return response()->json([
            'message' => 'First admin created successfully',
            'admin_id' => $admin->id,
            'email' => $admin->email,
            'api_key' => $apiKey,
            'warning' => 'Save this API key immediately. It will not be shown again.'
        ], 201);
    }

    /**
     * Delegation handover: Build an administrator using a one-time setup token.
     */
    public function createWithToken(AdminCreateWithTokenRequest $request): JsonResponse
    {
        // Pull token configuration directly from cache storage or fall back to application config registry
        $expectedToken = Cache::get('admin_creation_token:' . $request->input('email'))
            ?? config('auth.creation_token');

        if (!$expectedToken || !hash_equals((string) $expectedToken, $request->input('creation_token'))) {
            throw new HttpException(401, "Invalid or expired admin creation token");
        }

        [$admin, $apiKey] = $this->adminService->createAdminWithToken(
            $request->input('email'),
            $request->input('name')
        );

        Cache::forget('admin_creation_token:' . $request->input('email'));
        Mail::to($admin->email)->queue(new AdminCredentialsMail($admin, $apiKey));

        return response()->json([
            'message' => 'Admin created successfully. Previous admin deactivated if existed.',
            'admin_id' => $admin->id,
            'email' => $admin->email,
            'api_key' => $apiKey,
            'warning' => 'Save this API key. Previous admin API keys are now invalid.'
        ], 201);
    }

    /**
     * Peer Elevation: Issue new admin credentials using an active authenticated session context.
     */
    public function createByAdmin(AdminCreateByAdminRequest $request): JsonResponse
    {
        // Retrieve the current user record context mapped by the upstream route authentication layer
        $currentUser = $request->user();

        [$admin, $apiKey] = $this->adminService->createAdminAsCurrentAdmin(
            $request->input('email'),
            $request->input('name'),
            $currentUser->id,
            $request->input('deactivate_self')
        );

        $message = "New admin created successfully.";
        if ($request->input('deactivate_self')) {
            $message .= " Your admin privileges have been deactivated.";
        }

        Mail::to($admin->email)->queue(new AdminCredentialsMail($admin, $apiKey));

        return response()->json([
            'message' => $message,
            'admin_id' => $admin->id,
            'email' => $admin->email,
            'api_key' => $apiKey,
            'warning' => 'Save this API key. Share it securely with the new admin.'
        ], 201);
    }

    /**
     * Panic-Button Reset: Immediate automated deactivation override protocol.
     */
    public function emergencyCreate(EmergencyAdminCreateRequest $request): JsonResponse
    {
        [$admin, $apiKey] = $this->adminService->createSuperAdminEmergency(
            $request->input('email'),
            $request->input('name'),
            $request->input('emergency_code'),
            $request->input('system_secret')
        );

        Mail::to($admin->email)->queue(new AdminCredentialsMail($admin, $apiKey));

        return response()->json([
            'message' => 'EMERGENCY: Admin created. All previous admins have been deactivated.',
            'admin_id' => $admin->id,
            'email' => $admin->email,
            'api_key' => $apiKey,
            'warning' => 'CRITICAL: Secure this API key immediately. Previous admins have been locked out.'
        ], 201);
    }

    /**
     * Handover preparation: Generate a short-lived token to initialize secondary administrators.
     */
    public function generateCreationToken(\Illuminate\Http\Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);
        $targetEmail = $request->input('email');

        $token = Str::random(64);
        $ttlSeconds = 3600;

        // Persist token target variables down to the active Redis engine cluster cache layer
        Cache::put('admin_creation_token:' . $targetEmail, $token, $ttlSeconds);

        $this->auditService->logAction(
            action: 'generate_admin_creation_token',
            actorRole: 'admin',
            actorId: $request->user()->id,
            certificateId: null,
            details: ['target_email' => $targetEmail]
        );

        return response()->json([
            'message' => 'Creation token generated',
            'token' => $token,
            'expires_in_seconds' => $ttlSeconds,
            'usage' => 'Use this token with POST /api/v1/superadmin/create-with-token'
        ], 200);
    }
}