<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

/**
 * The complete, ordered, availability-preserving Menu read model for a published
 * Restaurant (Requirement 4, Property 12).
 *
 * It carries every Menu_Category and every Menu_Item of the Restaurant's Menu —
 * including items whose availability flag is false, which are marked unavailable
 * and fully populated rather than omitted (Requirement 4.4). Both levels are
 * ordered by ascending configured display-order value and, on a tie, by
 * ascending name (Requirement 4.1). The ISO 4217 currency code is carried once
 * at the top, so each item's integer-minor-unit Price is unambiguous without a
 * second lookup (Requirement 4.2).
 *
 * The value is immutable and framework-free. {@see self::toArray()} is the exact
 * JSON shape stored in the `menu:v1:{restaurantId}` cache entry, and
 * {@see self::fromArray()} rebuilds it; because the cached entry is produced from
 * the same projection the PostgreSQL fallback returns, a cached read is
 * byte-identical to an uncached read (Requirement 4.5, Property 13).
 */
final readonly class MenuReadModel
{
    /**
     * @param  list<MenuCategoryReadModel>  $categories  ordered by ascending
     *                                                   display-order then ascending name
     */
    public function __construct(
        public string $restaurantId,
        public string $currency,
        public array $categories,
    ) {}

    /**
     * @return array{
     *     restaurant_id: string,
     *     currency: string,
     *     categories: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'restaurant_id' => $this->restaurantId,
            'currency' => $this->currency,
            'categories' => array_map(
                static fn (MenuCategoryReadModel $c): array => $c->toArray(),
                $this->categories,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $data  a decoded cache entry
     */
    public static function fromArray(array $data): self
    {
        /** @var list<array<string, mixed>> $categories */
        $categories = array_values((array) $data['categories']);

        return new self(
            restaurantId: (string) $data['restaurant_id'],
            currency: (string) $data['currency'],
            categories: array_map(
                static fn (array $c): MenuCategoryReadModel => MenuCategoryReadModel::fromArray($c),
                $categories,
            ),
        );
    }
}
