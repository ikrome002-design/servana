<?php

declare(strict_types=1);

namespace App\Support\Phpstan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * Guardrail (Plan §3 rule, §24 threat model): raw SQL built by string
 * concatenation (e.g. `whereRaw('... '.$value)`, `DB::raw('...'.$x)`) is an
 * injection vector and is forbidden. Bindings must be used instead.
 *
 * Phase 1 status: registered placeholder so the rule wiring and CI gate exist
 * from day one. Full detection logic is implemented in Phase 9 (Plan §27),
 * alongside the data-access hardening pass.
 *
 * @implements Rule<Node>
 */
final class NoRawSqlConcatRule implements Rule
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
