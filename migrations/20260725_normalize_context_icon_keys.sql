UPDATE contexts
SET icon_key = NULL
WHERE icon_key IS NOT NULL
  AND icon_key NOT IN (
    'map_pinned',
    'plane',
    'calendar_days',
    'party_popper',
    'gift',
    'heart',
    'luggage',
    'home',
    'car',
    'building',
    'landmark',
    'mountain',
    'beach',
    'globe',
    'route',
    'briefcase',
    'users',
    'star',
    'flag',
    'ticket',
    'bookmark',
    'tag',
    'box'
  );
