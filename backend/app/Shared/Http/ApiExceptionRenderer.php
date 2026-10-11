<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Domain\Exceptions\DomainException;
use App\Shared\Domain\Exceptions\ErrorCatalogue;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException as LaravelValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The single place a failing Delivery_API request becomes a response body.
 *
 * The design's "One error shape, one place that renders it" makes this the whole
 * of error rendering: a DomainException carries its catalogued code and status
 * (Requirements 20.3, 20.4), a Form_Request rejection becomes
 * `422 validation_failed` with one entry per rejected field naming the rule it
 * violated (Requirements 20.4, 28.1), and anything else collapses to
 * `internal_error` with HTTP status 500 and no stack trace in the body
 * (Requirement 27.9) — the trace reaches the Observability_Stack through the
 * structured log channel instead.
 *
 * Transport-level rejections that never reach an endpoint — an unrouted path, a
 * method the route does not accept, an unsupported media type — are rendered in
 * the same envelope under the codes named in TRANSPORT_CODES. Those codes sit
 * outside the endpoint error catalogue on purpose: the catalogue documents what an
 * endpoint can answer, and no endpoint answers these.
 */
final readonly class ApiExceptionRenderer
{
    /**
     * Status to code for failures raised by the transport rather than by an
     * endpoint.
     *
     * @var array<int, string>
     */
    private const TRANSPORT_CODES = [
        Response::HTTP_BAD_REQUEST => 'malformed_request',
        Response::HTTP_NOT_FOUND => 'not_found',
        Response::HTTP_METHOD_NOT_ALLOWED => 'method_not_allowed',
        Response::HTTP_NOT_ACCEPTABLE => 'not_acceptable',
        Response::HTTP_REQUEST_ENTITY_TOO_LARGE => 'payload_too_large',
        Response::HTTP_UNSUPPORTED_MEDIA_TYPE => 'unsupported_media_type',
        Response::HTTP_SERVICE_UNAVAILABLE => 'service_unavailable',
    ];

    /**
     * True where the failure should be answered with the API_Error envelope. The
     * Delivery_API is JSON-only (Requirement 20.2), so every request under the
     * version prefix qualifies, as does any client that asked for JSON.
     */
    public function handles(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson() || $request->wantsJson();
    }

    public function render(Throwable $exception, Request $request): JsonResponse
    {
        return match (true) {
            $exception instanceof DomainException => $this->fromDomain($exception),
            $exception instanceof LaravelValidationException => $this->fromValidator($exception),
            $exception instanceof AuthenticationException => $this->response(
                ApiError::of('unauthenticated', 'This endpoint requires an Access_Token.'),
                Response::HTTP_UNAUTHORIZED,
            ),
            $exception instanceof HttpExceptionInterface => $this->fromHttp($exception),
            default => $this->internal(),
        };
    }

    private function fromDomain(DomainException $exception): JsonResponse
    {
        $error = new ApiError(
            $exception->errorCode(),
            $exception->getMessage(),
            $exception->fieldErrors(),
        );

        return $this->response($error, $exception->httpStatus());
    }

    /**
     * A Form_Request rejection. The rule is read from the validator's failure map
     * rather than from the message, so the entry names `between:1,20` exactly as
     * the documented shape does.
     */
    private function fromValidator(LaravelValidationException $exception): JsonResponse
    {
        $fieldErrors = [];

        /** @var array<string, array<string, array<int, mixed>>> $failed */
        $failed = $exception->validator->failed();

        foreach ($failed as $field => $rules) {
            foreach ($rules as $rule => $parameters) {
                $fieldErrors[$field][] = $this->ruleName($rule, $parameters);
            }
        }

        // A rejection added straight to the message bag names no rule; the
        // message keeps the field present so Requirement 20.4 still holds.
        foreach ($exception->errors() as $field => $messages) {
            if (! isset($fieldErrors[$field]) && $messages !== []) {
                $fieldErrors[$field] = array_values(array_map('strval', $messages));
            }
        }

        $error = ApiError::withFieldErrors(
            ErrorCatalogue::VALIDATION_FAILED,
            'The request did not pass validation.',
            $fieldErrors,
        );

        return $this->response($error, ErrorCatalogue::statusFor(ErrorCatalogue::VALIDATION_FAILED));
    }

    /**
     * @param  array<int, mixed>  $parameters
     */
    private function ruleName(string $rule, array $parameters): string
    {
        $name = Str::snake($rule);

        $scalars = array_values(array_filter(
            $parameters,
            static fn (mixed $parameter): bool => is_scalar($parameter),
        ));

        if ($scalars === []) {
            return $name;
        }

        return $name.':'.implode(',', array_map(
            static fn (mixed $parameter): string => is_bool($parameter)
                ? ($parameter ? 'true' : 'false')
                : (string) $parameter,
            $scalars,
        ));
    }

    private function fromHttp(HttpExceptionInterface $exception): JsonResponse
    {
        $status = $exception->getStatusCode();

        if ($status >= Response::HTTP_INTERNAL_SERVER_ERROR && ! isset(self::TRANSPORT_CODES[$status])) {
            return $this->internal();
        }

        $code = match ($status) {
            Response::HTTP_UNAUTHORIZED => 'unauthenticated',
            Response::HTTP_FORBIDDEN => 'forbidden',
            Response::HTTP_TOO_MANY_REQUESTS => ErrorCatalogue::RATE_LIMIT_EXCEEDED,
            default => self::TRANSPORT_CODES[$status] ?? ErrorCatalogue::INTERNAL_ERROR,
        };

        if ($code === ErrorCatalogue::INTERNAL_ERROR) {
            return $this->internal();
        }

        return $this->response(
            ApiError::of($code, $this->transportMessage($status)),
            $status,
            // Retry-After on a 429, Allow on a 405: both belong to the rejection.
            array_filter($exception->getHeaders(), static fn (mixed $value): bool => is_string($value)),
        );
    }

    private function transportMessage(int $status): string
    {
        return match ($status) {
            Response::HTTP_UNAUTHORIZED => 'This endpoint requires an Access_Token.',
            Response::HTTP_FORBIDDEN => 'This action is not permitted for the acting Platform user.',
            Response::HTTP_NOT_FOUND => 'No endpoint matches this request.',
            Response::HTTP_METHOD_NOT_ALLOWED => 'This endpoint does not accept that HTTP method.',
            Response::HTTP_TOO_MANY_REQUESTS => 'Too many requests. Retry after the period named in the Retry-After header.',
            default => 'The request could not be served.',
        };
    }

    /**
     * Requirement 27.9: the code is `internal_error`, the status is 500, and the
     * body carries no trace. The trace is recorded by the log channel.
     */
    private function internal(): JsonResponse
    {
        return $this->response(
            ApiError::of(ErrorCatalogue::INTERNAL_ERROR, 'The request could not be completed.'),
            ErrorCatalogue::statusFor(ErrorCatalogue::INTERNAL_ERROR),
        );
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function response(ApiError $error, int $status, array $headers = []): JsonResponse
    {
        return new JsonResponse(Envelope::error($error), $status, $headers);
    }
}
