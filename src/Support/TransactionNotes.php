<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\HttpException;

final class TransactionNotes
{
    public static function normalize(mixed $value, string $field = 'notes', string $errorMessage = 'Request validation failed'): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new HttpException(422, 'VALIDATION_ERROR', $errorMessage, [
                ['field' => $field, 'message' => 'must be a string or null'],
            ]);
        }

        $notes = trim($value);
        if ($notes === '') {
            return null;
        }

        if (mb_strlen($notes, 'UTF-8') > 255) {
            throw new HttpException(422, 'VALIDATION_ERROR', $errorMessage, [
                ['field' => $field, 'message' => 'must be 255 characters or fewer'],
            ]);
        }

        return $notes;
    }
}
