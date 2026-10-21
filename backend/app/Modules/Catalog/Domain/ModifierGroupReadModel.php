<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

/**
 * The Customer-facing read-model projection of a Modifier_Group with its
 * Modifier_Options (Requirements 4.2, 4.3).
 *
 * The selection counts satisfy 0 ≤ minimum ≤ maximum ≤ 50 (Requirement 4.3);
 * that invariant is a stored-data guarantee of task 8.1 and is preserved, not
 * re-derived, here. Options are carried in their configured display order so the
 * projection is deterministic.
 */
final readonly class ModifierGroupReadModel
{
    /**
     * @param  list<ModifierOptionReadModel>  $options  ordered by ascending
     *                                                  display-order then ascending name
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $minSelections,
        public int $maxSelections,
        public int $displayOrder,
        public array $options,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     min_selections: int,
     *     max_selections: int,
     *     display_order: int,
     *     options: list<array{id: string, name: string, price_delta_minor: int, display_order: int}>
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'min_selections' => $this->minSelections,
            'max_selections' => $this->maxSelections,
            'display_order' => $this->displayOrder,
            'options' => array_map(
                static fn (ModifierOptionReadModel $o): array => $o->toArray(),
                $this->options,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $data  a decoded cache entry
     */
    public static function fromArray(array $data): self
    {
        /** @var list<array<string, mixed>> $options */
        $options = array_values((array) $data['options']);

        return new self(
            id: (string) $data['id'],
            name: (string) $data['name'],
            minSelections: (int) $data['min_selections'],
            maxSelections: (int) $data['max_selections'],
            displayOrder: (int) $data['display_order'],
            options: array_map(
                static fn (array $o): ModifierOptionReadModel => ModifierOptionReadModel::fromArray($o),
                $options,
            ),
        );
    }
}
