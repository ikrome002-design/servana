<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Hr;

use App\Domain\Hr\Actions\AcceptStaffInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\AcceptStaffInvitationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Accept a staff invitation (Scope §3.4). PUBLIC — the invitee has no session
 * yet. On success the membership + staff profile + branch assignment are created
 * and the user is told to sign in via Magic Link (no password auth exists).
 */
final class StaffInvitationAcceptController extends Controller
{
    public function store(AcceptStaffInvitationRequest $request, AcceptStaffInvitation $action): JsonResponse
    {
        $validated = $request->validated();

        $action->handle((string) $validated['token'], [
            'first_name' => (string) $validated['first_name'],
            'last_name' => (string) $validated['last_name'],
            'phone' => (string) $validated['phone'],
        ]);

        return response()->json([
            'message' => 'Your account is ready. Use your email to request a secure sign-in link.',
        ], Response::HTTP_CREATED);
    }
}
