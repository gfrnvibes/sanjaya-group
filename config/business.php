<?php

return [
    'pg_dump' => env('PG_DUMP_BINARY', 'pg_dump'),
    'pg_restore' => env('PG_RESTORE_BINARY', 'pg_restore'),
    'backup_retention_days' => (int) env('DATABASE_BACKUP_RETENTION_DAYS', 14),
    'backup_enabled' => (bool) env('DATABASE_BACKUP_ENABLED', true),
    'import_max_rows' => (int) env('IMPORT_MAX_ROWS', 10000),
    'import_max_bytes' => (int) env('IMPORT_MAX_BYTES', 20971520),
];
