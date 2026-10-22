<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Encrypted off-server backups (P8-02, PRD §21 "Backup")
    |--------------------------------------------------------------------------
    | RPO 24 h / RTO 4 h. Only PUBLIC key material is ever configured on the server; the private key lives
    | with the owner (see ARCHITECTURE.md "Backup & Restore") and is passed to `backup:restore-test --key=`
    | at the moment of use.
    */

    // Master switch for the schedule and the health alerts. Off by default so dev/CI never alert about a backup nobody configured.
    'enabled' => (bool) env('BACKUP_ENABLED', false),

    // Any disk from config/filesystems.php; must be OFF the VPS in production (backup_s3).
    'disk' => env('BACKUP_DISK', 'backup_local'),
    'allow_local_disk' => (bool) env('BACKUP_ALLOW_LOCAL_DISK', false),

    // 'age' (preferred) or 'gpg'.
    'encryption' => env('BACKUP_ENCRYPTION', 'age'),
    'age' => [
        'binary' => env('BACKUP_AGE_BINARY', 'age'),
        'recipient' => env('BACKUP_AGE_RECIPIENT'), // public key "age1..." — NEVER the AGE-SECRET-KEY
    ],
    'gpg' => [
        'binary' => env('BACKUP_GPG_BINARY', 'gpg'),
        'recipient' => env('BACKUP_GPG_RECIPIENT'), // key id / fingerprint / e-mail of a PUBLIC key already in the server keyring
    ],

    'pg_dump_binary' => env('BACKUP_PG_DUMP_BINARY', 'pg_dump'),
    'pg_restore_binary' => env('BACKUP_PG_RESTORE_BINARY', 'pg_restore'),
    'process_timeout_seconds' => (int) env('BACKUP_PROCESS_TIMEOUT', 3600),

    'retention' => [
        'daily_days' => 30,
        'weekly_weeks' => 12,
    ],

    // Saturday (PRD §22 week starts Saturday), Tehran calendar: ISO day 6.
    'weekly_iso_weekday' => 6,

    // HealthCheckJob: a successful backup older than this raises BackupStale (RPO 24 h + 2 h slack).
    'stale_after_hours' => 26,

    'restore_test' => [
        // The ONLY database a restore test may ever write to. Hard-coded on purpose: BackupGuard refuses anything else.
        'database' => 'hm_restore_test',
        // Quarterly reminder.
        'due_every_days' => 90,
        // Tables compared against the live DB after a restore: table => created-at column (null = count only).
        'tables' => [
            'users' => 'created_at',
            'roles' => 'created_at',
            'permission_overrides' => 'created_at',
            'customers' => 'created_at',
            'orders' => 'created_at',
            'segments' => 'created_at',
            'customer_notes' => 'created_at',
            'ai_insights' => 'created_at',
            'audit_logs' => 'created_at',
            'settings' => null,
        ],
    ],

];
