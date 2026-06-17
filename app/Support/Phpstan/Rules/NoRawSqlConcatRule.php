<?php

declare(strict_types=1);

namespace App\Support\Phpstan\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\VariadicPlaceholder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Guardrail (Plan §3 rule, §24 threat model): raw SQL built by string
 * concatenation or interpolation (e.g. `whereRaw('... '.$value)`,
 * `DB::statement("... {$x}")`) is a SQL-injection vector and is forbidden —
 * parameter bindings must be used instead. A plain string literal (no variable)
 * is allowed.
 *
 * @implements Rule<Node>
 */
final class NoRawSqlConcatRule implements Rule
{
    /**
     * Methods/functions whose first argument is raw SQL.
     *
     * @var list<string>
     */
    private const RAW_SQL_CALLS = [
        'whereraw',
        'orwhereraw',
        'havingraw',
        'orhavingraw',
        'orderbyraw',
        'groupbyraw',
        'selectraw',
        'fromraw',
        'raw',
        'statement',
        'unprepared',
        'select',
        'selectone',
        'insert',
        'update',
        'delete',
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
        $name = $this->callName($node);

        if ($name === null || ! in_array(strtolower($name), self::RAW_SQL_CALLS, true)) {
            return [];
        }

        $args = $this->callArgs($node);
        $first = $args[0] ?? null;

        if (! $first instanceof Arg) {
            return [];
        }

        // Only a concatenation or an interpolated string with variables is unsafe;
        // a constant string literal passed as raw SQL is fine.
        if (! $first->value instanceof Concat && ! $first->value instanceof InterpolatedString) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Raw SQL built by string concatenation/interpolation is a SQL-injection vector (Plan §3/§24); use parameter bindings instead.',
            )->identifier('servana.security.rawSqlConcat')->build(),
        ];
    }

    private function callName(Node $node): ?string
    {
        if (($node instanceof MethodCall || $node instanceof StaticCall) && $node->name instanceof Identifier) {
            return $node->name->toString();
        }

        if ($node instanceof FuncCall && $node->name instanceof Node\Name) {
            return $node->name->toString();
        }

        return null;
    }

    /**
     * @return array<int, Arg|VariadicPlaceholder>
     */
    private function callArgs(Node $node): array
    {
        if ($node instanceof MethodCall || $node instanceof StaticCall || $node instanceof FuncCall) {
            return $node->getArgs();
        }

        return [];
    }
}
