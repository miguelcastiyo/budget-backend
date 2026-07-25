<?php

declare(strict_types=1);

namespace PrivacyParity;

final class SymbolicIdMap
{
    /** @var array<string, string> */
    private array $values = [];

    public function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = $this->normalize($item);
            }
            return $out;
        }

        if (!is_string($value) || !preg_match('/^(?:[a-z]+_)?(\d+|[a-f0-9-]{16,})$/i', $value)) {
            return $value;
        }

        $prefix = 'record';
        return $this->values[$value] ??= $prefix . '_' . (count($this->values) + 1);
    }
}
