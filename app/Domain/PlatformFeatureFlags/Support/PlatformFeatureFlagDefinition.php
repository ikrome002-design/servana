<?php

declare(strict_types=1);

namespace App\Domain\PlatformFeatureFlags\Support;

use App\Domain\PlatformFeatureFlags\Enums\PlatformFeatureFlagTargetType;

/**
 * One entry of the code-reviewed flag catalogue (COR-UI08-001 §12.1; Phase UI-08).
 *
 * `healthMetricKey` is NULLABLE and null means "no health metric exists for this flag". The page
 * renders that absence honestly rather than inventing a number — a fabricated health metric on a
 * rollout screen is worse than none, because it invites an operator to trust it.
 *
 * `externalGate` names a canonical external gate (today only `'W'`). The evaluator reads it as an
 * additional DENY. Nothing in this class, or anywhere in the flag surface, can OPEN a gate.
 */
final readonly class PlatformFeatureFlagDefinition
{
    /**
     * @param  list<string>  $environments
     * @param  list<string>  $targetTypes
     * @param  list<string>  $dependencies
     * @param  list<string>  $affectedScreenKeys
     * @param  list<string>  $affectedOperationIds
     */
    private function __construct(
        public string $key,
        public string $owner,
        public string $description,
        public string $riskClass,
        public array $environments,
        public array $targetTypes,
        public array $dependencies,
        public array $affectedScreenKeys,
        public array $affectedOperationIds,
        public ?string $healthMetricKey,
        public string $rollbackCriterion,
        public ?string $externalGate,
    ) {}

    /**
     * @param  array<string, mixed>  $definition
     */
    public static function fromArray(string $key, array $definition): self
    {
        /** @var list<string> $targetTypes */
        $targetTypes = array_values(array_intersect(
            self::stringList($definition['target_types'] ?? []),
            PlatformFeatureFlagTargetType::values(),
        ));

        return new self(
            key: $key,
            owner: (string) ($definition['owner'] ?? 'unassigned'),
            description: (string) ($definition['description'] ?? ''),
            riskClass: (string) ($definition['risk_class'] ?? 'medium'),
            environments: self::stringList($definition['environments'] ?? []),
            targetTypes: $targetTypes,
            dependencies: self::stringList($definition['dependencies'] ?? []),
            affectedScreenKeys: self::stringList($definition['affected_screen_keys'] ?? []),
            affectedOperationIds: self::stringList($definition['affected_operation_ids'] ?? []),
            healthMetricKey: isset($definition['health_metric_key']) ? (string) $definition['health_metric_key'] : null,
            rollbackCriterion: (string) ($definition['rollback_criterion'] ?? ''),
            externalGate: isset($definition['external_gate']) ? (string) $definition['external_gate'] : null,
        );
    }

    public function supportsEnvironment(string $environment): bool
    {
        return in_array($environment, $this->environments, true);
    }

    public function supportsTargetType(string $targetType): bool
    {
        return in_array($targetType, $this->targetTypes, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'owner' => $this->owner,
            'description' => $this->description,
            'risk_class' => $this->riskClass,
            'environments' => $this->environments,
            'target_types' => $this->targetTypes,
            'dependencies' => $this->dependencies,
            'affected_screen_keys' => $this->affectedScreenKeys,
            'affected_operation_ids' => $this->affectedOperationIds,
            // Null is rendered as "no health metric available", never as a zero.
            'health_metric_key' => $this->healthMetricKey,
            'health_metric_available' => $this->healthMetricKey !== null,
            'rollback_criterion' => $this->rollbackCriterion,
            'external_gate' => $this->externalGate,
        ];
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $item): string => (string) $item, $value));
    }
}
