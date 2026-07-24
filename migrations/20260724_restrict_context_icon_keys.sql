UPDATE contexts
SET icon_key = NULL
WHERE icon_key IS NOT NULL
  AND icon_key NOT IN (
    'map_pinned',
    'calendar_days',
    'party_popper',
    'building',
    'folder_kanban',
    'tent_tree',
    'mountain',
    'landmark',
    'globe',
    'milestone'
  );
