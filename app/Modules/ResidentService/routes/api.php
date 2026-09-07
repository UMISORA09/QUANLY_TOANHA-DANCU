<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ResidentService Module API Routes
| Route API cho module ResidentService (Nguyên)
|--------------------------------------------------------------------------
|
| Prefix: api/resident-service
|
*/

Route::prefix('api/resident-service')->middleware('api')->group(function () {
    // TODO: Thêm routes
    // Route::apiResource('tickets', \App\Modules\ResidentService\Controllers\TicketController::class);
    // Route::apiResource('amenities', ...);
    // Route::apiResource('announcements', ...);
});
