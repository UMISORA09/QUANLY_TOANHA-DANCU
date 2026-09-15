<?php

use App\Http\Controllers\ManagementDashboardController;
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

Route::get('/api/management/overview', [ManagementDashboardController::class, 'overview']);

Route::fallback(function () {
    return response()->view('errors.404', [], 404);
});
