<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Domain\Page;

/**
 * The one place the documented response shapes are built.
 *
 * From the design's "API envelope and error shapes":
 *
 *     single resource   { "data": { … } }
 *     collection        { "data": [ … ], "meta": { "total": 137, "next_cursor": "eyJ…" } }
 *     API_Error         { "error": { "code": …, "message": …, "fields": [ { "field": …, "rule": … } ] } }
 *
 * Requirement 20.10 requires the data array, the total count, and a next-page
 * cursor that is null on the final page; Requirement 20.4 requires the code, the
 * message, and one entry per rejected field naming the field and the rule. The
 * `fields` list is derived from ApiError's field-to-rules map, so a field that
 * violates two rules contributes two entries.
 *
 * Framework-free: these are plain arrays, encoded by the response layer.
 */
final class Envelope
{
    /**
     * @param  array<string, mixed>  $resource
     * @return array{data: array<string, mixed>}
     */
    public static function resource(array $resource): array
    {
        return ['data' => $resource];
    }

    /**
     * @template TItem
     *
     * @param  Page<TItem>  $page
     * @return array{data: list<TItem>, meta: array{total: int, next_cursor: string|null}}
     */
    public static function collection(Page $page): array
    {
        $envelope = $page->toEnvelope();

        return [
            'data' => $envelope['data'],
            'meta' => [
                'total' => $envelope['total'],
                'next_cursor' => $envelope['next_cursor'],
            ],
        ];
    }

    /**
     * A collection assembled without a Page, used where the items and the total
     * are already known.
     *
     * @param  list<mixed>  $data
     * @return array{data: list<mixed>, meta: array{total: int, next_cursor: string|null}}
     */
    public static function items(array $data, int $total, ?string $nextCursor = null): array
    {
        return self::collection(new Page($data, $total, $nextCursor));
    }

    /**
     * @return array{error: array{code: string, message: string, fields: list<array{field: string, rule: string}>}}
     */
    public static function error(ApiError $error): array
    {
        return [
            'error' => [
                'code' => $error->code,
                'message' => $error->message,
                'fields' => self::fields($error),
            ],
        ];
    }

    /**
     * True when $payload is already one of the three documented shapes, so the
     * response middleware wraps a payload at most once.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function wraps(array $payload): bool
    {
        return array_key_exists('data', $payload) || array_key_exists('error', $payload);
    }

    /**
     * @return list<array{field: string, rule: string}>
     */
    private static function fields(ApiError $error): array
    {
        $fields = [];

        foreach ($error->fieldErrors as $field => $rules) {
            foreach ($rules as $rule) {
                $fields[] = ['field' => (string) $field, 'rule' => $rule];
            }
        }

        return $fields;
    }
}
