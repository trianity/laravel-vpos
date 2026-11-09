<?php

declare(strict_types=1);

namespace Trianity\Vpos\Support;

use BackedEnum;
use InvalidArgumentException;

final class Canonicalizer
{
    /**
     * @param  array<string, mixed>|object  $payload
     * @param  array<int|string, string|array<mixed>>  $schema
     */
    public function canonicalize(array|object $payload, array $schema): string
    {
        $values = [];
        $this->appendFields($values, $this->properties($payload), $schema);

        return implode('|', $values);
    }

    /**
     * @param  list<string>  $values
     * @param  array<string, mixed>  $payload
     * @param  array<int|string, string|array<mixed>>  $schema
     */
    private function appendFields(array &$values, array $payload, array $schema): void
    {
        foreach ($schema as $key => $definition) {
            if (is_int($key)) {
                if (! is_string($definition)) {
                    throw new InvalidArgumentException('Scalar canonical fields must be strings.');
                }

                if ($definition === '*') {
                    foreach (array_keys($payload) as $field) {
                        $this->appendScalar($values, $payload, (string) $field);
                    }

                    continue;
                }

                $this->appendScalar($values, $payload, $definition);

                continue;
            }

            if (! is_array($definition) || ! array_key_exists($key, $payload)) {
                continue;
            }

            $nested = $payload[$key];
            if ($this->isOmitted($nested)) {
                continue;
            }

            if (is_array($nested) && array_is_list($nested)) {
                if ($definition === ['*']) {
                    foreach ($nested as $item) {
                        $this->appendValue($values, $item, $key);
                    }

                    continue;
                }

                foreach ($nested as $item) {
                    if (! is_array($item) && ! is_object($item)) {
                        throw new InvalidArgumentException("Canonical list [{$key}] must contain objects.");
                    }

                    $this->appendFields($values, $this->properties($item), $definition);
                }

                continue;
            }

            if (! is_array($nested) && ! is_object($nested)) {
                throw new InvalidArgumentException("Canonical field [{$key}] must be an object.");
            }

            $this->appendFields($values, $this->properties($nested), $definition);
        }
    }

    /** @param list<string> $values */
    private function appendScalar(array &$values, array $payload, string $field): void
    {
        if ($field === 'signature' || ! array_key_exists($field, $payload)) {
            return;
        }

        $value = $payload[$field];
        if ($this->isOmitted($value)) {
            return;
        }

        $this->appendValue($values, $value, $field);
    }

    /** @param list<string> $values */
    private function appendValue(array &$values, mixed $value, string $field): void
    {
        if ($this->isOmitted($value)) {
            return;
        }

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (is_bool($value)) {
            $values[] = $value ? 'true' : 'false';

            return;
        }

        if (! is_scalar($value)) {
            throw new InvalidArgumentException("Canonical field [{$field}] must be scalar.");
        }

        $values[] = (string) $value;
    }

    private function isOmitted(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /** @return array<string, mixed> */
    private function properties(array|object $value): array
    {
        return is_array($value) ? $value : get_object_vars($value);
    }
}
