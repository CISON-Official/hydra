<?php
use Illuminate\Support\Facades\Route;
// use App\Http\Middleware\AutoAuditLog;
use App\Http\Controllers\API\SuperAdminController;
// use App\Http\Controllers\API\CertificateController;
use App\Http\Controllers\API\HolderOperationsController;
use App\Http\Controllers\API\CertificateVerificationController;
use App\Http\Controllers\API\CertificateAdministrationController;
// use Illuminate\Routing\Controllers\Middleware;


Route::prefix('v1')->group(function () {
    Route::get('/certificates/{id}/info', [CertificateVerificationController::class, 'getCertificateInfo']);
    Route::get('/certificates/{id}/stream', [CertificateVerificationController::class, 'streamCertificate']);
    Route::get('/certificates/{id}/download', [CertificateVerificationController::class, 'downloadCertificate']);
});

Route::prefix('v1/superadmin')->group(function () {

    // Public Unauthenticated Handlers (Protected via out-of-band secrets)
    Route::post('/setup/first', [SuperAdminController::class, 'createFirstAdmin']);
    Route::post('/create-with-token', [SuperAdminController::class, 'createWithToken']);
    Route::post('/emergency-create', [SuperAdminController::class, 'emergencyCreate']);

    // Authenticated Operations Layer (Enforced via Sanctum and Role Authorization Middlewares)
    Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
        Route::post('/create-by-admin', [SuperAdminController::class, 'createByAdmin']);
        Route::post('/generate-creation-token', [SuperAdminController::class, 'generateCreationToken']);
    });
});

Route::middleware(['api.key', 'role:issuer,admin'])->prefix('v1/certificates')->group(function () {

    // Core Administrative Operations (Issuers and Admins only)
    Route::middleware(['role:issuer,admin'])->group(function () {
        Route::post('/upload', [CertificateAdministrationController::class, 'store']);
        Route::get('/', [CertificateAdministrationController::class, 'index']);
        Route::post('/{certificate_id}/revoke', [CertificateAdministrationController::class, 'revoke']);
        Route::post('/{certificate_id}/rotate-qr', [CertificateAdministrationController::class, 'rotateQr']);
        Route::put('/{certificate_id}/file', [CertificateAdministrationController::class, 'updateFile']);
        Route::post('/{certificate_id}/rollback/{version}', [CertificateAdministrationController::class, 'rollback']);
        Route::get('/{certificate_id}/compare', [CertificateAdministrationController::class, 'compare']);
        Route::post('/{certificate_id}/extend', [CertificateAdministrationController::class, 'extend']);
    });

    // Broad Shared Read Access Operations (Issuers, Admins, and Verifiers)
    Route::middleware(['role:issuer,admin,verifier'])->group(function () {
        Route::get('/{certificate_id}', [CertificateAdministrationController::class, 'show']);
        Route::get('/{certificate_id}/versions', [CertificateAdministrationController::class, 'versions']);
    });

    // Asset Access Permissions (Allows structural download streams for Holders too)
    Route::middleware(['role:issuer,admin,holder'])->group(function () {
        Route::get('/{certificate_id}/download/version/{version}', [CertificateAdministrationController::class, 'downloadVersion']);
    });
});

Route::get('/v1/verify/{certificate_id}', [CertificateVerificationController::class, 'verify'])
    ->name('certificates.verify');


// Route::post('/v1/certificates/{certificate_id}/verify', [CertificateController::class, 'verify'])
// ->middleware(AutoAuditLog::class . ':verify');

// Route::delete('/v1/certificates/{certificate_id}/revoke', [CertificateController::class, 'revoke'])
// ->middleware(AutoAuditLog::class . ':revoke');
Route::prefix('v1/holder')
    // ->withMiddleware(function (Middleware $middleware) {
//     // Prevent web redirection for unauthenticated API requests
//     $middleware->redirectGuestsTo(fn () => response()->json(['message' => 'Unauthenticated.'], 401));
// })
    ->group(function () {
        Route::get('/certificates', [HolderOperationsController::class, 'index']);
        Route::get('/certificates/{id}/qr', [HolderOperationsController::class, 'downloadQr']);
        Route::get('/certificates/{id}/status', [HolderOperationsController::class, 'status']);
        Route::post('/certificates/{id}/request-renewal', [HolderOperationsController::class, 'requestRenewal']);
    });

