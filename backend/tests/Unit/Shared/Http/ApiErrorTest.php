<?php

declare(strict_types=1);

/*
 * ApiError: the Requirement 20.4 failure payload — a stable snake_case code, a
 * human-readable message, and one entry per rejected field.
 */

use App\Shared\Http\ApiError;

it('holds a stable code and a message with no field errors', function (): void {
    $error = ApiError::of('unauthenticated', 'Authentication is required.');

    expect($error->code)->toBe('unauthenticated')
        ->and($error->message)->toBe('Authentication is required.')
        ->and($error->fieldErrors)->toBe([])
        ->and($error->hasFieldErrors())->toBeFalse()
        ->and($error->toArray())->toBe([
            'code' => 'unauthenticated',
            'message' => 'Authentication is required.',
            'field_errors' => [],
        ]);
});

it('accepts the documented snake_case codes', function (string $code): void {
    expect(ApiError::of($code, 'A message.')->code)->toBe($code);
})->with([
    'single word' => ['unauthenticated'],
    'two words' => ['restaurant_suspended'],
    'three words' => ['promotion_not_applicable'],
    'with a digit' => ['error_42'],
]);

it('rejects a code that is not stable snake_case', function (string $code): void {
    expect(static fn (): ApiError => ApiError::of($code, 'A message.'))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'blank' => [''],
    'capitalised' => ['Unauthenticated'],
    'screaming' => ['UNAUTHENTICATED'],
    'camel case' => ['promotionNotApplicable'],
    'kebab case' => ['promotion-not-applicable'],
    'leading underscore' => ['_unauthenticated'],
    'leading digit' => ['42_error'],
    'spaces' => ['not applicable'],
]);

it('rejects a blank message', function (string $message): void {
    expect(static fn (): ApiError => ApiError::of('unauthenticated', $message))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => [''],
    'spaces' => ['   '],
]);

it('names each rejected field and the rules it violated', function (): void {
    $error = ApiError::withFieldErrors('validation_failed', 'The request is invalid.', [
        'email' => ['required', 'email'],
        'quantity' => ['min:1'],
    ]);

    expect($error->hasFieldErrors())->toBeTrue()
        ->and($error->toArray())->toBe([
            'code' => 'validation_failed',
            'message' => 'The request is invalid.',
            'field_errors' => [
                'email' => ['required', 'email'],
                'quantity' => ['min:1'],
            ],
        ]);
});

it('rejects a blank field name', function (): void {
    expect(static fn (): ApiError => ApiError::withFieldErrors('validation_failed', 'Invalid.', [
        '   ' => ['required'],
    ]))->toThrow(InvalidArgumentException::class);
});

it('rejects a field entry naming no violated rule', function (): void {
    expect(static fn (): ApiError => ApiError::withFieldErrors('validation_failed', 'Invalid.', [
        'email' => [],
    ]))->toThrow(InvalidArgumentException::class);
});

it('adds a field error without mutating the original', function (): void {
    $original = ApiError::of('validation_failed', 'The request is invalid.');
    $withOne = $original->withFieldError('email', 'required');
    $withTwo = $withOne->withFieldError('email', 'email');

    expect($original->fieldErrors)->toBe([])
        ->and($withOne->fieldErrors)->toBe(['email' => ['required']])
        ->and($withTwo->fieldErrors)->toBe(['email' => ['required', 'email']])
        ->and($withTwo->code)->toBe('validation_failed')
        ->and($withTwo->message)->toBe('The request is invalid.');
});

it('keeps one entry per field when several fields are rejected', function (): void {
    $error = ApiError::of('validation_failed', 'The request is invalid.')
        ->withFieldError('email', 'required')
        ->withFieldError('quantity', 'min:1');

    expect(array_keys($error->fieldErrors))->toBe(['email', 'quantity']);
});
