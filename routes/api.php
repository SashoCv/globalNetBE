<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContactMessageController;
use App\Http\Controllers\Api\GalleryController;
use App\Http\Controllers\Api\GalleryCategoryController;
use App\Http\Controllers\Api\HcBookingController;
use App\Http\Controllers\Api\HcClinicController;
use App\Http\Controllers\Api\HcHospitalController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\EventSessionTypeController;
use App\Http\Controllers\Api\EventKotizacijaController;
use App\Http\Controllers\Api\EventRegistrationController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\EvaluationController;
use App\Http\Controllers\Api\PresentationController;
use App\Http\Controllers\Api\PublicEvaluationController;
use App\Http\Controllers\Api\PublicPresentationController;
use App\Http\Controllers\Api\PublicEventRegistrationController;
use App\Http\Controllers\Api\ShopVendorController;
use App\Http\Controllers\Api\ShopCategoryController;
use App\Http\Controllers\Api\ShopProductController;
use App\Http\Controllers\Api\ShopClinicController;
use App\Http\Controllers\Api\PublicShopController;
use App\Http\Controllers\Api\ClinicAuthController;
use App\Http\Controllers\Api\ShopOrderModelController;
use App\Http\Controllers\Api\ShopOrderController;
use App\Http\Controllers\Api\ClinicOrderController;
use App\Http\Controllers\Api\ClinicWalletController;
use App\Http\Controllers\Api\ClinicOrderRequestController;
use App\Http\Controllers\Api\ShopOrderRequestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes (no auth required)
|--------------------------------------------------------------------------
*/

Route::post('/login', [AuthController::class, 'login']);

Route::post('/contact', [ContactMessageController::class, 'submit']);

Route::get('/services/public', [ServiceController::class, 'public']);
Route::get('/settings/public/{group}', [SettingController::class, 'public']);
Route::get('/gallery/public', [GalleryController::class, 'public']);
Route::get('/gallery/home-images', [GalleryController::class, 'homeImages']);
Route::get('/gallery/hero-images', [GalleryController::class, 'heroImages']);
Route::get('/gallery-categories/public', [GalleryCategoryController::class, 'public']);

Route::post('/hc-bookings/submit', [HcBookingController::class, 'submit']);
Route::get('/hc-clinics/public', [HcClinicController::class, 'public']);
Route::get('/hc-hospitals/public', [HcHospitalController::class, 'public']);

// Attendance (public QR form)
Route::get('/attendance/{qrToken}', [AttendanceController::class, 'show']);
Route::post('/attendance/{qrToken}', [AttendanceController::class, 'store']);

// Evaluations (public QR form)
Route::get('/evaluation/{qrToken}', [PublicEvaluationController::class, 'show']);
Route::post('/evaluation/{qrToken}', [PublicEvaluationController::class, 'submit']);

// Presentations (public landing page)
Route::get('/presentation/{qrToken}', [PublicPresentationController::class, 'show']);

// Event registration (public QR form)
Route::get('/event-registration/{qrToken}', [PublicEventRegistrationController::class, 'show']);
Route::post('/event-registration/{qrToken}', [PublicEventRegistrationController::class, 'store']);

// E-Shop clinic registration (public)
Route::post('/public/shop-clinics', [ShopClinicController::class, 'publicStore']);

// Clinic auth (public)
Route::post('/clinic/register', [ClinicAuthController::class, 'register']);
Route::post('/clinic/login', [ClinicAuthController::class, 'login']);
Route::post('/clinic/forgot-password', [ClinicAuthController::class, 'forgotPassword']);
Route::post('/clinic/reset-password', [ClinicAuthController::class, 'resetPassword']);

// Clinic auth + orders (token-protected, clinic users only)
Route::middleware(['auth:sanctum', \App\Http\Middleware\EnsureClinicAuth::class])->group(function () {
    Route::get('/clinic/me', [ClinicAuthController::class, 'me']);
    Route::post('/clinic/logout', [ClinicAuthController::class, 'logout']);

    // Clinic orders
    Route::post('/clinic/orders', [ClinicOrderController::class, 'store']);
    Route::get('/clinic/orders', [ClinicOrderController::class, 'index']);
    Route::get('/clinic/orders/{id}', [ClinicOrderController::class, 'show']);
    Route::patch('/clinic/orders/{id}/cancel', [ClinicOrderController::class, 'cancel']);

    // Clinic order requests (complaints / special requests)
    Route::post('/clinic/requests', [ClinicOrderRequestController::class, 'store']);
    Route::get('/clinic/requests', [ClinicOrderRequestController::class, 'index']);
    Route::get('/clinic/requests/{id}', [ClinicOrderRequestController::class, 'show']);

    // Clinic wallet
    Route::get('/clinic/wallet', [ClinicWalletController::class, 'index']);
});

// E-Shop public catalog
Route::get('/public/shop-categories', [PublicShopController::class, 'categories']);
Route::get('/public/shop-vendors', [PublicShopController::class, 'vendors']);
Route::post('/public/shop-vendors', [PublicShopController::class, 'storeVendor']);
Route::get('/public/shop-vendors/{slug}', [PublicShopController::class, 'vendor']);
Route::get('/public/shop-products', [PublicShopController::class, 'products']);
Route::get('/public/shop-products/{slug}', [PublicShopController::class, 'product']);

// E-Shop public order models
Route::get('/public/shop-order-models', [ShopOrderModelController::class, 'publicIndex']);

/*
|--------------------------------------------------------------------------
| Authenticated Routes (auth:sanctum)
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // Contact Messages
    Route::get('/messages', [ContactMessageController::class, 'index']);
    Route::get('/messages/{id}', [ContactMessageController::class, 'show']);
    Route::put('/messages/{id}/status', [ContactMessageController::class, 'updateStatus']);
    Route::delete('/messages/{id}', [ContactMessageController::class, 'destroy']);

    Route::get('/gallery/hero-selection', [GalleryController::class, 'heroSelection']);

    // Gallery Categories (admin)
    Route::get('/gallery-categories', [GalleryCategoryController::class, 'index']);
    Route::post('/gallery-categories', [GalleryCategoryController::class, 'store']);
    Route::put('/gallery-categories/{id}', [GalleryCategoryController::class, 'update']);
    Route::delete('/gallery-categories/{id}', [GalleryCategoryController::class, 'destroy']);

    // Gallery Events & Images
    Route::get('/gallery-events', [GalleryController::class, 'index']);
    Route::post('/gallery-events', [GalleryController::class, 'store']);
    Route::put('/gallery-events/{id}', [GalleryController::class, 'update']);
    Route::delete('/gallery-events/{id}', [GalleryController::class, 'destroy']);
    Route::post('/gallery-events/{id}/images', [GalleryController::class, 'uploadImages']);
    Route::delete('/gallery-images/{id}', [GalleryController::class, 'destroyImage']);
    Route::put('/gallery-images/{id}/cover', [GalleryController::class, 'setCover']);
    Route::put('/gallery-images/{id}/home', [GalleryController::class, 'toggleHome']);
    Route::put('/gallery-images/{id}/hero', [GalleryController::class, 'toggleHero']);
    Route::put('/gallery-images/{id}/move', [GalleryController::class, 'moveImage']);

    // Services
    Route::get('/services', [ServiceController::class, 'index']);
    Route::post('/services', [ServiceController::class, 'store']);
    Route::put('/services/{id}', [ServiceController::class, 'update']);
    Route::delete('/services/{id}', [ServiceController::class, 'destroy']);

    // Generic image upload (admin image pickers)
    Route::post('/uploads', [\App\Http\Controllers\Api\UploadController::class, 'store']);

    // Settings
    Route::get('/settings', [SettingController::class, 'index']);
    Route::put('/settings', [SettingController::class, 'update']);

    // Healthcare Bookings
    Route::get('/hc-bookings', [HcBookingController::class, 'index']);
    Route::get('/hc-bookings/{id}', [HcBookingController::class, 'show']);
    Route::put('/hc-bookings/{id}/status', [HcBookingController::class, 'updateStatus']);
    Route::delete('/hc-bookings/{id}', [HcBookingController::class, 'destroy']);

    // Healthcare Clinics
    Route::get('/hc-clinics', [HcClinicController::class, 'index']);
    Route::post('/hc-clinics', [HcClinicController::class, 'store']);
    Route::put('/hc-clinics/{id}', [HcClinicController::class, 'update']);
    Route::delete('/hc-clinics/{id}', [HcClinicController::class, 'destroy']);

    // Healthcare Hospitals
    Route::get('/hc-hospitals', [HcHospitalController::class, 'index']);
    Route::post('/hc-hospitals', [HcHospitalController::class, 'store']);
    Route::put('/hc-hospitals/{id}', [HcHospitalController::class, 'update']);
    Route::delete('/hc-hospitals/{id}', [HcHospitalController::class, 'destroy']);

    // Events & Attendance
    Route::get('/events', [EventController::class, 'index']);
    Route::post('/events', [EventController::class, 'store']);
    Route::put('/events/{id}', [EventController::class, 'update']);
    Route::delete('/events/{id}', [EventController::class, 'destroy']);
    Route::get('/events/{id}/sessions', [EventController::class, 'sessions']);
    Route::post('/events/{id}/sessions', [EventController::class, 'storeSession']);
    Route::put('/event-sessions/{id}', [EventController::class, 'updateSession']);
    Route::delete('/event-sessions/{id}', [EventController::class, 'destroySession']);
    Route::get('/events/{id}/stats', [EventController::class, 'stats']);
    Route::get('/event-sessions/{id}/attendees', [EventController::class, 'sessionAttendees']);

    // Event Kotizacii (admin)
    Route::get('/events/{id}/kotizacii', [EventKotizacijaController::class, 'index']);
    Route::post('/events/{id}/kotizacii', [EventKotizacijaController::class, 'store']);
    Route::put('/event-kotizacii/{id}', [EventKotizacijaController::class, 'update']);
    Route::delete('/event-kotizacii/{id}', [EventKotizacijaController::class, 'destroy']);

    // Event Registrations (admin)
    Route::get('/events/{id}/registrations', [EventRegistrationController::class, 'index']);
    Route::get('/events/{id}/registrations/export', [EventRegistrationController::class, 'export']);
    Route::delete('/event-registrations/{id}', [EventRegistrationController::class, 'destroy']);

    // Event Session Types (categories)
    Route::get('/event-session-types', [EventSessionTypeController::class, 'index']);
    Route::post('/event-session-types', [EventSessionTypeController::class, 'store']);
    Route::put('/event-session-types/{id}', [EventSessionTypeController::class, 'update']);
    Route::delete('/event-session-types/{id}', [EventSessionTypeController::class, 'destroy']);

    // Evaluations (admin)
    Route::get('/event-sessions/{id}/evaluations', [EvaluationController::class, 'index']);
    Route::post('/event-sessions/{id}/evaluations', [EvaluationController::class, 'store']);
    Route::put('/evaluations/{id}', [EvaluationController::class, 'update']);
    Route::delete('/evaluations/{id}', [EvaluationController::class, 'destroy']);
    Route::get('/evaluations/{id}/questions', [EvaluationController::class, 'questions']);
    Route::post('/evaluations/{id}/questions', [EvaluationController::class, 'storeQuestion']);
    Route::put('/evaluation-questions/{id}', [EvaluationController::class, 'updateQuestion']);
    Route::delete('/evaluation-questions/{id}', [EvaluationController::class, 'destroyQuestion']);
    Route::get('/evaluations/{id}/responses', [EvaluationController::class, 'responses']);
    Route::get('/evaluations/{id}/stats', [EvaluationController::class, 'stats']);
    Route::get('/evaluations/{id}/report.pdf', [EvaluationController::class, 'reportPdf']);

    // Presentations (admin)
    Route::get('/event-sessions/{id}/presentations', [PresentationController::class, 'index']);
    Route::post('/event-sessions/{id}/presentations', [PresentationController::class, 'store']);
    Route::put('/presentations/{id}', [PresentationController::class, 'update']);
    Route::delete('/presentations/{id}', [PresentationController::class, 'destroy']);
    Route::post('/presentations/{id}/upload-image', [PresentationController::class, 'uploadImage']);

    // E-Shop — Vendors (admin)
    Route::get('/shop-vendors', [ShopVendorController::class, 'index']);
    Route::post('/shop-vendors', [ShopVendorController::class, 'store']);
    Route::post('/shop-vendors/reorder', [ShopVendorController::class, 'reorder']);
    Route::get('/shop-vendors/{id}', [ShopVendorController::class, 'show']);
    Route::put('/shop-vendors/{id}', [ShopVendorController::class, 'update']);
    Route::delete('/shop-vendors/{id}', [ShopVendorController::class, 'destroy']);

    // E-Shop — Categories (admin)
    Route::get('/shop-categories', [ShopCategoryController::class, 'index']);
    Route::post('/shop-categories', [ShopCategoryController::class, 'store']);
    Route::put('/shop-categories/{id}', [ShopCategoryController::class, 'update']);
    Route::delete('/shop-categories/{id}', [ShopCategoryController::class, 'destroy']);

    // E-Shop — Order models (admin)
    Route::get('/shop-order-models', [ShopOrderModelController::class, 'index']);
    Route::post('/shop-order-models', [ShopOrderModelController::class, 'store']);
    Route::put('/shop-order-models/{id}', [ShopOrderModelController::class, 'update']);
    Route::delete('/shop-order-models/{id}', [ShopOrderModelController::class, 'destroy']);

    // E-Shop — Orders (admin)
    Route::get('/shop-orders', [ShopOrderController::class, 'index']);
    Route::get('/shop-orders/stats', [ShopOrderController::class, 'stats']);
    Route::get('/shop-orders/dashboard', [ShopOrderController::class, 'dashboard']);
    Route::get('/shop-orders/procurement', [ShopOrderController::class, 'procurement']);
    Route::post('/shop-orders/procurement/dispatch', [ShopOrderController::class, 'dispatch']);
    Route::post('/shop-orders/procurement/dispatch-courier', [ShopOrderController::class, 'dispatchCourier']);
    Route::post('/shop-orders/calculate-rebates', [ShopOrderController::class, 'calculateRebates']);
    Route::get('/shop-orders/{id}', [ShopOrderController::class, 'show']);
    Route::post('/shop-orders/{id}/dispatch-to-vendors', [ShopOrderController::class, 'dispatchOrder']);
    Route::patch('/shop-orders/{id}/status', [ShopOrderController::class, 'updateStatus']);
    Route::put('/shop-orders/{id}/items', [ShopOrderController::class, 'updateItems']);
    Route::patch('/shop-orders/{id}/mark-paid', [ShopOrderController::class, 'markPaid']);
    Route::delete('/shop-orders/{id}', [ShopOrderController::class, 'destroy']);

    // E-Shop — Order requests / complaints (admin)
    Route::get('/shop-order-requests', [ShopOrderRequestController::class, 'index']);
    Route::get('/shop-order-requests/stats', [ShopOrderRequestController::class, 'stats']);
    Route::get('/shop-order-requests/{id}', [ShopOrderRequestController::class, 'show']);
    Route::patch('/shop-order-requests/{id}/status', [ShopOrderRequestController::class, 'updateStatus']);

    // E-Shop — Clinics (admin)
    Route::get('/shop-clinics', [ShopClinicController::class, 'index']);
    Route::get('/shop-clinics/stats', [ShopClinicController::class, 'stats']);
    Route::post('/shop-clinics/bulk-approve', [ShopClinicController::class, 'bulkApprove']);
    Route::post('/shop-clinics/bulk-reject', [ShopClinicController::class, 'bulkReject']);
    Route::get('/shop-clinics/{id}', [ShopClinicController::class, 'show']);
    Route::get('/shop-clinics/{id}/document', [ShopClinicController::class, 'document']);
    Route::post('/shop-clinics', [ShopClinicController::class, 'store']);
    Route::put('/shop-clinics/{id}', [ShopClinicController::class, 'update']);
    Route::delete('/shop-clinics/{id}', [ShopClinicController::class, 'destroy']);
    Route::post('/shop-clinics/{id}/approve', [ShopClinicController::class, 'approve']);
    Route::post('/shop-clinics/{id}/reject', [ShopClinicController::class, 'reject']);
    Route::post('/shop-clinics/{id}/wallet/credit', [ShopClinicController::class, 'walletCredit']);
    Route::get('/shop-clinics/{id}/wallet', [ShopClinicController::class, 'walletHistory']);

    // E-Shop — Products (admin)
    Route::get('/shop-products', [ShopProductController::class, 'index']);
    Route::get('/shop-products/{id}', [ShopProductController::class, 'show']);
    Route::get('/shop-vendors/{vendor}/products', [ShopProductController::class, 'indexForVendor']);
    Route::post('/shop-vendors/{vendor}/products', [ShopProductController::class, 'store']);
    Route::put('/shop-products/{id}', [ShopProductController::class, 'update']);
    Route::delete('/shop-products/{id}', [ShopProductController::class, 'destroy']);
});
