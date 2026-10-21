<?php

declare(strict_types=1);

namespace App\Providers;

use App\Shared\Http\Controllers\ReferenceDataController;
use App\Shared\Http\Middleware\Cors;
use App\Shared\Http\Middleware\SecurityHeaders;
use App\Shared\Http\Middleware\ThrottleRequests;
use App\Shared\Support\Clock;
use App\Shared\Support\Correlation\CorrelationContext;
use App\Shared\Support\Correlation\CorrelationId;
use App\Shared\Support\IdGenerator;
use App\Shared\Support\Logging\RequestSecretSource;
use App\Shared\Support\Logging\SecretRedactor;
use App\Shared\Support\Logging\SecretSource;
use App\Shared\Support\SystemClock;
use App\Shared\Support\UlidGenerator;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Shared kernel abstractions. A test swaps either binding for its fake
        // (FrozenClock, SequenceIdGenerator) to make time and identifiers
        // deterministic.
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(IdGenerator::class, UlidGenerator::class);

        // One holder per request, written by AssignCorrelationIdentifier and read
        // by the log channel, the queue payload hook, and the throttle subject.
        $this->app->singleton(CorrelationContext::class);

        // Log redaction (Requirement 27.7).
        $this->app->singleton(SecretRedactor::class);
        $this->app->singleton(SecretSource::class, RequestSecretSource::class);

        $this->registerPipeline();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->propagateCorrelationIdentifier();
        $this->registerReferenceDataRoute();
    }

    /**
     * The Platform's shared vocabularies (roles, statuses, currencies). Public
     * and unauthenticated: it is static reference data the Web_Client fetches to
     * render labels, so values and labels live only in the backend enums.
     */
    private function registerReferenceDataRoute(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(function (): void {
            Route::get('enums', [ReferenceDataController::class, 'index']);
        });
    }

    /**
     * The middleware whose behaviour is configuration rather than code: the
     * header set of Requirement 28.9, the origin list of Requirement 28.4, and
     * the per-minute allowances of Requirement 28.3.
     */
    private function registerPipeline(): void
    {
        $this->app->when(SecurityHeaders::class)
            ->needs('$headers')
            ->give(fn (): array => (array) config('waselna.security_headers', []));

        $this->app->bind(Cors::class, function (): Cors {
            /** @var array{allowed_origins: list<string>, allowed_methods: list<string>, allowed_headers: list<string>, exposed_headers: list<string>, max_age: int, supports_credentials: bool} $cors */
            $cors = config('waselna.cors');

            return new Cors(
                $cors['allowed_origins'],
                $cors['allowed_methods'],
                $cors['allowed_headers'],
                $cors['exposed_headers'],
                $cors['max_age'],
                $cors['supports_credentials'],
            );
        });

        $this->app->bind(ThrottleRequests::class, function (): ThrottleRequests {
            /** @var array{store: string|null, window_seconds: int, limits: array{authenticated: int, unauthenticated: int, unauthenticated_auth_endpoints: int}, auth_scope: string} $throttle */
            $throttle = config('waselna.throttle');

            return new ThrottleRequests(
                $this->app->make(CacheFactory::class)->store($throttle['store']),
                $this->app->make(Clock::class),
                $this->app->make(CorrelationContext::class),
                $throttle['limits'],
                $throttle['window_seconds'],
                $throttle['auth_scope'],
            );
        });
    }

    /**
     * Requirement 27.5: the Correlation_Identifier of the request reaches every
     * queued Job the request dispatches, and comes back out of the payload when
     * the Queue_Worker picks the Job up, so the Job's log entries carry the
     * identifier of the request that caused them.
     *
     * Registered once, here, from the same CorrelationContext the middleware
     * writes, so no producer has to be told the identifier explicitly.
     */
    private function propagateCorrelationIdentifier(): void
    {
        $context = $this->app->make(CorrelationContext::class);

        Queue::createPayloadUsing(static function () use ($context): array {
            $correlationId = $context->value();

            return $correlationId === null ? [] : ['correlation_id' => $correlationId];
        });

        Queue::before(static function (JobProcessing $event) use ($context): void {
            $payload = $event->job->payload();
            $carried = $payload['correlation_id'] ?? null;

            if (is_string($carried)) {
                $correlationId = CorrelationId::tryFromString($carried);

                if ($correlationId !== null) {
                    $context->assign($correlationId);
                }
            }
        });

        // A worker is long-lived: clearing between Jobs keeps one Job's
        // identifier out of the next Job's log entries.
        $forget = static function (JobProcessed|JobFailed $event) use ($context): void {
            $context->forget();
        };

        Queue::after($forget);
        Queue::failing($forget);
    }
}
