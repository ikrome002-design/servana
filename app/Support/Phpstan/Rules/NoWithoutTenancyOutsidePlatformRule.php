<?php

declare(strict_types=1);

namespace App\Support\Phpstan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Guardrail (CLAUDE.md §6.3 / Plan §8.2): the tenant-scope escape hatches —
 * `withoutTenancy()`, `withoutGlobalScope()`, `withoutGlobalScopes()` — may only
 * be called from Platform-scope or Tenancy-infrastructure code (the traits/scopes
 * that DEFINE the escape, and audited platform services). Any other call site is
 * a tenant-isolation bypass and fails static analysis (Plan §27 Phase 9).
 *
 * @implements Rule<Node>
 */
final class NoWithoutTenancyOutsidePlatformRule implements Rule
{
    /** @var list<string> */
    private const BANNED_METHODS = [
        'withouttenancy',
        'withoutglobalscope',
        'withoutglobalscopes',
    ];

    /**
     * Namespaces where bypassing tenant scope is legitimate: the tenancy traits
     * and scopes themselves, and (future) audited Platform services.
     *
     * @var list<string>
     */
    private const ALLOWED_NAMESPACE_PREFIXES = [
        'App\\Domain\\Tenancy',
        'App\\Domain\\Platform',
    ];

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node instanceof MethodCall && ! $node instanceof StaticCall) {
            return [];
        }

        if (! $node->name instanceof Identifier) {
            return [];
        }

        if (! in_array(strtolower($node->name->toString()), self::BANNED_METHODS, true)) {
            return [];
        }

        if ($this->isAllowedLocation($scope)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Calling %s() outside App\\Domain\\Tenancy or App\\Domain\\Platform bypasses tenant isolation (Plan §8.2).',
                $node->name->toString(),
            ))->identifier('servana.tenancy.withoutTenancy')->build(),
        ];
    }

    private function isAllowedLocation(Scope $scope): bool
    {
        $namespace = $scope->getNamespace() ?? '';

        foreach (self::ALLOWED_NAMESPACE_PREFIXES as $prefix) {
            if ($namespace === $prefix || str_starts_with($namespace, $prefix.'\\')) {
                return true;
            }
        }

        return false;
    }
}
