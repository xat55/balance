<?php

use App\Http\Controllers\BalanceController;
use Illuminate\Support\Facades\Route;

Route::post('/webhook/deposit', [BalanceController::class, 'depositWebhook']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/balance', [BalanceController::class, 'balance']);
    Route::post('/withdraw', [BalanceController::class, 'withdraw']);
});
