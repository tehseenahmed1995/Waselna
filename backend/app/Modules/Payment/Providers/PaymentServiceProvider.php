<?php

declare(strict_types=1);

namespace App\Modules\Payment\Providers;

use App\Modules\Payment\Http\Controllers\PaymentController;
use App\Modules\Payment\Services\SimulatedPaymentGateway;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the container bindings owned by the Payment module (Payment authorization, capture, and refunds behind the Payment_Gateway).
 */
final class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SimulatedPaymentGateway::class);
    }

    public function boot(): void
    {
        Route::middleware(['api', 'auth.bearer:required'])->prefix('api/v1')->group(function (): void {
            Route::post('orders/{order}/pay', [PaymentController::class, 'pay']);
        });
    }
}
