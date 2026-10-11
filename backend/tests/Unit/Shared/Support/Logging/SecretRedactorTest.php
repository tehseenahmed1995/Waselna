<?php

declare(strict_types=1);

/*
 * SecretRedactor: Requirement 27.7 — passwords, Access_Tokens, Refresh_Tokens,
 * and payment instrument data appear in no log entry, whether they arrive under a
 * secret-naming key or as a literal value inside free text.
 */

use App\Shared\Support\Logging\SecretRedactor;

function redactor(): SecretRedactor
{
    return new SecretRedactor;
}

it('recognises a secret-naming key whatever its case and separators', function (string $key): void {
    expect(redactor()->isSensitiveKey($key))->toBeTrue();
})->with([
    'password' => ['password'],
    'confirmation' => ['password_confirmation'],
    'current' => ['currentPassword'],
    'access token' => ['access_token'],
    'refresh token' => ['REFRESH-TOKEN'],
    'authorization header' => ['Authorization'],
    'card number' => ['card_number'],
    'card verification value' => ['cvv'],
    'card expiry month' => ['exp_month'],
    'api key' => ['api_key'],
    'gateway secret' => ['stripe_client_secret'],
]);

it('leaves an ordinary key alone', function (string $key): void {
    expect(redactor()->isSensitiveKey($key))->toBeFalse();
})->with([
    'identifier' => ['order_id'],
    'email' => ['email'],
    'state' => ['order_state'],
    'amount' => ['amount_minor'],
    'blank' => [''],
    'separators only' => ['__'],
]);

it('replaces every value under a secret-naming key at any depth', function (): void {
    $redacted = redactor()->redact([
        'email' => 'customer@example.test',
        'password' => 'a-very-secret-password',
        'payment' => [
            'card_number' => '4242424242424242',
            'cvv' => '123',
            'amount_minor' => 1250,
        ],
    ]);

    expect($redacted)->toBe([
        'email' => 'customer@example.test',
        'password' => SecretRedactor::PLACEHOLDER,
        'payment' => [
            'card_number' => SecretRedactor::PLACEHOLDER,
            'cvv' => SecretRedactor::PLACEHOLDER,
            'amount_minor' => 1250,
        ],
    ]);
});

it('replaces a literal secret wherever it appears in free text', function (): void {
    $token = '01JB7Q2N4Z5X6Y7W8V9T0S1R2Q';

    $redacted = redactor()->redact([
        'note' => "presented Bearer {$token} twice: {$token}",
    ], [$token]);

    expect($redacted)->toBe([
        'note' => sprintf(
            'presented Bearer %s twice: %s',
            SecretRedactor::PLACEHOLDER,
            SecretRedactor::PLACEHOLDER,
        ),
    ]);
});

it('replaces a literal secret in a log message', function (): void {
    $password = 'correct-horse-battery-staple';

    expect(redactor()->redactText("sign-in failed for {$password}", [$password]))
        ->toBe('sign-in failed for '.SecretRedactor::PLACEHOLDER);
});

it('ignores a literal too short to replace safely', function (): void {
    expect(redactor()->redactText('a of the', ['a', 'of', 'the']))->toBe('a of the');
});

it('describes a throwable by class, location, and stack trace', function (): void {
    $redacted = redactor()->redact(['exception' => new RuntimeException('boom')]);

    expect($redacted)->toBeArray();

    /** @var array{exception: array{class: string, message: string, line: int, trace: string}} $redacted */
    expect($redacted['exception']['class'])->toBe(RuntimeException::class)
        ->and($redacted['exception']['message'])->toBe('boom')
        ->and($redacted['exception']['line'])->toBeInt()
        ->and($redacted['exception']['trace'])->toBeString()
        ->and($redacted['exception']['trace'])->not->toBe('');
});

it('keeps a secret out of the stack trace description', function (): void {
    $token = 'refresh-token-value-01JB7Q2N4Z';

    $redacted = redactor()->redact(
        ['exception' => new RuntimeException("rejected {$token}")],
        [$token],
    );

    /** @var array{exception: array{message: string}} $redacted */
    expect($redacted['exception']['message'])->toBe('rejected '.SecretRedactor::PLACEHOLDER);
});

it('leaves scalars and nulls as they are', function (): void {
    expect(redactor()->redact(['a' => 1, 'b' => 1.5, 'c' => true, 'd' => null]))
        ->toBe(['a' => 1, 'b' => 1.5, 'c' => true, 'd' => null]);
});

it('stops descending at a cyclic structure', function (): void {
    $node = ['name' => 'root'];
    $node['self'] = &$node;

    $redacted = redactor()->redact($node);

    expect($redacted)->toBeArray()
        ->and(json_encode($redacted))->toBeString();
});
