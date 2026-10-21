<?php

declare(strict_types=1);

use App\Http\Controllers\Api\NotificationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| JSON API のルート定義。
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware('auth:sanctum')->get('/v1/notifications', [NotificationController::class, 'index'])
    ->name('api.v1.notifications.index');

Route::middleware('auth:sanctum')->post('/v1/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])
    ->name('api.v1.notifications.markAsRead');

Route::middleware('auth:sanctum')->post('/v1/notifications/read-all', [NotificationController::class, 'markAllAsRead'])
    ->name('api.v1.notifications.markAllAsRead');
