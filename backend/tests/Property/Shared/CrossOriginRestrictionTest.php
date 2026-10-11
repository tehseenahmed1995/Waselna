<?php

declare(strict_types=1);

// Feature: waselna, Property 64: Cross-origin requests are restricted to configured origins

/*
 * For any request origin, cross-origin access is granted exactly when the origin
 * is in the configured Web_Client origin list.
 *
 * The grant is what the browser reads: a response carrying
 * `Access-Control-Allow-Origin` for that origin. An origin outside the list gets
 * a response with no cross-origin headers at all, on an actual request and on a
 * preflight alike.
 *
 * **Validates: Requirements 28.4**
 */

use Eris\Generators;
use Eris\TestTrait;
use Illuminate\Support\Facades\Route;

uses(TestTrait::class);

/**
 * The origins the generator draws both the configured list and the request
 * origin from. Neighbours differing only in scheme, port, subdomain, or suffix
 * sit next to each other so a near-miss is generated as often as a hit.
 *
 * @return list<string>
 */
function crossOriginUniverse(): array
{
    return [
        'http://localhost:5173',
        'http://localhost:3000',
        'https://app.waselna.test',
        'https://app.waselna.test:8443',
        'http://app.waselna.test',
        'https://admin.waselna.test',
        'https://app.waselna.test.evil.example',
        'https://evil.example',
    ];
}

it('grants cross-origin access exactly to the configured Web_Client origins', function (): void {
    // One property run issues more requests than a minute's allowance permits,
    // and the allowance is Property 63's subject, not this one's.
    config(['waselna.throttle.limits.unauthenticated' => 100_000]);

    // Endpoints arrive with their module tasks; the pipeline is what is under
    // test. OPTIONS is registered so a preflight reaches the middleware.
    Route::middleware('api')
        ->prefix('api/v1')
        ->match(['GET', 'OPTIONS'], 'cors-probe', static fn (): array => ['ok' => true]);

    $universe = crossOriginUniverse();

    $this->limitTo(100)->forAll(
        Generators::subset($universe),
        Generators::elements([...$universe, '', null]),
        Generators::bool(),
    )->then(function (array $allowedOrigins, ?string $requestOrigin, bool $isPreflight): void {
        config(['waselna.cors.allowed_origins' => array_values($allowedOrigins)]);

        $headers = $requestOrigin === null ? [] : ['Origin' => $requestOrigin];

        if ($isPreflight) {
            $headers['Access-Control-Request-Method'] = 'GET';
        }

        $response = $this->withHeaders($headers)
            ->json($isPreflight ? 'OPTIONS' : 'GET', '/api/v1/cors-probe');

        $isGranted = $requestOrigin !== null
            && $requestOrigin !== ''
            && in_array($requestOrigin, $allowedOrigins, true);

        if ($isGranted) {
            expect($response->headers->get('Access-Control-Allow-Origin'))->toBe($requestOrigin)
                ->and($response->headers->get('Vary'))->toContain('Origin');

            if ($isPreflight) {
                expect($response->getStatusCode())->toBe(204)
                    ->and($response->headers->get('Access-Control-Allow-Methods'))->toContain('GET');
            }

            return;
        }

        expect($response->headers->get('Access-Control-Allow-Origin'))->toBeNull()
            ->and($response->headers->get('Access-Control-Allow-Methods'))->toBeNull()
            ->and($response->headers->get('Access-Control-Allow-Credentials'))->toBeNull()
            ->and($response->headers->get('Access-Control-Expose-Headers'))->toBeNull();
    });
});
