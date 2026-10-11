<?php

declare(strict_types=1);

namespace App\Shared\Http;

use InvalidArgumentException;

/**
 * The API_Error payload every failing Delivery_API response carries.
 *
 * Requirement 20.4 requires a stable error code, a human-readable message, and
 * one entry per rejected field naming the field and the rule violated. The code
 * catalogue itself and the rendering of this object into an HTTP response belong
 * to the exception handler; this value object only holds the shape.
 *
 * Framework-free on purpose: services raise domain exceptions carrying an
 * ApiError without reaching for a framework type.
 */
final readonly class ApiError
{
    /**
     * Stable codes are snake_case, as the documented catalogue spells them
     * (`unauthenticated`, `promotion_not_applicable`, `restaurant_suspended`).
     */
    private const CODE_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /**
     * @param  string  $code  a stable machine-readable code
     * @param  string  $message  a human-readable explanation, free of secrets
     * @param  array<string, list<string>>  $fieldErrors  rejected field name to the rules it violated
     */
    public function __construct(
        public string $code,
        public string $message,
        public array $fieldErrors = [],
    ) {
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new InvalidArgumentException(
                sprintf('"%s" is not a stable snake_case API_Error code.', $code)
            );
        }

        if (trim($message) === '') {
            throw new InvalidArgumentException('An API_Error message cannot be blank.');
        }

        foreach ($fieldErrors as $field => $rules) {
            if (trim($field) === '') {
                throw new InvalidArgumentException('An API_Error field name cannot be blank.');
            }

            if ($rules === []) {
                throw new InvalidArgumentException(
                    sprintf('The API_Error entry for field "%s" names no violated rule.', $field)
                );
            }
        }
    }

    public static function of(string $code, string $message): self
    {
        return new self($code, $message);
    }

    /**
     * @param  array<string, list<string>>  $fieldErrors
     */
    public static function withFieldErrors(string $code, string $message, array $fieldErrors): self
    {
        return new self($code, $message, $fieldErrors);
    }

    /**
     * Records one more violated rule against $field, returning a new instance.
     */
    public function withFieldError(string $field, string $rule): self
    {
        $fieldErrors = $this->fieldErrors;
        $fieldErrors[$field] = [...$fieldErrors[$field] ?? [], $rule];

        return new self($this->code, $this->message, $fieldErrors);
    }

    public function hasFieldErrors(): bool
    {
        return $this->fieldErrors !== [];
    }

    /**
     * @return array{code: string, message: string, field_errors: array<string, list<string>>}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'field_errors' => $this->fieldErrors,
        ];
    }
}
