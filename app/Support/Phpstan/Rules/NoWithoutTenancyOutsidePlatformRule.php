<?php

declare(strict_types=1);

namespace App\Support\Phpstan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * Guardrail (CLAUDE.md §6.3 / Plan §8): `withoutTenancy()` may only be called
 * inside Platform-scope services. Any other call site is a tenant-isolation
 * escape hatch and must fail static analysis.
 *
 * Phase 1 status: registered placeholder so the rule wiring and CI gate exist
 * from day one. Full detection logic is implemented in Phase 9 (Plan §27 —
 * "PHPStan tenancy rule active"), where the tenancy traits also land.
 *
 * @implements Rule<Node>
 */
final class NoWithoutTenancyOutsidePlatformRule implements Rule
{
    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // Intentionally no-op until Phase 9. See class docblock.
        return [];
    }
}
