<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Reception Module API Routes
| Route API cho module Reception (Tín)
|--------------------------------------------------------------------------
|
| Prefix: api/reception
|
*/

Route::prefix('api/reception')->middleware('api')->group(function () {
    // TODO: Thêm routes
    // Route::apiResource('residents', \App\Modules\Reception\Controllers\ResidentController::class);
    // Route::apiResource('apartments', ...);
    // Route::apiResource('visitors', ...);
    // Route::apiResource('parcels', ...);
});
