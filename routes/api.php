<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContactMessageController;
use App\Http\Controllers\Api\GalleryController;
use App\Http\Controllers\Api\HcBookingController;
use App\Http\Controllers\Api\HcClinicController;
use App\Http\Controllers\Api\HcHospitalController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\AttendanceController;
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

Route::post('/hc-bookings/submit', [HcBookingController::class, 'submit']);
Route::get('/hc-clinics/public', [HcClinicController::class, 'public']);
Route::get('/hc-hospitals/public', [HcHospitalController::class, 'public']);

// Attendance (public QR form)
Route::get('/attendance/{qrToken}', [AttendanceController::class, 'show']);
Route::post('/attendance/{qrToken}', [AttendanceController::class, 'store']);

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

    // Gallery Events & Images
    Route::get('/gallery-events', [GalleryController::class, 'index']);
    Route::post('/gallery-events', [GalleryController::class, 'store']);
    Route::put('/gallery-events/{id}', [GalleryController::class, 'update']);
    Route::delete('/gallery-events/{id}', [GalleryController::class, 'destroy']);
    Route::post('/gallery-events/{id}/images', [GalleryController::class, 'uploadImages']);
    Route::delete('/gallery-images/{id}', [GalleryController::class, 'destroyImage']);
    Route::put('/gallery-images/{id}/cover', [GalleryController::class, 'setCover']);

    // Services
    Route::get('/services', [ServiceController::class, 'index']);
    Route::post('/services', [ServiceController::class, 'store']);
    Route::put('/services/{id}', [ServiceController::class, 'update']);
    Route::delete('/services/{id}', [ServiceController::class, 'destroy']);

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
});
