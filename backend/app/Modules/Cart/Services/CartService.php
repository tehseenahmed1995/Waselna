<?php

declare(strict_types=1);

namespace App\Modules\Cart\Services;

use App\Modules\Catalog\Models\MenuItem;
use App\Modules\Catalog\Models\Restaurant;
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Domain\Exceptions\ConflictException;
use App\Shared\Domain\Exceptions\ValidationException;
use App\Shared\Support\Clock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;

/**
 * The Cart use cases (Requirement 6). The Cart is held in the Cache_Store under
 * `cart:v1:{customerId}` with a 72-hour sliding expiry reset on each accepted
 * write. Cart lines carry ULID identifiers.
 *
 * Invariants enforced here:
 *  - every line belongs to the same Restaurant (`cart_restaurant_conflict`);
 *  - an item must exist and be available when added (`item_unavailable`);
 *  - at most 50 lines (`cart_line_limit_reached`);
 *  - quantity 0 removes the line.
 */
final class CartService
{
    private const KEY_PREFIX = 'cart:v1:';

    private const TTL_SECONDS = 72 * 3600;

    private const MAX_LINES = 50;

    public function __construct(
        private readonly Cache $cache,
        private readonly Clock $clock,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function view(string $customerId): array
    {
        return $this->present($this->read($customerId));
    }

    /**
     * @return array<string, mixed>
     */
    public function addItem(string $customerId, string $menuItemId, int $quantity): array
    {
        if ($quantity < 1) {
            throw ValidationException::forField('quantity', 'min:1');
        }

        /** @var MenuItem|null $item */
        $item = MenuItem::query()->find($menuItemId);

        if ($item === null) {
            throw AuthorizationException::notFound('menu_item_not_found', 'No Menu_Item matches that identifier.');
        }

        if (! $item->is_available) {
            throw ValidationException::because('item_unavailable', 'That Menu_Item is not available.');
        }

        $cart = $this->read($customerId);

        if ($cart['restaurant_id'] !== null && $cart['restaurant_id'] !== $item->restaurant_id) {
            throw ConflictException::because(
                'cart_restaurant_conflict',
                'The Cart already holds items from another Restaurant.',
            );
        }

        // Fold into an existing identical line when one exists.
        foreach ($cart['lines'] as &$line) {
            if ($line['menu_item_id'] === $menuItemId) {
                $line['quantity'] += $quantity;
                unset($line);

                return $this->persist($customerId, $cart);
            }
        }
        unset($line);

        if (count($cart['lines']) >= self::MAX_LINES) {
            throw ValidationException::because('cart_line_limit_reached', 'The Cart cannot hold more than 50 lines.');
        }

        $cart['restaurant_id'] = $item->restaurant_id;
        $cart['lines'][] = [
            'id' => (string) Str::ulid(),
            'menu_item_id' => $item->id,
            'name' => $item->name,
            'unit_price_minor' => $item->price_minor,
            'quantity' => $quantity,
        ];

        return $this->persist($customerId, $cart);
    }

    /**
     * @return array<string, mixed>
     */
    public function setQuantity(string $customerId, string $lineId, int $quantity): array
    {
        if ($quantity < 0) {
            throw ValidationException::forField('quantity', 'min:0');
        }

        $cart = $this->read($customerId);
        $found = false;

        foreach ($cart['lines'] as $index => $line) {
            if ($line['id'] !== $lineId) {
                continue;
            }

            $found = true;

            if ($quantity === 0) {
                unset($cart['lines'][$index]);
            } else {
                $cart['lines'][$index]['quantity'] = $quantity;
            }
            break;
        }

        if (! $found) {
            throw AuthorizationException::notFound('cart_line_not_found', 'No Cart_Line matches that identifier.');
        }

        $cart['lines'] = array_values($cart['lines']);

        if ($cart['lines'] === []) {
            $cart['restaurant_id'] = null;
        }

        return $this->persist($customerId, $cart);
    }

    /**
     * @return array<string, mixed>
     */
    public function removeLine(string $customerId, string $lineId): array
    {
        return $this->setQuantity($customerId, $lineId, 0);
    }

    public function clear(string $customerId): void
    {
        $this->cache->forget(self::KEY_PREFIX.$customerId);
    }

    /**
     * The raw Cart document, consumed by Order checkout.
     *
     * @return array{restaurant_id: ?string, lines: list<array<string, mixed>>}
     */
    public function read(string $customerId): array
    {
        /** @var array{restaurant_id: ?string, lines: list<array<string, mixed>>}|null $cart */
        $cart = $this->cache->get(self::KEY_PREFIX.$customerId);

        if (! is_array($cart)) {
            return ['restaurant_id' => null, 'lines' => []];
        }

        return $cart;
    }

    /**
     * @param  array{restaurant_id: ?string, lines: list<array<string, mixed>>}  $cart
     * @return array<string, mixed>
     */
    private function persist(string $customerId, array $cart): array
    {
        $this->cache->put(self::KEY_PREFIX.$customerId, $cart, self::TTL_SECONDS);

        return $this->present($cart);
    }

    /**
     * @param  array{restaurant_id: ?string, lines: list<array<string, mixed>>}  $cart
     * @return array<string, mixed>
     */
    private function present(array $cart): array
    {
        $currency = 'USD';

        if ($cart['restaurant_id'] !== null) {
            /** @var Restaurant|null $restaurant */
            $restaurant = Restaurant::query()->find($cart['restaurant_id']);
            $currency = $restaurant?->currency ?? 'USD';
        }

        $subtotal = 0;
        $lines = [];

        foreach ($cart['lines'] as $line) {
            $lineSubtotal = (int) $line['unit_price_minor'] * (int) $line['quantity'];
            $subtotal += $lineSubtotal;
            $lines[] = [
                'id' => $line['id'],
                'menu_item_id' => $line['menu_item_id'],
                'name' => $line['name'],
                'quantity' => (int) $line['quantity'],
                'unit_price' => ['amount' => (int) $line['unit_price_minor'], 'currency' => $currency],
                'line_subtotal' => ['amount' => $lineSubtotal, 'currency' => $currency],
            ];
        }

        return [
            'restaurant_id' => $cart['restaurant_id'],
            'currency' => $currency,
            'lines' => $lines,
            'line_subtotal' => ['amount' => $subtotal, 'currency' => $currency],
            'updated_at' => $this->clock->nowUtc()->format(DATE_ATOM),
        ];
    }
}
