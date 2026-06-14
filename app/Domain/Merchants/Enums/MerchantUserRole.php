<?php

declare(strict_types=1);

namespace App\Domain\Merchants\Enums;

/**
 * The seven merchant account-type roles (Plan §7.1, §10.2; Scope §3.2–§3.8).
 *
 * Mirrors the merchant_users.role DB CHECK. The permission registry that maps
 * each role to capability keys is Phase 8; Phase 6 only needs the role identity
 * to assign the owner (`MerchantAdmin`) and the initial staff (`BranchManager`,
 * `Hr`) during first-time setup.
 */
enum MerchantUserRole: string
{
    case MerchantAdmin = 'merchant_admin';
    case BranchManager = 'branch_manager';
    case Hr = 'hr';
    case Finance = 'finance';
    case FrontOffice = 'front_office';
    case Personnel = 'personnel';
    case Audit = 'audit';
}
