<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Billing Module API Routes
| Route API cho module Billing (Hòa)
|--------------------------------------------------------------------------
|
| Prefix: api/billing
| Tất cả route trong file này sẽ có prefix /api/billing/
|
*/

Route::prefix('api/billing')->middleware('api')->group(function () {
    // TODO: Thêm routes cho Invoice, UnitPrice
    // Route::apiResource('invoices', \App\Modules\Billing\Controllers\InvoiceController::class);
});
