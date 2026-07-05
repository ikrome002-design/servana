<?php

declare(strict_types=1);

namespace App\Domain\Files\Services;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Files\FilePurposeRegistry;
use App\Domain\Files\Models\UploadedFile;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Central file authorization (Plan §9, §65, §73; Phase 10F). The single place that
 * decides who may view/download a file. Re-checked at BOTH link issuance and the
 * actual download — a valid signature is transport, never authorization.
 *
 * Foreign-tenant and foreign-owner files 404 (no existence leak); out-of-branch and
 * missing-permission are 403; an unavailable/uncleaned/missing object is never
 * downloadable. Storage disk/paths are never exposed.
 */
final class FileAccessService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    /** Authorise a read/metadata view (and link issuance). Throws 404/403 otherwise. */
    public function authorizeView(UploadedFile $file, User $user): void
    {
        $definition = FilePurposeRegistry::for($file->purpose);

        // Tenant scope — foreign merchant 404; platform files are platform-only.
        if ($file->merchant_id !== null) {
            if ($file->merchant_id !== $this->context->merchantId()) {
                $this->deny($file, $user, 'foreign_tenant', notFound: true);
            }
        } elseif (! $this->context->isPlatformStaff()) {
            $this->deny($file, $user, 'platform_only', notFound: true);
        }

        // Owner own-scope — another person's file is 404 (no existence leak).
        if ($definition->requiresOwner && $file->owner_user_id !== $user->id) {
            $this->deny($file, $user, 'foreign_owner', notFound: true);
        }

        // Branch scope — same tenant, out of branch → 403.
        if ($file->branch_id !== null
            && $this->context->isBranchScoped()
            && ! $this->context->canAccessBranch($file->branch_id)) {
            $this->deny($file, $user, 'no_branch_scope', notFound: false);
        }

        // Purpose permission (when the purpose declares one; own-scope purposes
        // are authorised by ownership above and carry no extra permission).
        if ($definition->permission !== null && ! $this->context->can($definition->permission)) {
            $this->deny($file, $user, 'permission_denied', notFound: false);
        }
    }

    /** Authorise the actual download: view rules PLUS clean+available+object present. */
    public function authorizeDownload(UploadedFile $file, User $user): void
    {
        $this->authorizeView($file, $user);

        if (! $file->isDownloadable()) {
            $this->deny($file, $user, 'not_downloadable', notFound: true);
        }

        if ($file->final_path === null || ! Storage::disk($file->storage_disk)->exists($file->final_path)) {
            $this->deny($file, $user, 'object_missing', notFound: true);
        }
    }

    /** @return array{url: string, expires_at: string} short-lived signed download link. */
    public function issueSignedUrl(UploadedFile $file): array
    {
        return $this->signDownloadRoute('files.download', ['uploadedFile' => $file->ulid]);
    }

    /**
     * Sign a short-lived link to an authorized download-STREAM route (Plan §65, §73).
     * Route signing for private files is confined to the file domain by the storage-
     * boundary guard; a domain that must account downloads on its own stream route
     * (e.g. `audit-exports.download` for the stream-time accounting mandated by ADR-010)
     * signs it here rather than issuing a `temporarySignedRoute` itself.
     *
     * @param  array<string, mixed>  $parameters
     * @return array{url: string, expires_at: string}
     */
    public function signDownloadRoute(string $routeName, array $parameters): array
    {
        $expires = now()->addMinutes((int) config('files.signed_url_ttl_minutes', 5));

        $url = URL::temporarySignedRoute($routeName, $expires, $parameters);

        return ['url' => $url, 'expires_at' => $expires->toIso8601String()];
    }

    private function deny(UploadedFile $file, User $user, string $reason, bool $notFound): never
    {
        $this->audit->record(AuditEvent::FileAccessDenied, $user, $file->merchant_id, $file->branch_id, $file, [
            'purpose' => $file->purpose->value,
            'reason' => $reason,
        ]);

        throw $notFound
            ? new NotFoundHttpException
            : new AccessDeniedHttpException('This action is unauthorized.');
    }
}
