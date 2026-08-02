<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateUserPreferencesRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * PATCH /me/preferences — the authenticated user's own display preferences (Phase UI-04; ADR-021).
 *
 * OWN SCOPE ONLY. The subject is resolved from the authenticated session, never from the payload,
 * so there is no request that can address another user. That is why UI-04 adds **no permission
 * key** and makes **no permission-matrix change**: this is authorized by ownership, exactly like
 * own-session revocation in UI-03.
 *
 * It is deliberately not a tenant mutation — the value lives on the identity row, carries no
 * authority, and one user's theme can never affect another's.
 */
final class UserPreferencesController extends Controller
{
    public function update(UpdateUserPreferencesRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->theme_preference = $request->themePreference();
        $user->save();

        return response()->json([
            'data' => [
                // Echo the STORED value (null when cleared) plus the RESOLVED theme, so the client
                // never has to re-implement the "absence means light" rule.
                'theme_preference' => $user->theme_preference?->value,
                'resolved_theme' => $user->resolvedTheme()->value,
            ],
        ]);
    }
}
