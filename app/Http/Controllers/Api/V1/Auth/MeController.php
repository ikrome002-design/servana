<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Auth\AuthenticatedUserResource;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * GET /me — authenticated bootstrap payload for the SPA authStore (Plan §6.2).
 * Auth is enforced by the `auth:sanctum` middleware on the route.
 */
final class MeController extends Controller
{
    public function show(Request $request): AuthenticatedUserResource
    {
        /** @var User $user */
        $user = $request->user();

        return AuthenticatedUserResource::make($user);
    }
}
