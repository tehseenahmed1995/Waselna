<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Delivery_API routes
|--------------------------------------------------------------------------
|
| Registered under the version prefix `/api/v1` (Requirements 20.1, 20.11) and
| wrapped by the seven-middleware `api` group declared in bootstrap/app.php.
|
| Endpoints are added by the module tasks that own them; each module registers
| its own routes from this file so the prefix and the pipeline are declared in
| exactly one place.
|
*/

use App\Modules\Auth\Http\Controllers\AuthController;
use App\Modules\Catalog\Http\Controllers\RestaurantController;
use App\Shared\Http\Controllers\HealthController;
use App\Shared\Http\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

/*
| Health (Requirements 27.1, 27.2)
|
| Read by the Local_Dev_Environment container health check, by the load balancer,
| and by the post-deployment check of the Deploy_Pipeline, so it is deliberately
| left out of the per-minute allowance of Requirement 28.3: the counter lives in
| the Cache_Store, and a health endpoint that fails when the Cache_Store is
| unreachable could never report the Cache_Store as unreachable.
*/
Route::get('health', HealthController::class)
    ->withoutMiddleware(ThrottleRequests::class)
    ->name('health');

/*
| Auth (Requirement 1)
|
| Registration, sign-in, token refresh, sign-out, and profile endpoints.
*/
Route::group(['prefix' => 'auth'], function (): void {
    Route::post('register', [AuthController::class, 'register'])->name('auth.register');
    Route::post('login', [AuthController::class, 'login'])->name('auth.login');
    Route::post('refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
    // The Delivery_API authenticates with the custom opaque-token middleware
    // (AuthenticateBearer), not a Laravel auth guard; `auth:api` would resolve a
    // guard that this application never defines and fail with a 500.
    Route::middleware('auth.bearer:required')->post('logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::middleware('auth.bearer:required')->get('me', [AuthController::class, 'me'])->name('auth.me');
});

/*
| Catalog (Requirement 3)
|
| Restaurant discovery and menu browsing.
*/
Route::group(['prefix' => 'restaurants'], function (): void {
    Route::get('', [RestaurantController::class, 'index'])->name('restaurants.index');
    Route::get('{restaurant}', [RestaurantController::class, 'show'])->name('restaurants.show');
});
