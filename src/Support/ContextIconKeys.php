<?php

declare(strict_types=1);

namespace App\Support;

final class ContextIconKeys
{
    /** @var list<string> */
    private const KEYS = [
        'map_pinned', 'plane', 'calendar_days', 'party_popper', 'gift', 'heart',
        'luggage', 'home', 'car', 'building', 'landmark', 'mountain', 'beach',
        'globe', 'route', 'briefcase', 'users', 'star', 'flag', 'ticket',
        'bookmark', 'tag', 'box',
        'coffee', 'utensils', 'book_open', 'shopping_bag', 'shirt',
        'sparkles', 'droplet', 'scissors', 'film', 'cookie',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return self::KEYS;
    }

    public static function isValid(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }
}
