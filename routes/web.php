<?php

use App\Http\Controllers\AmenityController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CicdDashboardController;
use App\Http\Controllers\DevOpsApiController;
use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\ManagementDashboardController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ReceptionPortalController;
use App\Http\Controllers\ResidentController;
use App\Http\Controllers\ResidentPortalController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\UserController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', function () {
    return redirect('/home');
});

Route::get('/home', function () {
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

Route::get('/quan-ly/{any}', function () {
    return view('welcome');
})->where('any', '.*');

Route::get('/manager', function () {
    return view('welcome');
});

Route::get('/manager/{any}', function () {
    return view('welcome');
})->where('any', '.*');

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

Route::get('/le-tan', function () {
    return view('welcome');
});

Route::get('/le-tan/{any}', function () {
    return view('welcome');
})->where('any', '.*');

Route::get('/receptionist', function () {
    return view('welcome');
});

Route::get('/receptionist/{any}', function () {
    return view('welcome');
})->where('any', '.*');

// API Management Dashboard
Route::get('/api/management/overview', [ManagementDashboardController::class, 'overview']);

// API Reception Portal
Route::get('/api/v1/reception/overview', [ReceptionPortalController::class, 'overview']);

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

// ==========================================
// HỆ THỐNG PHÂN QUYỀN VAI TRÒ RBAC (RESTful APIs)
// ==========================================
Route::prefix('api/v1')->middleware(['auth.bearer'])->group(function () {
    // 1. Quản lý Người dùng (Users)
    Route::get('users', [UserController::class, 'index'])->middleware('permission:USER:VIEW');
    Route::post('users', [UserController::class, 'store'])->middleware('permission:USER:CREATE');
    Route::get('users/{id}', [UserController::class, 'show'])->middleware('permission:USER:VIEW');
    Route::put('users/{id}', [UserController::class, 'update'])->middleware('permission:USER:UPDATE');
    Route::patch('users/{id}', [UserController::class, 'update'])->middleware('permission:USER:UPDATE');
    Route::delete('users/{id}', [UserController::class, 'destroy'])->middleware('permission:USER:DELETE');
    Route::get('users/{id}/roles', [UserController::class, 'getUserRoles'])->middleware('permission:USER:VIEW');
    Route::put('users/{id}/roles', [UserController::class, 'assignRoles'])->middleware('permission:USER:ASSIGN_ROLE|USER:UPDATE');
    Route::patch('users/{id}/roles', [UserController::class, 'assignRoles'])->middleware('permission:USER:ASSIGN_ROLE|USER:UPDATE');

    // 2. Quản lý Vai trò (Roles)
    Route::get('roles', [RoleController::class, 'index'])->middleware('permission:ROLE:VIEW');
    Route::post('roles', [RoleController::class, 'store'])->middleware('permission:ROLE:CREATE');
    Route::get('roles/{id}', [RoleController::class, 'show'])->middleware('permission:ROLE:VIEW');
    Route::put('roles/{id}', [RoleController::class, 'update'])->middleware('permission:ROLE:UPDATE');
    Route::patch('roles/{id}', [RoleController::class, 'update'])->middleware('permission:ROLE:UPDATE');
    Route::delete('roles/{id}', [RoleController::class, 'destroy'])->middleware('permission:ROLE:DELETE');
    Route::get('roles/{id}/permissions', [RoleController::class, 'getRolePermissions'])->middleware('permission:ROLE:VIEW');
    Route::put('roles/{id}/permissions', [RoleController::class, 'syncRolePermissions'])->middleware('permission:ROLE:ASSIGN_PERMISSION|ROLE:UPDATE');
    Route::patch('roles/{id}/permissions', [RoleController::class, 'syncRolePermissions'])->middleware('permission:ROLE:ASSIGN_PERMISSION|ROLE:UPDATE');

    // 3. Quản lý Danh mục Quyền hạn (Permissions)
    Route::get('permissions', [PermissionController::class, 'index'])->middleware('permission:PERMISSION:VIEW');
    Route::post('permissions', [PermissionController::class, 'store'])->middleware('permission:PERMISSION:CREATE');
    Route::get('permissions/{id}', [PermissionController::class, 'show'])->middleware('permission:PERMISSION:VIEW');
    Route::put('permissions/{id}', [PermissionController::class, 'update'])->middleware('permission:PERMISSION:UPDATE');
    Route::patch('permissions/{id}', [PermissionController::class, 'update'])->middleware('permission:PERMISSION:UPDATE');
    Route::delete('permissions/{id}', [PermissionController::class, 'destroy'])->middleware('permission:PERMISSION:DELETE');
});

// Aliases under api/v1/admin for RBAC
Route::prefix('api/v1/admin')->middleware(['auth.bearer'])->group(function () {
    Route::get('users', [UserController::class, 'index'])->middleware('permission:USER:VIEW');
    Route::post('users', [UserController::class, 'store'])->middleware('permission:USER:CREATE');
    Route::get('users/{id}', [UserController::class, 'show'])->middleware('permission:USER:VIEW');
    Route::put('users/{id}', [UserController::class, 'update'])->middleware('permission:USER:UPDATE');
    Route::delete('users/{id}', [UserController::class, 'destroy'])->middleware('permission:USER:DELETE');
    Route::get('users/{id}/roles', [UserController::class, 'getUserRoles'])->middleware('permission:USER:VIEW');
    Route::put('users/{id}/roles', [UserController::class, 'assignRoles'])->middleware('permission:USER:ASSIGN_ROLE|USER:UPDATE');

    Route::get('roles', [RoleController::class, 'index'])->middleware('permission:ROLE:VIEW');
    Route::post('roles', [RoleController::class, 'store'])->middleware('permission:ROLE:CREATE');
    Route::get('roles/{id}', [RoleController::class, 'show'])->middleware('permission:ROLE:VIEW');
    Route::put('roles/{id}', [RoleController::class, 'update'])->middleware('permission:ROLE:UPDATE');
    Route::delete('roles/{id}', [RoleController::class, 'destroy'])->middleware('permission:ROLE:DELETE');
    Route::get('roles/{id}/permissions', [RoleController::class, 'getRolePermissions'])->middleware('permission:ROLE:VIEW');
    Route::put('roles/{id}/permissions', [RoleController::class, 'syncRolePermissions'])->middleware('permission:ROLE:ASSIGN_PERMISSION|ROLE:UPDATE');

    Route::get('permissions', [PermissionController::class, 'index'])->middleware('permission:PERMISSION:VIEW');
    Route::post('permissions', [PermissionController::class, 'store'])->middleware('permission:PERMISSION:CREATE');
    Route::get('permissions/{id}', [PermissionController::class, 'show'])->middleware('permission:PERMISSION:VIEW');
    Route::put('permissions/{id}', [PermissionController::class, 'update'])->middleware('permission:PERMISSION:UPDATE');
    Route::delete('permissions/{id}', [PermissionController::class, 'destroy'])->middleware('permission:PERMISSION:DELETE');
});

// Explicit RBAC endpoints under api/v1/rbac
Route::prefix('api/v1/rbac')->middleware(['auth.bearer'])->group(function () {
    // 1. Quản lý Người dùng (Users)
    Route::get('users', [UserController::class, 'index'])->middleware('permission:USER:VIEW');
    Route::post('users', [UserController::class, 'store'])->middleware('permission:USER:CREATE');
    Route::get('users/{id}', [UserController::class, 'show'])->middleware('permission:USER:VIEW');
    Route::put('users/{id}', [UserController::class, 'update'])->middleware('permission:USER:UPDATE');
    Route::patch('users/{id}', [UserController::class, 'update'])->middleware('permission:USER:UPDATE');
    Route::delete('users/{id}', [UserController::class, 'destroy'])->middleware('permission:USER:DELETE');
    Route::get('users/{id}/roles', [UserController::class, 'getUserRoles'])->middleware('permission:USER:VIEW');
    Route::put('users/{id}/roles', [UserController::class, 'assignRoles'])->middleware('permission:USER:ASSIGN_ROLE|USER:UPDATE');
    Route::patch('users/{id}/roles', [UserController::class, 'assignRoles'])->middleware('permission:USER:ASSIGN_ROLE|USER:UPDATE');

    // 2. Quản lý Vai trò (Roles)
    Route::get('roles', [RoleController::class, 'index'])->middleware('permission:ROLE:VIEW');
    Route::post('roles', [RoleController::class, 'store'])->middleware('permission:ROLE:CREATE');
    Route::get('roles/{id}', [RoleController::class, 'show'])->middleware('permission:ROLE:VIEW');
    Route::put('roles/{id}', [RoleController::class, 'update'])->middleware('permission:ROLE:UPDATE');
    Route::patch('roles/{id}', [RoleController::class, 'update'])->middleware('permission:ROLE:UPDATE');
    Route::delete('roles/{id}', [RoleController::class, 'destroy'])->middleware('permission:ROLE:DELETE');
    Route::get('roles/{id}/permissions', [RoleController::class, 'getRolePermissions'])->middleware('permission:ROLE:VIEW');
    Route::put('roles/{id}/permissions', [RoleController::class, 'syncRolePermissions'])->middleware('permission:ROLE:ASSIGN_PERMISSION|ROLE:UPDATE');
    Route::patch('roles/{id}/permissions', [RoleController::class, 'syncRolePermissions'])->middleware('permission:ROLE:ASSIGN_PERMISSION|ROLE:UPDATE');

    // 3. Quản lý Danh mục Quyền hạn (Permissions)
    Route::get('permissions', [PermissionController::class, 'index'])->middleware('permission:PERMISSION:VIEW');
    Route::post('permissions', [PermissionController::class, 'store'])->middleware('permission:PERMISSION:CREATE');
    Route::get('permissions/{id}', [PermissionController::class, 'show'])->middleware('permission:PERMISSION:VIEW');
    Route::put('permissions/{id}', [PermissionController::class, 'update'])->middleware('permission:PERMISSION:UPDATE');
    Route::patch('permissions/{id}', [PermissionController::class, 'update'])->middleware('permission:PERMISSION:UPDATE');
    Route::delete('permissions/{id}', [PermissionController::class, 'destroy'])->middleware('permission:PERMISSION:DELETE');
});

// ==========================================
// QUẢN LÝ CHỦ HỘ VÀ NHÂN KHẨU CĂN HỘ (ADMIN)
// ==========================================
Route::prefix('api/v1')->middleware(['auth.bearer'])->group(function () {
    Route::get('residents', [ResidentController::class, 'index']);
    Route::post('residents', [ResidentController::class, 'store']);
    Route::get('residents/{id}', [ResidentController::class, 'show']);
    Route::put('residents/{id}', [ResidentController::class, 'update']);
    Route::patch('residents/{id}', [ResidentController::class, 'update']);
    Route::delete('residents/{id}', [ResidentController::class, 'destroy']);
    Route::get('meta/apartments', [ResidentController::class, 'apartments']);
});

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

// Phân hệ Quản trị CI/CD & DevOps Dashboard APIs
Route::prefix('api/admin/cicd')->group(function () {
    Route::get('bundle', [CicdDashboardController::class, 'bundle']);
    Route::get('overview', [CicdDashboardController::class, 'overview']);
    Route::get('pipelines', [CicdDashboardController::class, 'pipelines']);
    Route::get('branches', [CicdDashboardController::class, 'branches']);
    Route::get('pipelines/{id}', [CicdDashboardController::class, 'pipelineDetail']);
    Route::get('pipelines/{id}/jobs', [CicdDashboardController::class, 'jobs']);
    Route::get('pipelines/{id}/logs', [CicdDashboardController::class, 'logs']);
    Route::get('deployments', [CicdDashboardController::class, 'deployments']);
    Route::get('environments', [CicdDashboardController::class, 'environments']);
    Route::get('health', [CicdDashboardController::class, 'health']);
    Route::get('activities', [CicdDashboardController::class, 'activities']);
    Route::post('pipelines/run', [CicdDashboardController::class, 'runPipeline']);
    Route::post('pipelines/{id}/retry', [CicdDashboardController::class, 'retryPipeline']);
    Route::post('pipelines/{id}/cancel', [CicdDashboardController::class, 'cancelPipeline']);
    Route::post('deploy', [CicdDashboardController::class, 'deploy']);
    Route::post('rollback', [CicdDashboardController::class, 'rollback']);
});

// DevOps & Public Status SPA Pages
Route::get('/devops', function () {
    return view('welcome');
});

Route::get('/admin/devops', function () {
    return view('welcome');
});

Route::get('/status', function () {
    return view('welcome');
});

Route::get('/status/incidents', function () {
    return view('welcome');
});

// Health Check & Telemetry Endpoints (Stateless, no session or CSRF required)
Route::withoutMiddleware([
    StartSession::class,
    ShareErrorsFromSession::class,
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    PreventRequestForgery::class,
    ValidateCsrfToken::class,
])->group(function () {
    Route::get('/health', [HealthCheckController::class, 'health']);
    Route::get('/api/health', [HealthCheckController::class, 'health']);
    Route::get('/api/db-health', [HealthCheckController::class, 'dbHealth']);
    Route::get('/metrics', [MetricsController::class, 'metrics']);
});

// DevOps Management & Metrics APIs
Route::prefix('api/devops')->group(function () {
    Route::get('status', [DevOpsApiController::class, 'status']);
    Route::get('metrics', [DevOpsApiController::class, 'metrics']);
    Route::get('deployments', [DevOpsApiController::class, 'deployments']);
    Route::get('incidents', [DevOpsApiController::class, 'incidents']);
});

// Public Status APIs
Route::prefix('api/public')->group(function () {
    Route::get('status', [DevOpsApiController::class, 'publicStatus']);
    Route::get('incidents', [DevOpsApiController::class, 'publicIncidents']);
});

Route::fallback(function () {
    return response()->view('errors.404', [], 404);
});
