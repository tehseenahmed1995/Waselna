<?php

declare(strict_types=1);

namespace App\Modules\Account\Http\Controllers;

use App\Modules\Account\Contracts\Services\AddressService;
use App\Modules\Account\Domain\AddressView;
use App\Modules\Account\Domain\CreateAddressCommand;
use App\Modules\Account\Domain\UpdateAddressCommand;
use App\Modules\Account\Http\Requests\StoreAddressRequest;
use App\Modules\Auth\Domain\ActorContext;
use App\Shared\Domain\Exceptions\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The saved Delivery_Address endpoints (Requirements 31.7–31.11). The Controller
 * holds no rules: it resolves the acting Customer, hands the Service a command,
 * and renders the result. Every documented rejection — the 20-address bound, the
 * non-disclosing 404 — is raised inside the Service and rendered by the single
 * exception pipeline, so no error body is composed here.
 */
final class AddressController
{
    public function __construct(private readonly AddressService $addresses) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        $data = array_map(
            static fn (AddressView $view): array => $view->toArray(),
            $this->addresses->listOwn($actor->userId),
        );

        return new JsonResponse(['data' => $data, 'total' => count($data), 'next_cursor' => null], 200);
    }

    public function store(StoreAddressRequest $request): JsonResponse
    {
        $actor = $this->actor($request);

        $view = $this->addresses->create(
            CreateAddressCommand::fromValidated($actor->userId, $request->validated()),
        );

        return new JsonResponse(['data' => $view->toArray()], 201);
    }

    public function update(StoreAddressRequest $request, string $addressId): JsonResponse
    {
        $actor = $this->actor($request);

        $view = $this->addresses->update(
            UpdateAddressCommand::fromValidated($actor->userId, $addressId, $request->validated()),
        );

        return new JsonResponse(['data' => $view->toArray()], 200);
    }

    public function markDefault(Request $request, string $addressId): JsonResponse
    {
        $actor = $this->actor($request);

        $this->addresses->markDefault($actor->userId, $addressId);

        return new JsonResponse(['data' => ['status' => 'ok']], 200);
    }

    public function destroy(Request $request, string $addressId): JsonResponse
    {
        $actor = $this->actor($request);

        $this->addresses->archive($actor->userId, $addressId);

        return new JsonResponse(['data' => ['status' => 'ok']], 200);
    }

    private function actor(Request $request): ActorContext
    {
        $actor = $request->attributes->get('actor');

        if (! $actor instanceof ActorContext) {
            throw AuthorizationException::unauthenticated();
        }

        return $actor;
    }
}
