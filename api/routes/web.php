<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/login', [\App\Http\Controllers\Web\AuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [\App\Http\Controllers\Web\AuthController::class, 'login']);
Route::post('/logout', [\App\Http\Controllers\Web\AuthController::class, 'logout'])->name('logout');
