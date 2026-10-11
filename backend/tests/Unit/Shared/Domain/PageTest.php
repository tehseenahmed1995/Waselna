<?php

declare(strict_types=1);

/*
 * Page: the Requirement 20.10 collection envelope (data, total, next_cursor)
 * and the Requirement 20.15 page-size bounds it publishes.
 */

use App\Shared\Domain\Page;

it('holds the items, the overall total, and the next-page cursor', function (): void {
    $page = new Page(['first', 'second'], 42, 'cursor-2');

    expect($page->data)->toBe(['first', 'second'])
        ->and($page->total)->toBe(42)
        ->and($page->nextCursor)->toBe('cursor-2')
        ->and($page->isEmpty())->toBeFalse()
        ->and($page->isLastPage())->toBeFalse();
});

it('reports the final page by a null cursor', function (): void {
    $page = new Page(['only'], 1);

    expect($page->nextCursor)->toBeNull()
        ->and($page->isLastPage())->toBeTrue();
});

it('builds the item-free final page', function (): void {
    $page = Page::empty();

    expect($page->data)->toBe([])
        ->and($page->total)->toBe(0)
        ->and($page->nextCursor)->toBeNull()
        ->and($page->isEmpty())->toBeTrue()
        ->and($page->isLastPage())->toBeTrue();
});

it('allows an empty page of a non-zero total, as a cursor past the end gives', function (): void {
    $page = new Page([], 42, null);

    expect($page->isEmpty())->toBeTrue()
        ->and($page->total)->toBe(42);
});

it('rejects a negative total', function (): void {
    expect(static fn (): Page => new Page([], -1))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a page holding more items than the total', function (): void {
    expect(static fn (): Page => new Page(['first', 'second'], 1))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a blank next-page cursor', function (string $cursor): void {
    expect(static fn (): Page => new Page(['only'], 2, $cursor))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => [''],
    'spaces' => ['   '],
]);

it('renders the response envelope in snake_case', function (): void {
    expect((new Page(['first'], 3, 'cursor-2'))->toEnvelope())->toBe([
        'data' => ['first'],
        'total' => 3,
        'next_cursor' => 'cursor-2',
    ])->and(Page::empty()->toEnvelope())->toBe([
        'data' => [],
        'total' => 0,
        'next_cursor' => null,
    ]);
});

it('publishes the documented page-size bounds', function (): void {
    expect(Page::DEFAULT_PAGE_SIZE)->toBe(20)
        ->and(Page::MIN_PAGE_SIZE)->toBe(1)
        ->and(Page::MAX_PAGE_SIZE)->toBe(100);
});
