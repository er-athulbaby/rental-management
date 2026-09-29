<?php

use Spatie\Backup\Notifications\Notifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;

// Nightly backups (spec §13.3). Only the RMS-specific choices are commented.
return [

    'backup' => [
        'name' => env('APP_NAME', 'laravel-backup'),

        'source' => [
            'files' => [
                // DIRECTORY_SEPARATOR, not '/': on Windows a '/' makes zip entries absolute and restores reject them.
                'include' => [storage_path('app'.DIRECTORY_SEPARATOR.'private')],
                'exclude' => [storage_path('app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'backup-restore-temp')],
                'follow_links' => false,
                'ignore_unreadable_directories' => false,
                'relative_path' => storage_path('app'),
            ],

            // The migrator holds TRIGGER, so the dump keeps the immutability triggers (spec §8.5).
            'databases' => ['migrator'],
        ],

        'database_dump_compressor' => null,
        'database_dump_file_timestamp_format' => null,
        'database_dump_filename_base' => 'database',
        'database_dump_file_extension' => '',

        'destination' => [
            'compression_method' => ZipArchive::CM_DEFAULT,
            'compression_level' => 9,
            'filename_prefix' => '',
            'disks' => explode(',', (string) (env('BACKUP_DISKS') ?: 'backups')), // production: backups-s3
            'continue_on_failure' => false,
        ],

        'temporary_directory' => storage_path('app/backup-temp'),
        'password' => env('BACKUP_ARCHIVE_PASSWORD'), // per install, kept in the vendor vault
        'encryption' => 'aes256',
        'verify_backup' => true,
        'tries' => 1,
        'retry_delay' => 0,
    ],

    'notifications' => [
        'notifications' => [
            BackupHasFailedNotification::class => ['mail'],
            UnhealthyBackupWasFoundNotification::class => ['mail'],
            CleanupHasFailedNotification::class => ['mail'],
            BackupWasSuccessfulNotification::class => ['mail'],
            HealthyBackupWasFoundNotification::class => ['mail'],
            CleanupWasSuccessfulNotification::class => ['mail'],
        ],
        'notifiable' => Notifiable::class,
        'mail' => [
            // `?:` not a default argument: a blank BACKUP_NOTIFY_EMAIL= in .env is "", which laravel-backup rejects at boot.
            'to' => env('BACKUP_NOTIFY_EMAIL') ?: env('MAIL_FROM_ADDRESS', 'hello@example.com'),
            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
                'name' => env('MAIL_FROM_NAME', 'Rental Management'),
            ],
        ],
        'slack' => ['webhook_url' => '', 'channel' => null, 'username' => null, 'icon' => null],
        'discord' => ['webhook_url' => '', 'username' => '', 'avatar_url' => ''],
        'webhook' => ['url' => ''],
    ],

    'log_channel' => null,

    'monitor_backups' => [
        [
            'name' => env('APP_NAME', 'laravel-backup'),
            'disks' => explode(',', (string) (env('BACKUP_DISKS') ?: 'backups')),
            'health_checks' => [
                MaximumAgeInDays::class => 1, // no size-based alarms
            ],
        ],
    ],

    'cleanup' => [
        'strategy' => DefaultStrategy::class,
        'default_strategy' => [
            'keep_all_backups_for_days' => 7,
            'keep_daily_backups_for_days' => 23, // 7 + 23 = 30 days of dailies (spec §13.3)
            'keep_weekly_backups_for_weeks' => 0,
            'keep_monthly_backups_for_months' => 12,
            'keep_yearly_backups_for_years' => 0,
            'delete_oldest_backups_when_using_more_megabytes_than' => null, // no size-based deletion
        ],
        'tries' => 1,
        'retry_delay' => 0,
    ],

];
