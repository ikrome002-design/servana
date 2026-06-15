<?php

declare(strict_types=1);

namespace App\Domain\Branches\Enums;

/** Cash-up state (Plan §7.2 seam; full workflow Phase 18). Mirrors the DB CHECK. */
enum CashUpStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
