<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ManagementDashboardController;
use App\Http\Controllers\ResidentPortalController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/login', function () {
    return view('welcome');
});

Route::get('/register', function () {
    return view('welcome');
});

Route::get('/admin/amenities', function () {
    return view('welcome');
});

Route::get('/admin/amenities/{any}', function () {
    return view('welcome');
})->where('any', '.*');

Route::get('/admin', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('welcome');
});

Route::get('/quan-ly', function () {
    return view('welcome');
});

Route::get('/manager', function () {
    return view('welcome');
});

Route::get('/cu-dan', function () {
    return view('welcome');
});

Route::get('/cu-dan/{any}', function () {
    return view('welcome');
})->where('any', '.*');

Route::get('/resident', function () {
    return view('welcome');
});

Route::get('/resident/{any}', function () {
    return view('welcome');
})->where('any', '.*');

Route::get('/api/management/overview', [ManagementDashboardController::class, 'overview']);

// API Resident Portal
Route::get('/api/v1/resident/overview', [ResidentPortalController::class, 'overview']);
Route::post('/api/v1/resident/tickets', [ResidentPortalController::class, 'createTicket']);
Route::post('/api/v1/resident/amenity-bookings', [ResidentPortalController::class, 'createAmenityBooking']);
Route::post('/api/v1/resident/visitors', [ResidentPortalController::class, 'createVisitor']);
Route::post('/api/v1/resident/invoices/{id}/pay', [ResidentPortalController::class, 'payInvoice']);

Route::post('/api/v1/auth/login', [AuthController::class, 'login']);
Route::post('/api/auth/login', [AuthController::class, 'login']);
Route::get('/api/v1/auth/me', [AuthController::class, 'me']);
Route::post('/api/v1/auth/logout', [AuthController::class, 'logout']);

Route::fallback(function () {
    return response()->view('errors.404', [], 404);
});
