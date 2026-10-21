<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

/**
 * The Customer-facing read-model projection of a Menu_Item with its
 * Modifier_Groups (Requirements 4.2, 4.4).
 *
 * A Menu_Item whose availability flag is false is NOT omitted: it is carried in
 * full with `isAvailable` false so the Web_Client can show it struck through
 * (Requirement 4.4). The Price is an integer number of minor currency units; the
 * ISO 4217 currency code lives once on the owning {@see MenuReadModel}
 * (Requirement 4.2).
 */
final readonly class MenuItemReadModel
{
    /**
     * @param  list<ModifierGroupReadModel>  $modifierGroups  ordered by ascending
     *                                                        display-order then ascending name
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public int $priceMinor,
        public bool $isAvailable,
        public int $displayOrder,
        public array $modifierGroups,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     description: ?string,
     *     price_minor: int,
     *     is_available: bool,
     *     display_order: int,
     *     modifier_groups: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price_minor' => $this->priceMinor,
            'is_available' => $this->isAvailable,
            'display_order' => $this->displayOrder,
            'modifier_groups' => array_map(
                static fn (ModifierGroupReadModel $g): array => $g->toArray(),
                $this->modifierGroups,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $data  a decoded cache entry
     */
    public static function fromArray(array $data): self
    {
        /** @var list<array<string, mixed>> $groups */
        $groups = array_values((array) $data['modifier_groups']);

        return new self(
            id: (string) $data['id'],
            name: (string) $data['name'],
            description: $data['description'] === null ? null : (string) $data['description'],
            priceMinor: (int) $data['price_minor'],
            isAvailable: (bool) $data['is_available'],
            displayOrder: (int) $data['display_order'],
            modifierGroups: array_map(
                static fn (array $g): ModifierGroupReadModel => ModifierGroupReadModel::fromArray($g),
                $groups,
            ),
        );
    }
}
