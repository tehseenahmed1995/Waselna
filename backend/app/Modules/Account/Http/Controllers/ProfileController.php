<?php

declare(strict_types=1);

namespace App\Modules\Account\Http\Controllers;

use App\Modules\Account\Contracts\Services\ProfileService;
use App\Modules\Account\Domain\RequestEmailChangeCommand;
use App\Modules\Account\Domain\UpdateProfileCommand;
use App\Modules\Account\Http\Requests\ChangePasswordRequest;
use App\Modules\Account\Http\Requests\ConfirmEmailChangeRequest;
use App\Modules\Account\Http\Requests\RequestEmailChangeRequest;
use App\Modules\Account\Http\Requests\UpdateProfileRequest;
use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Auth\Domain\ChangePasswordCommand;
use App\Shared\Domain\Exceptions\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The own-profile endpoints (Requirement 31.1–31.6, 31.12–31.14). The Controller
 * holds no rules: it resolves the acting Customer, hands the Service a command,
 * and renders the result.
 */
final class ProfileController
{
    public function __construct(private readonly ProfileService $profiles) {}

    public function show(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        return new JsonResponse(['data' => $this->profiles->getOwnProfile($actor->userId)->toArray()], 200);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $actor = $this->actor($request);

        $view = $this->profiles->updateProfile(
            UpdateProfileCommand::fromValidated($actor->userId, $request->validated()),
        );

        return new JsonResponse(['data' => $view->toArray()], 200);
    }

    public function requestEmailChange(RequestEmailChangeRequest $request): JsonResponse
    {
        $actor = $this->actor($request);

        $this->profiles->requestEmailChange(new RequestEmailChangeCommand(
            $actor->userId,
            $request->string('email')->toString(),
        ));

        // The plaintext token is delivered out of band (Notification), never in
        // the response body.
        return new JsonResponse(['data' => ['status' => 'pending_confirmation']], 202);
    }

    public function confirmEmailChange(ConfirmEmailChangeRequest $request): JsonResponse
    {
        $actor = $this->actor($request);

        $view = $this->profiles->confirmEmailChange(
            $actor->userId,
            $request->string('token')->toString(),
        );

        return new JsonResponse(['data' => $view->toArray()], 200);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $actor = $this->actor($request);

        $presentedRefreshToken = $request->has('refresh_token')
            ? $request->string('refresh_token')->toString()
            : null;

        $this->profiles->changePassword(new ChangePasswordCommand(
            $actor->userId,
            $request->string('current_password')->toString(),
            $request->string('new_password')->toString(),
            $presentedRefreshToken === '' ? null : $presentedRefreshToken,
        ));

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
