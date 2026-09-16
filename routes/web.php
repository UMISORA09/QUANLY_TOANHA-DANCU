<?php

use App\Http\Controllers\AmenityController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ManagementDashboardController;
use App\Http\Controllers\ResidentPortalController;
use App\Http\Controllers\SearchController;
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

Route::get('/dang-nhap', function () {
    return view('welcome');
});

Route::get('/dang-ky', function () {
    return view('welcome');
});

Route::get('/admin', function () {
    return view('welcome');
});

Route::get('/admin/{any}', function () {
    return view('welcome');
})->where('any', '.*');

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

// API Management Dashboard
Route::get('/api/management/overview', [ManagementDashboardController::class, 'overview']);

// API Resident Portal
Route::get('/api/v1/resident/overview', [ResidentPortalController::class, 'overview']);
Route::post('/api/v1/resident/tickets', [ResidentPortalController::class, 'createTicket']);
Route::post('/api/v1/resident/amenity-bookings', [ResidentPortalController::class, 'createAmenityBooking']);
Route::post('/api/v1/resident/visitors', [ResidentPortalController::class, 'createVisitor']);
Route::post('/api/v1/resident/invoices/{id}/pay', [ResidentPortalController::class, 'payInvoice']);

// API Auth
Route::post('/api/v1/auth/login', [AuthController::class, 'login']);
Route::post('/api/auth/login', [AuthController::class, 'login']);
Route::get('/api/v1/auth/me', [AuthController::class, 'me']);
Route::post('/api/v1/auth/logout', [AuthController::class, 'logout']);

// Meta endpoints
Route::get('/api/v1/meta/blocks', [AmenityController::class, 'getBlocks']);
Route::get('/meta/blocks', [AmenityController::class, 'getBlocks']);

// Smart Search Engine APIs (Full-Text, Autocomplete & AI Knowledge Hybrid Search)
Route::get('/api/amenities/search', [SearchController::class, 'searchAmenities']);
Route::get('/api/v1/amenities/search', [SearchController::class, 'searchAmenities']);
Route::get('/api/v1/search/suggestions', [SearchController::class, 'suggestions']);
Route::get('/api/v1/search/ai-knowledge', [SearchController::class, 'aiKnowledge']);

// Phân hệ Quản lý tiện ích & Cấu hình Slot
Route::prefix('api/v1/admin')->group(function () {
    // Tòa nhà / Blocks
    Route::get('blocks', [AmenityController::class, 'getBlocks']);

    // Danh mục tiện ích
    Route::get('amenity-categories', [AmenityController::class, 'getCategories']);
    Route::post('amenity-categories', [AmenityController::class, 'createCategory']);
    Route::put('amenity-categories/{id}', [AmenityController::class, 'updateCategory']);
    Route::delete('amenity-categories/{id}', [AmenityController::class, 'deleteCategory']);

    // Tiện ích
    Route::get('amenities', [AmenityController::class, 'getAmenities']);
    Route::post('amenities', [AmenityController::class, 'createAmenity']);
    Route::get('amenities/{id}', [AmenityController::class, 'getAmenity']);
    Route::put('amenities/{id}', [AmenityController::class, 'updateAmenity']);
    Route::patch('amenities/{id}/status', [AmenityController::class, 'toggleAmenityStatus']);
    Route::delete('amenities/{id}', [AmenityController::class, 'deleteAmenity']);
    Route::get('amenities/{id}/bookings', [AmenityController::class, 'getAmenityBookings']);
    Route::patch('amenities/{amenityId}/bookings/{bookingId}/status', [AmenityController::class, 'updateBookingStatus']);
    Route::post('amenities/{amenityId}/bookings/{bookingId}/cancel', [AmenityController::class, 'cancelBooking']);

    // Khung giờ hoạt động (Time Slots)
    Route::get('amenities/{amenityId}/time-slots', [AmenityController::class, 'getTimeSlots']);
    Route::post('amenities/{amenityId}/time-slots', [AmenityController::class, 'createTimeSlot']);
    Route::put('amenities/{amenityId}/time-slots/{slotId}', [AmenityController::class, 'updateTimeSlot']);
    Route::patch('amenities/{amenityId}/time-slots/{slotId}/status', [AmenityController::class, 'toggleTimeSlotStatus']);
    Route::delete('amenities/{amenityId}/time-slots/{slotId}', [AmenityController::class, 'deleteTimeSlot']);

    // Ngày đóng cửa bảo trì (Blackouts)
    Route::get('amenities/{amenityId}/blackouts', [AmenityController::class, 'getBlackouts']);
    Route::post('amenities/{amenityId}/blackouts', [AmenityController::class, 'createBlackout']);
    Route::put('amenities/{amenityId}/blackouts/{blackoutId}', [AmenityController::class, 'updateBlackout']);
    Route::delete('amenities/{amenityId}/blackouts/{blackoutId}', [AmenityController::class, 'deleteBlackout']);
});

// Đặt chỗ tiện ích (Booking Enforcement)
Route::post('/api/v1/amenities/{id}/bookings', [AmenityController::class, 'bookAmenity']);
Route::post('/api/v1/amenities/{amenityId}/bookings/{bookingId}/cancel', [AmenityController::class, 'cancelBooking']);

Route::fallback(function () {
    return response()->view('errors.404', [], 404);
});
