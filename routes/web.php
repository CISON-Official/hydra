<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\CertificateVerificationController;

// Front facing template actions
Route::get('/verify/{id}', [CertificateVerificationController::class, 'verifyAndRedirect']);

Route::get('/', function () {
    return view('welcome');
});
