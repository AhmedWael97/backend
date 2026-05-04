<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/login', fn() => response()->json(['message' => 'Unauthenticated.'], 401))->name('login');
