<?php

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

Route::fallback(function () {
    return response()->view('errors.404', [], 404);
});


