<?php

declare(strict_types=1);

namespace App\Http\Requests\Files;

use App\Domain\Files\Enums\FilePurpose;
use App\Domain\Files\FilePurposeRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validated multipart upload request (Plan §65; Phase 10F). Only currently
 * uploadable purposes are accepted at the request layer; the pipeline performs the
 * authoritative server-side content validation (magic-byte MIME, dangerous-file
 * rejection). A coarse size ceiling here fails huge uploads fast; the per-purpose
 * byte limit is enforced in the pipeline.
 */
final class StoreFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // pipeline + FileAccessService are the authorization boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $uploadable = array_map(
            static fn (FilePurpose $p): string => $p->value,
            FilePurposeRegistry::uploadablePurposes(),
        );

        $maxKb = (int) ceil(((int) config('files.image_max_bytes', 5 * 1024 * 1024)) / 1024);

        return [
            'purpose' => ['required', 'string', Rule::in($uploadable)],
            'file' => ['required', 'file', 'max:'.$maxKb],
            'owner_user_id' => ['sometimes', 'integer'],
        ];
    }
}
