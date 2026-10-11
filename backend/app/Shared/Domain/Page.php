<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use InvalidArgumentException;

/**
 * One page of a cursor-paginated collection.
 *
 * Requirement 20.10 returns every collection as a data array, a total count,
 * and a next-page cursor that is null on the final page. Requirement 20.15
 * applies a default page size of 20 and accepts a supplied size in the closed
 * range 1 to 100; those bounds belong to the request, so they are validated by
 * the Form_Request rather than here.
 *
 * @template-covariant TItem
 */
final readonly class Page
{
    public const DEFAULT_PAGE_SIZE = 20;

    public const MIN_PAGE_SIZE = 1;

    public const MAX_PAGE_SIZE = 100;

    /**
     * @param  list<TItem>  $data  the items on this page, in the documented order
     * @param  int  $total  the count of items matching the query across every page
     * @param  string|null  $nextCursor  null on the final page
     */
    public function __construct(
        public array $data,
        public int $total,
        public ?string $nextCursor = null,
    ) {
        if ($total < 0) {
            throw new InvalidArgumentException(
                sprintf('A page total cannot be negative; received %d.', $total)
            );
        }

        if (count($data) > $total) {
            throw new InvalidArgumentException(sprintf(
                'A page cannot hold more items than the total; received %d items and a total of %d.',
                count($data),
                $total,
            ));
        }

        if ($nextCursor !== null && trim($nextCursor) === '') {
            throw new InvalidArgumentException(
                'A next-page cursor must be null or a non-blank string.'
            );
        }
    }

    /**
     * The final, item-free page. Covariance makes it assignable wherever a page
     * of any item type is expected.
     *
     * @return self<never>
     */
    public static function empty(): self
    {
        return new self([], 0, null);
    }

    public function isEmpty(): bool
    {
        return $this->data === [];
    }

    public function isLastPage(): bool
    {
        return $this->nextCursor === null;
    }

    /**
     * The Requirement 20.10 response envelope.
     *
     * @return array{data: list<TItem>, total: int, next_cursor: string|null}
     */
    public function toEnvelope(): array
    {
        return [
            'data' => $this->data,
            'total' => $this->total,
            'next_cursor' => $this->nextCursor,
        ];
    }
}
