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

Route::fallback(function () {
    return response()->view('errors.404', [], 404);
});


