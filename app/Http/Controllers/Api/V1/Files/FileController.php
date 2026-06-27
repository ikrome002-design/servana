<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Files;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Files\Enums\FilePurpose;
use App\Domain\Files\Models\UploadedFile;
use App\Domain\Files\Services\FileAccessService;
use App\Domain\Files\Services\FileUploadPipeline;
use App\Http\Controllers\Controller;
use App\Http\Requests\Files\StoreFileRequest;
use App\Http\Resources\FileResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * File domain HTTP surface (Plan §65; Phase 10F). Thin: validate → delegate to the
 * file-domain services → safe resource/stream. Storage paths and hashes are never
 * exposed; downloads require BOTH a valid temporary signature AND authentication,
 * with authorization re-checked at issuance and at download.
 */
final class FileController extends Controller
{
    public function store(StoreFileRequest $request, FileUploadPipeline $pipeline): JsonResponse
    {
        $validated = $request->validated();
        /** @var User $actor */
        $actor = $request->user();

        $ownerUserId = isset($validated['owner_user_id']) ? (int) $validated['owner_user_id'] : null;

        $file = $pipeline->accept(
            FilePurpose::from((string) $validated['purpose']),
            $request->file('file'),
            $actor,
            $ownerUserId,
        );

        return FileResource::make($file)->response()->setStatusCode(202);
    }

    public function show(UploadedFile $uploadedFile, FileAccessService $access, Request $request): FileResource
    {
        /** @var User $user */
        $user = $request->user();
        $access->authorizeView($uploadedFile, $user);

        return FileResource::make($uploadedFile);
    }

    public function downloadLink(UploadedFile $uploadedFile, FileAccessService $access, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        // A link is only issued for a file the caller may actually download now.
        $access->authorizeDownload($uploadedFile, $user);

        return response()->json(['data' => $access->issueSignedUrl($uploadedFile)]);
    }

    public function download(Request $request, UploadedFile $uploadedFile, FileAccessService $access, AuditRecorder $audit): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        // Re-check current authorization at download time (signature alone is not it).
        $access->authorizeDownload($uploadedFile, $user);

        $audit->record(AuditEvent::FileDownloaded, $user, $uploadedFile->merchant_id, $uploadedFile->branch_id, $uploadedFile, [
            'purpose' => $uploadedFile->purpose->value,
        ]);

        return Storage::disk($uploadedFile->storage_disk)->download(
            (string) $uploadedFile->final_path,
            $uploadedFile->safe_download_filename,
            [
                'Content-Type' => (string) ($uploadedFile->detected_mime_type ?? 'application/octet-stream'),
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store, private',
            ],
        );
    }
}
