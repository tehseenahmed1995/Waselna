<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Auth\Http\Requests\RefreshRequest;
use App\Modules\Auth\Http\Requests\RegisterRequest;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\AuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuthController
{
    public function __construct(private readonly AuthenticationService $auth) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->auth->register(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->string('display_name')->toString(),
            $request->string('role', 'customer')->toString(),
            $request->filled('restaurant_name') ? $request->string('restaurant_name')->toString() : null,
        );

        return new JsonResponse(['data' => $user], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $tokens = $this->auth->login(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );

        return new JsonResponse(['data' => $tokens], 201);
    }

    public function refresh(RefreshRequest $request): JsonResponse
    {
        $tokens = $this->auth->refresh($request->string('refresh_token')->toString());

        return new JsonResponse(['data' => $tokens], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->attributes->get('access_token');

        if (is_string($token)) {
            $this->auth->logout($token);
        }

        return new JsonResponse(['data' => ['status' => 'ok']], 200);
    }

    public function me(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('actor');
        $userId = $actor?->userId;

        /** @var User|null $user */
        $user = $userId !== null ? User::query()->find($userId) : null;

        return new JsonResponse(['data' => [
            'id' => $userId,
            'role' => $actor?->role,
            'account_status' => $actor?->accountStatus,
            'display_name' => $user?->display_name,
            'email' => $user?->email,
            'restaurant_id' => $user?->restaurant_id,
            'branch_id' => $user?->branch_id,
        ]], 200);
    }
}
