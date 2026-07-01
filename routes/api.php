<?php

use App\Http\Controllers\Api\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\RegistrationController;
use App\Http\Controllers\Api\DeliveryController;
use App\Http\Controllers\Api\Driver\DocumentController;
use App\Http\Controllers\Api\Driver\DriverController;
use App\Http\Controllers\Api\EarningsController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| PUBLIC ROUTES
|--------------------------------------------------------------------------
*/

// WhatsApp Webhook
Route::get('/whatsapp/webhook', [WhatsAppWebhookController::class, 'verify']);
Route::post('/whatsapp/webhook', [WhatsAppWebhookController::class, 'receive']);
Route::post('/whatsapp/test-send', [WhatsAppWebhookController::class, 'testSend']);

// ── TRACKING GPS PÚBLICO ────────────────────────────────────────────────────
// El conductor envía su ubicación cada 3s (no requiere auth, solo el token del viaje)
Route::post('/track/{token}/location', [\App\Http\Controllers\TrackingController::class, 'updateLocation'])
    ->name('api.tracking.location');

// Auth
Route::prefix('auth')->group(function () {

    Route::post('/register/personal', [RegistrationController::class, 'registerPersonalInfo']);

    Route::post('/register/vehicle', [RegistrationController::class, 'registerVehicleInfo'])
        ->middleware('auth:sanctum');

    Route::post('/register/documents', [RegistrationController::class, 'uploadDocuments'])
        ->middleware('auth:sanctum');

    Route::get('/register/status/{driverId}', [RegistrationController::class, 'checkStatus']);

    Route::post('/login', [LoginController::class, 'login']);

    Route::post('/forgot-password/send-otp', [ForgotPasswordController::class, 'sendOtp']);
    Route::post('/forgot-password/verify-otp', [ForgotPasswordController::class, 'verifyOtp']);
    Route::post('/forgot-password/reset', [ForgotPasswordController::class, 'resetPassword']);
    Route::post('/forgot-password/resend-otp', [ForgotPasswordController::class, 'resendOtp']);
});

/*
|--------------------------------------------------------------------------
| PROTECTED ROUTES (SANCTUM)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'driver.active'])->group(function () {

    // Auth
    Route::post('/auth/logout', [LoginController::class, 'logout']);
    Route::post('/auth/refresh', [LoginController::class, 'refreshToken']);

    // ================= DRIVER =================
    Route::get('/driver/profile', [DriverController::class, 'profile']);
    Route::match(['put', 'post'], '/driver/profile', [DriverController::class, 'updateProfile']);

    Route::post('/driver/availability', [DriverController::class, 'setAvailability']);
    Route::post('/driver/online', [DriverController::class, 'goOnline']);
    Route::post('/driver/offline', [DriverController::class, 'goOffline']);
    Route::get('/driver/status', [DriverController::class, 'status']);

    Route::put('/driver/fcm-token', [DriverController::class, 'updateFcmToken']);
    Route::post('/driver/location', [DriverController::class, 'updateLocation']);
    Route::get('/driver/location', [DriverController::class, 'getLocation']);
    Route::get('/driver/stats', [DriverController::class, 'stats']);

    // ================= DOCUMENTS =================
    Route::get('/driver/documents', [DocumentController::class, 'index']);
    Route::post('/driver/documents', [DocumentController::class, 'upload']);
    Route::delete('/driver/documents/{id}', [DocumentController::class, 'destroy']);

    // ================= DELIVERIES =================
    Route::get('/deliveries/available', [DeliveryController::class, 'available']);
    Route::get('/deliveries/active', [DeliveryController::class, 'active']);
    Route::get('/deliveries/history', [DeliveryController::class, 'history']);

    Route::post('/deliveries/{trip}/accept', [DeliveryController::class, 'accept']);
    Route::post('/deliveries/{trip}/reject', [DeliveryController::class, 'reject']);
    Route::post('/deliveries/{trip}/status', [DeliveryController::class, 'updateStatus']);
    Route::post('/deliveries/{trip}/complete', [DeliveryController::class, 'completeWithPod']);
    Route::post('/deliveries/{trip}/complete-simple', [DeliveryController::class, 'completeSimple']);
    Route::get('/deliveries/{trip}/pod', [DeliveryController::class, 'getPod']);

    // ================= WALLET =================
    Route::get('/driver/wallet', [WalletController::class, 'show']);
    Route::get('/driver/wallet/transactions', [WalletController::class, 'transactions']);
    Route::post('/driver/wallet/topup-request', [WalletController::class, 'requestTopUp']);

    // ================= EARNINGS =================
    Route::get('/driver/earnings', [EarningsController::class, 'summary']);
    Route::get('/driver/earnings/daily', [EarningsController::class, 'dailyBreakdown']);
    Route::get('/driver/earnings/transactions', [EarningsController::class, 'transactions']);

    // ================= DASHBOARD =================
    Route::get('/driver/dashboard', [DriverController::class, 'dashboard']);

    // ================= BROADCAST AUTH =================
    Route::post('/broadcasting/auth', function (Request $request) {
        // Ensure the request has the authenticated user from Sanctum
        $request->setUserResolver(fn() => Auth::guard('sanctum')->user() ?? Auth::user());
        return Broadcast::auth($request);
    });
});