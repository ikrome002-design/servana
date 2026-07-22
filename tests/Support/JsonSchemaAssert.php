<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * A deliberately small, STRICT JSON Schema validator for the committed Refer & Earn payload
 * schemas (Plan §58B.2: "A schema test validates every emitted payload against committed JSON
 * Schemas in `docs/integrations/refer-earn/schemas/*.json`").
 *
 * Why not a library: the repository pins its dependency set, and adding a schema library for five
 * flat object schemas would widen the supply-chain surface for no behavioural gain. The trade-off is
 * managed by making this validator FAIL CLOSED — it supports an explicit keyword list and throws on
 * any keyword it does not implement. So a future schema that uses, say, `oneOf` cannot be silently
 * half-checked: the test breaks until this validator learns the keyword.
 *
 * @internal test support only
 */
final class JsonSchemaAssert
{
    private const SUPPORTED_KEYWORDS = [
        '$schema', '$id', 'title', 'description',
        'type', 'enum', 'const', 'properties', 'required', 'additionalProperties',
        'pattern', 'format', 'minimum', 'minLength', 'maxLength',
    ];

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string> violations; empty means valid
     */
    public static function violations(array $schema, mixed $value, string $path = '$'): array
    {
        foreach (array_keys($schema) as $keyword) {
            if (! in_array($keyword, self::SUPPORTED_KEYWORDS, true)) {
                throw new RuntimeException("JsonSchemaAssert does not implement the '{$keyword}' keyword (at {$path}); extend it rather than skipping validation.");
            }
        }

        $violations = [];

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $violations[] = "{$path}: expected const ".var_export($schema['const'], true).', got '.var_export($value, true);
        }

        if (array_key_exists('enum', $schema) && ! in_array($value, $schema['enum'], true)) {
            $violations[] = "{$path}: ".var_export($value, true).' is not one of '.json_encode($schema['enum']);
        }

        if (array_key_exists('type', $schema)) {
            $types = (array) $schema['type'];

            if (! self::matchesAnyType($value, $types)) {
                $violations[] = "{$path}: expected type ".implode('|', $types).', got '.get_debug_type($value);
            }
        }

        if (is_string($value)) {
            if (isset($schema['pattern']) && preg_match('/'.str_replace('/', '\/', (string) $schema['pattern']).'/u', $value) !== 1) {
                $violations[] = "{$path}: '{$value}' does not match pattern {$schema['pattern']}";
            }

            if (isset($schema['minLength']) && mb_strlen($value) < (int) $schema['minLength']) {
                $violations[] = "{$path}: shorter than minLength {$schema['minLength']}";
            }

            if (isset($schema['maxLength']) && mb_strlen($value) > (int) $schema['maxLength']) {
                $violations[] = "{$path}: longer than maxLength {$schema['maxLength']}";
            }

            if (isset($schema['format'])) {
                $violations = [...$violations, ...self::checkFormat((string) $schema['format'], $value, $path)];
            }
        }

        if (is_int($value) && isset($schema['minimum']) && $value < (int) $schema['minimum']) {
            $violations[] = "{$path}: {$value} is below minimum {$schema['minimum']}";
        }

        if (isset($schema['properties']) && is_array($value)) {
            /** @var array<string, array<string, mixed>> $properties */
            $properties = $schema['properties'];

            foreach ($properties as $name => $subSchema) {
                if (array_key_exists($name, $value)) {
                    $violations = [...$violations, ...self::violations($subSchema, $value[$name], $path.'.'.$name)];
                }
            }

            if (($schema['additionalProperties'] ?? true) === false) {
                foreach (array_keys($value) as $name) {
                    if (! array_key_exists($name, $properties)) {
                        $violations[] = "{$path}.{$name}: additional property is not allowed by the schema";
                    }
                }
            }
        }

        if (isset($schema['required']) && is_array($value)) {
            foreach ((array) $schema['required'] as $name) {
                if (! array_key_exists($name, $value)) {
                    $violations[] = "{$path}.{$name}: required property is missing";
                }
            }
        }

        return $violations;
    }

    /** @param list<string> $types */
    private static function matchesAnyType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $ok = match ($type) {
                'object' => is_array($value) && ! array_is_list($value),
                'array' => is_array($value) && array_is_list($value),
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                default => throw new RuntimeException("JsonSchemaAssert does not implement type '{$type}'."),
            };

            if ($ok) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function checkFormat(string $format, string $value, string $path): array
    {
        return match ($format) {
            'date-time' => preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/', $value) === 1
                ? []
                : ["{$path}: '{$value}' is not an ISO-8601 Zulu date-time"],
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
                ? []
                : ["{$path}: '{$value}' is not an ISO-8601 date"],
            default => throw new RuntimeException("JsonSchemaAssert does not implement format '{$format}'."),
        };
    }

    /** @return array<string, mixed> */
    public static function load(string $absolutePath): array
    {
        $raw = file_get_contents($absolutePath);

        if ($raw === false) {
            throw new RuntimeException("Unable to read JSON Schema at {$absolutePath}.");
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
