<?php

declare(strict_types=1);

namespace PrivacyParity;

final class FixtureNormalizer
{
    /** @param array<int, string> $ignoredFields */
    public function normalize(mixed $value, array $ignoredFields = []): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && in_array($key, $ignoredFields, true)) {
                    continue;
                }
                $out[$key] = $this->normalize($item, $ignoredFields);
            }
            return $out;
        }

        if (is_float($value)) {
            return number_format($value, 2, '.', '');
        }

        if (is_int($value) || is_bool($value) || $value === null) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+\.\d+$/', $value)) {
            return $value;
        }

        return $value;
    }

    /** @param array<int, mixed> $items */
    public function stableList(array $items): array
    {
        usort($items, static fn(mixed $a, mixed $b): int => strcmp(
            json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
            json_encode($b, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''
        ));
        return $items;
    }
}
