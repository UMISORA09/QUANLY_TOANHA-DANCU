<?php

use App\Http\Controllers\AccountProvisioningController;
use App\Http\Controllers\AmenityController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BuildingStructureController;
use App\Http\Controllers\CicdDashboardController;
use App\Http\Controllers\DevOpsApiController;
use App\Http\Controllers\FreshnessController;
use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\ManagementDashboardController;
use App\Http\Controllers\MeterReadingController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ReceptionPortalController;
use App\Http\Controllers\ResidentAmenityBookingController;
use App\Http\Controllers\ResidentController;
use App\Http\Controllers\ResidentPortalController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ServicePricingController;
use App\Http\Controllers\TemporaryRegistrationController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VehicleController;
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

Route::get('/forgot-password', function () {
    return view('welcome');
});

Route::get('/quen-mat-khau', function () {
    return view('welcome');
});

Route::get('/verify-otp', function () {
    return view('welcome');
});

Route::get('/reset-password', function () {
    return view('welcome');
});

Route::get('/admin', function () {
    return view('welcome');
});

Route::get('/admin/{any}', function () {
    return view('welcome');
})->where('any', '.*');

Route::get('/dev', function () {
    return view('welcome');
});

Route::get('/dev/{any}', function () {
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

Route::get('/tien-ich', function () {
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

Route::get('/an-ninh', function () {
    return view('welcome');
});

Route::get('/an-ninh/{any}', function () {
    return view('welcome');
})->where('any', '.*');

Route::get('/bao-ve', function () {
    return view('welcome');
});

Route::get('/bao-ve/{any}', function () {
    return view('welcome');
})->where('any', '.*');

Route::get('/security', function () {
    return view('welcome');
});

Route::get('/security/{any}', function () {
    return view('welcome');
})->where('any', '.*');

// API Management Dashboard
Route::get('/api/management/overview', [ManagementDashboardController::class, 'overview']);

// API Reception Portal
Route::get('/api/v1/reception/overview', [ReceptionPortalController::class, 'overview']);

// API Resident Portal
Route::get('/api/v1/resident/overview', [ResidentPortalController::class, 'overview'])->middleware('auth.bearer:strict');
Route::post('/api/v1/resident/tickets', [ResidentPortalController::class, 'createTicket'])->middleware('auth.bearer:strict');
Route::prefix('api/v1/resident')->middleware('auth.bearer:strict')->group(function () {
    Route::get('amenities', [ResidentAmenityBookingController::class, 'index']);
    Route::get('amenities/{id}/availability', [ResidentAmenityBookingController::class, 'availability']);
    Route::post('amenity-bookings', [ResidentAmenityBookingController::class, 'store']);
    Route::get('amenity-bookings', [ResidentAmenityBookingController::class, 'bookings']);
    Route::get('amenity-bookings/{id}', [ResidentAmenityBookingController::class, 'show']);
    Route::post('amenity-bookings/{id}/cancel', [ResidentAmenityBookingController::class, 'cancel']);
});
Route::post('/api/v1/resident/visitors', [ResidentPortalController::class, 'createVisitor']);
Route::post('/api/v1/resident/invoices/{id}/pay', [ResidentPortalController::class, 'payInvoice']);

// API Auth (Có Throttle Rate Limiting chống Brute-Force)
Route::post('/api/v1/auth/login', [AuthController::class, 'login'])->middleware('throttle:60,1');
Route::post('/api/auth/login', [AuthController::class, 'login'])->middleware('throttle:60,1');
Route::post('/api/v1/auth/register', [AuthController::class, 'register'])->middleware('throttle:60,1');
Route::post('/api/auth/register', [AuthController::class, 'register'])->middleware('throttle:60,1');
Route::post('/api/v1/auth/send-register-otp', [AuthController::class, 'sendRegisterOtp'])->middleware('throttle:60,1');
Route::post('/api/auth/send-register-otp', [AuthController::class, 'sendRegisterOtp'])->middleware('throttle:60,1');
Route::post('/api/v1/auth/verify-register-otp', [AuthController::class, 'verifyRegisterOtp'])->middleware('throttle:60,1');
Route::post('/api/auth/verify-register-otp', [AuthController::class, 'verifyRegisterOtp'])->middleware('throttle:60,1');
Route::post('/api/v1/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:60,1');
Route::post('/api/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:60,1');
Route::post('/api/v1/auth/verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:60,1');
Route::post('/api/auth/verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:60,1');
Route::post('/api/v1/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:60,1');
Route::post('/api/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:60,1');
Route::get('/api/v1/auth/public-apartments', [AuthController::class, 'publicApartments']);
Route::get('/api/auth/public-apartments', [AuthController::class, 'publicApartments']);
Route::get('/api/v1/auth/rental-listings', [AuthController::class, 'rentalListings']);
Route::get('/api/auth/rental-listings', [AuthController::class, 'rentalListings']);
Route::get('/api/v1/rentals/listings', [AuthController::class, 'rentalListings']);
Route::get('/api/rentals/listings', [AuthController::class, 'rentalListings']);
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
// ĐĂNG KÝ VÀ DUYỆT TẠM TRÚ / TẠM VẮNG (ADMIN)
// ==========================================
Route::prefix('api/v1')->middleware(['auth.bearer'])->group(function () {
    Route::get('residents/temporary-registrations', [TemporaryRegistrationController::class, 'index']);
    Route::post('residents/temporary-registrations', [TemporaryRegistrationController::class, 'store']);
    Route::get('residents/temporary-registrations/{id}', [TemporaryRegistrationController::class, 'show']);
    Route::put('residents/temporary-registrations/{id}', [TemporaryRegistrationController::class, 'update']);
    Route::patch('residents/temporary-registrations/{id}', [TemporaryRegistrationController::class, 'update']);
    Route::delete('residents/temporary-registrations/{id}', [TemporaryRegistrationController::class, 'destroy']);
    Route::post('residents/temporary-registrations/{id}/approve', [TemporaryRegistrationController::class, 'approve']);
    Route::post('residents/temporary-registrations/{id}/reject', [TemporaryRegistrationController::class, 'reject']);
    Route::post('residents/temporary-registrations/{id}/submit-police', [TemporaryRegistrationController::class, 'submitToPolice']);
    Route::post('residents/temporary-registrations/upload-cccd', [TemporaryRegistrationController::class, 'uploadCccd']);
    Route::get('residents/temporary-registrations/{id}/export', [TemporaryRegistrationController::class, 'exportForm']);
    Route::get('residents/temporary-registrations/{id}/download', [TemporaryRegistrationController::class, 'downloadForm']);

    // Direct / Alias routes
    Route::get('temporary-registrations', [TemporaryRegistrationController::class, 'index']);
    Route::post('temporary-registrations', [TemporaryRegistrationController::class, 'store']);
    Route::get('temporary-registrations/{id}', [TemporaryRegistrationController::class, 'show']);
    Route::put('temporary-registrations/{id}', [TemporaryRegistrationController::class, 'update']);
    Route::patch('temporary-registrations/{id}', [TemporaryRegistrationController::class, 'update']);
    Route::delete('temporary-registrations/{id}', [TemporaryRegistrationController::class, 'destroy']);
    Route::post('temporary-registrations/{id}/approve', [TemporaryRegistrationController::class, 'approve']);
    Route::post('temporary-registrations/{id}/reject', [TemporaryRegistrationController::class, 'reject']);
    Route::post('temporary-registrations/{id}/submit-police', [TemporaryRegistrationController::class, 'submitToPolice']);
    Route::post('temporary-registrations/upload-cccd', [TemporaryRegistrationController::class, 'uploadCccd']);
    Route::get('temporary-registrations/{id}/export', [TemporaryRegistrationController::class, 'exportForm']);
    Route::get('temporary-registrations/{id}/download', [TemporaryRegistrationController::class, 'downloadForm']);
});

// ==========================================
// CẤP PHÁT TÀI KHOẢN TỰ ĐỘNG (ACCOUNT PROVISIONING)
// ==========================================
Route::prefix('api/v1')->middleware(['auth.bearer'])->group(function () {
    Route::get('account-provisioning', [AccountProvisioningController::class, 'index']);
    Route::post('account-provisioning', [AccountProvisioningController::class, 'store']);
    Route::post('account-provisioning/import', [AccountProvisioningController::class, 'import']);
    Route::post('account-provisioning/batch-resend', [AccountProvisioningController::class, 'batchResend']);
    Route::get('account-provisioning/{id}', [AccountProvisioningController::class, 'show']);
    Route::put('account-provisioning/{id}', [AccountProvisioningController::class, 'update']);
    Route::delete('account-provisioning/{id}', [AccountProvisioningController::class, 'destroy']);
    Route::post('account-provisioning/{id}/resend-activation', [AccountProvisioningController::class, 'resendActivation']);
    Route::post('account-provisioning/{id}/toggle-lock', [AccountProvisioningController::class, 'toggleLock']);
});
Route::get('/api/v1/account-provisioning/status', [AccountProvisioningController::class, 'checkStatus']);
Route::post('/api/v1/account-provisioning/activate', [AccountProvisioningController::class, 'activate']);
Route::get('/kich-hoat-tai-khoan', function () {
    return view('welcome');
});
Route::get('/activate-account', function () {
    return view('welcome');
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

// ==========================================
// ĐĂNG KÝ PHƯƠNG TIỆN & TỰ ĐỘNG ĐẨY PHÍ HÓA ĐƠN (VEHICLES)
// ==========================================
Route::prefix('api/v1')->middleware(['auth.bearer'])->group(function () {
    Route::get('vehicles', [VehicleController::class, 'index']);
    Route::post('vehicles', [VehicleController::class, 'store']);
    Route::get('vehicles/pricing-config', [VehicleController::class, 'pricingConfig']);
    Route::get('vehicles/meta/apartments', [VehicleController::class, 'apartments']);
    Route::get('vehicles/meta/residents', [VehicleController::class, 'allResidents']);
    Route::get('vehicles/meta/apartments/{apartmentId}/residents', [VehicleController::class, 'apartmentResidents']);
    Route::get('vehicles/{id}', [VehicleController::class, 'show']);
    Route::put('vehicles/{id}', [VehicleController::class, 'update']);
    Route::patch('vehicles/{id}', [VehicleController::class, 'update']);
    Route::delete('vehicles/{id}', [VehicleController::class, 'destroy']);
    Route::patch('vehicles/{id}/toggle-active', [VehicleController::class, 'toggleActive']);
    Route::post('vehicles/{id}/approve', [VehicleController::class, 'approve']);
    Route::post('vehicles/{id}/sync-invoice', [VehicleController::class, 'syncInvoice']);
});
Route::get('vehicles/pricing-config', [ServicePricingController::class, 'pricingConfig']);

// ==========================================
// CÀI ĐẶT ĐƠN GIÁ ĐIỆN, NƯỚC LŨY TIẾN & PHÍ QUẢN LÝ (CHỨC NĂNG 3 - XUANHOA)
// ==========================================
Route::prefix('api/v1')->middleware(['auth.bearer'])->group(function () {
    Route::get('pricing-configs', [ServicePricingController::class, 'index']);
    Route::post('pricing-configs', [ServicePricingController::class, 'store']);
    Route::get('pricing-configs/{id}', [ServicePricingController::class, 'show']);
    Route::put('pricing-configs/{id}', [ServicePricingController::class, 'update']);
    Route::delete('pricing-configs/{id}', [ServicePricingController::class, 'destroy']);
    Route::patch('pricing-configs/{id}/toggle-active', [ServicePricingController::class, 'toggleActive']);
    Route::get('pricing-configs/{configId}/tiers', [ServicePricingController::class, 'getTiers']);
    Route::put('pricing-configs/{configId}/tiers', [ServicePricingController::class, 'updateTiers']);
    Route::post('pricing-configs/{configId}/simulate', [ServicePricingController::class, 'simulate']);
});

Route::get('/quan-ly/vehicles', function () {
    return view('welcome');
});
Route::get('/admin/vehicles', function () {
    return view('welcome');
});
Route::get('/le-tan/vehicles', function () {
    return view('welcome');
});
Route::get('/le-tan/phuong-tien', function () {
    return view('welcome');
});
Route::get('/an-ninh/vehicles', function () {
    return view('welcome');
});
Route::get('/quan-ly/don-gia', function () {
    return view('welcome');
});
Route::get('/quan-ly/pricing-configs', function () {
    return view('welcome');
});
Route::get('/admin/pricing-configs', function () {
    return view('welcome');
});

// ==========================================
// CHỐT CHỈ SỐ ĐIỆN/NƯỚC THỦ CÔNG QUA FORM (CHỨC NĂNG 4 - XUANHOA)
// ==========================================
Route::prefix('api/v1')->middleware(['auth.bearer'])->group(function () {
    Route::get('meter-readings/summary', [MeterReadingController::class, 'summary']);
    Route::get('meter-readings', [MeterReadingController::class, 'indexReadings']);
    Route::post('meter-readings', [MeterReadingController::class, 'storeReading']);
    Route::put('meter-readings/{id}', [MeterReadingController::class, 'updateReading']);
    Route::delete('meter-readings/{id}', [MeterReadingController::class, 'destroyReading']);
    Route::post('meter-readings/lock-cycle', [MeterReadingController::class, 'lockCycle']);
    Route::post('meter-readings/unlock-cycle', [MeterReadingController::class, 'unlockCycle']);

    Route::get('meters', [MeterReadingController::class, 'indexMeters']);
    Route::post('meters', [MeterReadingController::class, 'storeMeter']);
});

Route::get('/quan-ly/chot-chi-so', function () {
    return view('welcome');
});
Route::get('/admin/meter-readings', function () {
    return view('welcome');
});

// ==========================================
// QUẢN LÝ KHỐI, TẦNG & CĂN HỘ (BUILDING, FLOORS & APARTMENTS) - CHỨC NĂNG 2
// ==========================================
// Heartbeat kiểm tra phiên bản dữ liệu thời gian thực (Cực nhanh qua Redis, không phụ thuộc auth middleware)
Route::get('api/v1/buildings/version', [BuildingStructureController::class, 'getDataVersion']);

Route::prefix('api/v1')->middleware(['auth.bearer'])->group(function () {
    // Tải toàn bộ cấu trúc tòa nhà siêu tốc 1 request duy nhất
    Route::get('buildings/bootstrap', [BuildingStructureController::class, 'bootstrap']);

    // Khối tòa nhà & Thống kê KPI
    Route::get('blocks', [BuildingStructureController::class, 'indexBlocks']);
    Route::get('apartments/stats', [BuildingStructureController::class, 'getStats']);

    // Tầng theo khối (1-N: Block -> Floors)
    Route::get('blocks/{blockId}/floors', [BuildingStructureController::class, 'indexFloors']);
    Route::post('blocks/{blockId}/floors', [BuildingStructureController::class, 'storeFloor']);
    Route::put('floors/{floorId}', [BuildingStructureController::class, 'updateFloor']);
    Route::delete('floors/{floorId}', [BuildingStructureController::class, 'destroyFloor']);

    // Căn hộ (1-N: Floor -> Apartments)
    Route::get('apartments', [BuildingStructureController::class, 'indexApartments']);
    Route::post('apartments', [BuildingStructureController::class, 'storeApartment']);
    Route::post('apartments/batch-generate', [BuildingStructureController::class, 'batchGenerateApartments']);
    Route::put('apartments/{id}', [BuildingStructureController::class, 'updateApartment']);
    Route::patch('apartments/{id}/status', [BuildingStructureController::class, 'updateStatus']);
    Route::delete('apartments/{id}', [BuildingStructureController::class, 'destroyApartment']);
});

Route::get('/quan-ly/apartments', function () {
    return view('welcome');
});
Route::get('/quan-ly/buildings', function () {
    return view('welcome');
});
Route::get('/admin/buildings', function () {
    return view('welcome');
});
Route::get('/admin/apartments', function () {
    return view('welcome');
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
Route::prefix('api/v1/admin')->middleware('amenity.lock')->group(function () {
    // Tòa nhà / Blocks
    Route::get('blocks', [AmenityController::class, 'getBlocks']);

    // Danh mục tiện ích
    Route::get('amenity-categories', [AmenityController::class, 'getCategories']);
    Route::post('amenity-categories', [AmenityController::class, 'createCategory'])->middleware(['auth.bearer:strict', 'permission:AMENITY:CREATE']);
    Route::put('amenity-categories/{id}', [AmenityController::class, 'updateCategory'])->middleware(['auth.bearer:strict', 'permission:AMENITY:UPDATE']);
    Route::delete('amenity-categories/{id}', [AmenityController::class, 'deleteCategory'])->middleware(['auth.bearer:strict', 'permission:AMENITY:DELETE']);

    // Tiện ích
    Route::get('amenities', [AmenityController::class, 'getAmenities']);
    Route::post('amenities', [AmenityController::class, 'createAmenity'])->middleware(['auth.bearer:strict', 'permission:AMENITY:CREATE']);
    Route::get('amenities/{id}', [AmenityController::class, 'getAmenity']);
    Route::put('amenities/{id}', [AmenityController::class, 'updateAmenity'])->middleware(['auth.bearer:strict', 'permission:AMENITY:UPDATE']);
    Route::patch('amenities/{id}/status', [AmenityController::class, 'toggleAmenityStatus'])->middleware(['auth.bearer:strict', 'permission:AMENITY:UPDATE']);
    Route::delete('amenities/{id}', [AmenityController::class, 'deleteAmenity'])->middleware(['auth.bearer:strict', 'permission:AMENITY:DELETE']);
    Route::get('amenities/{id}/bookings', [AmenityController::class, 'getAmenityBookings'])->middleware(['auth.bearer:strict', 'permission:AMENITY:UPDATE']);
    Route::patch('amenities/{amenityId}/bookings/{bookingId}/status', [AmenityController::class, 'updateBookingStatus'])->middleware(['auth.bearer:strict', 'permission:AMENITY:UPDATE']);
    Route::post('amenities/{amenityId}/bookings/{bookingId}/cancel', [AmenityController::class, 'cancelBooking'])->middleware(['auth.bearer:strict', 'permission:AMENITY:UPDATE']);

    // Khung giờ hoạt động (Time Slots)
    Route::get('amenities/{amenityId}/time-slots', [AmenityController::class, 'getTimeSlots']);
    Route::post('amenities/{amenityId}/time-slots', [AmenityController::class, 'createTimeSlot'])->middleware(['auth.bearer:strict', 'permission:AMENITY:CONFIG_SLOT']);
    Route::put('amenities/{amenityId}/time-slots/{slotId}', [AmenityController::class, 'updateTimeSlot'])->middleware(['auth.bearer:strict', 'permission:AMENITY:CONFIG_SLOT']);
    Route::patch('amenities/{amenityId}/time-slots/{slotId}/status', [AmenityController::class, 'toggleTimeSlotStatus'])->middleware(['auth.bearer:strict', 'permission:AMENITY:CONFIG_SLOT']);
    Route::delete('amenities/{amenityId}/time-slots/{slotId}', [AmenityController::class, 'deleteTimeSlot'])->middleware(['auth.bearer:strict', 'permission:AMENITY:CONFIG_SLOT']);

    // Ngày đóng cửa bảo trì (Blackouts)
    Route::get('amenities/{amenityId}/blackouts', [AmenityController::class, 'getBlackouts']);
    Route::post('amenities/{amenityId}/blackouts', [AmenityController::class, 'createBlackout'])->middleware(['auth.bearer:strict', 'permission:AMENITY:CONFIG_SLOT']);
    Route::put('amenities/{amenityId}/blackouts/{blackoutId}', [AmenityController::class, 'updateBlackout'])->middleware(['auth.bearer:strict', 'permission:AMENITY:CONFIG_SLOT']);
    Route::delete('amenities/{amenityId}/blackouts/{blackoutId}', [AmenityController::class, 'deleteBlackout'])->middleware(['auth.bearer:strict', 'permission:AMENITY:CONFIG_SLOT']);
});

// Đặt chỗ tiện ích (Booking Enforcement)
Route::post('/api/v1/amenities/{id}/bookings', [ResidentAmenityBookingController::class, 'store'])->middleware('auth.bearer:strict');
Route::post('/api/v1/amenities/{amenityId}/bookings/{bookingId}/cancel', [ResidentAmenityBookingController::class, 'cancelAlias'])->middleware('auth.bearer:strict');

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
    Route::get('security', [CicdDashboardController::class, 'security']);
    Route::get('activities', [CicdDashboardController::class, 'activities']);
    Route::get('freshness', [FreshnessController::class, 'index']);
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
    Route::get('/api/monitoring/freshness', [FreshnessController::class, 'index']);
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
