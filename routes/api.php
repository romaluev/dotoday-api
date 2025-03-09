<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;

Route::prefix('api')->group(function () {
    // Protected Routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('user', [AuthController::class, 'user'])->name('user');

        Route::apiResource('tasks', TaskController::class);
        Route::get('tasks/search', [TaskController::class, 'search']);
    });
});
