<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Stable machine-readable error codes for the API error envelope (Plan §11.5).
 * Only cross-cutting codes live here; domain-specific codes (duplicate_reference,
 * period_locked, invalid_state_transition, …) are added by their owning phases.
 */
enum ErrorCode: string
{
    case ValidationFailed = 'validation_failed';
    case Unauthenticated = 'unauthenticated';
    case PermissionDenied = 'permission_denied';
    case NotFound = 'not_found';
    case MethodNotAllowed = 'method_not_allowed';
    case Conflict = 'conflict';
    case RateLimited = 'rate_limited';
    case ServiceUnavailable = 'service_unavailable';
    case InternalError = 'internal_error';
    /**
     * The request did not arrive on an approved Servana account host (Phase UI-03; ADR-016).
     * Distinct from 404: the RESOURCE may exist, the ORIGIN is wrong. Carries no host detail, so
     * it never becomes an oracle for which hosts are approved.
     */
    case MisdirectedRequest = 'misdirected_request';

    public function httpStatus(): int
    {
        return match ($this) {
            self::ValidationFailed => 422,
            self::Unauthenticated => 401,
            self::PermissionDenied => 403,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::Conflict => 409,
            self::MisdirectedRequest => 421,
            self::RateLimited => 429,
            self::ServiceUnavailable => 503,
            self::InternalError => 500,
        };
    }

    /** Map an arbitrary HTTP status to the closest stable code (default: internal_error). */
    public static function fromHttpStatus(int $status): self
    {
        return match ($status) {
            401 => self::Unauthenticated,
            403 => self::PermissionDenied,
            404 => self::NotFound,
            405 => self::MethodNotAllowed,
            409 => self::Conflict,
            421 => self::MisdirectedRequest,
            422 => self::ValidationFailed,
            429 => self::RateLimited,
            503 => self::ServiceUnavailable,
            default => self::InternalError,
        };
    }
}
