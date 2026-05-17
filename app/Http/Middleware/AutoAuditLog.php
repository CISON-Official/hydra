<?php

namespace App\Http\Middleware;

use App\Services\AuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AutoAuditLog
{
    protected AuditService $auditService;

    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    public function handle(Request $request, Closure $next, string $action): Response
    {
        // 1. Process the inner request logic first to determine if it fails or succeeds
        $response = $next($request);

        // 2. Extract context dimensions from routing parameters and authenticated sessions
        $certificateId = $request->route('certificate_id') ?? $request->input('certificate_id');
        
        $user = $request->user();
        $actorRole = $user ? ($user->role ?? 'user') : 'guest';
        $actorId = $user ? (string)$user->id : null;

        // 3. Document the structural footprints in the background without lowering app execution speed
        $this->auditService->logAction(
            action: $action,
            actorRole: $actorRole,
            actorId: $actorId,
            certificateId: $certificateId,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            success: $response->isSuccessful(), // True for 2xx status profiles
            details: [
                'route' => $request->route()->getName(),
                'method' => $request->method()
            ],
            asyncMode: true
        );

        return $response;
    }
}