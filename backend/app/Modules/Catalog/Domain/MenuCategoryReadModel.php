<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

/**
 * The Customer-facing read-model projection of a Menu_Category with its
 * Menu_Items (Requirement 4.1).
 *
 * A Menu_Category with no Menu_Item is carried with an empty `items` list rather
 * than omitted (Requirement 4.7). Items are ordered by ascending display-order
 * then ascending name.
 */
final readonly class MenuCategoryReadModel
{
    /**
     * @param  list<MenuItemReadModel>  $items  ordered by ascending display-order
     *                                          then ascending name
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $displayOrder,
        public array $items,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     display_order: int,
     *     items: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'display_order' => $this->displayOrder,
            'items' => array_map(
                static fn (MenuItemReadModel $i): array => $i->toArray(),
                $this->items,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $data  a decoded cache entry
     */
    public static function fromArray(array $data): self
    {
        /** @var list<array<string, mixed>> $items */
        $items = array_values((array) $data['items']);

        return new self(
            id: (string) $data['id'],
            name: (string) $data['name'],
            displayOrder: (int) $data['display_order'],
            items: array_map(
                static fn (array $i): MenuItemReadModel => MenuItemReadModel::fromArray($i),
                $items,
            ),
        );
    }
}
