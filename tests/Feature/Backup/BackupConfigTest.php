<?php

use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;

test('backups dump through the migrator connection so triggers are included', function () {
    expect(config('backup.backup.source.databases'))->toBe(['migrator'])
        ->and(config('database.connections.migrator.dump.mysql_gtid_purged'))->toBe('OFF')
        ->and(config('database.connections.migrator.dump'))->toContain('use_single_transaction', 'include_routines');
});

test('private files are included and the restore scratch folder is not', function () {
    $sep = DIRECTORY_SEPARATOR;

    expect(config('backup.backup.source.files.include'))->toBe([storage_path('app'.$sep.'private')])
        ->and(config('backup.backup.source.files.exclude'))->toContain(storage_path('app'.$sep.'private'.$sep.'backup-restore-temp'));
});

test('archives are AES-256 encrypted with the per-install password', function () {
    expect(config('backup.backup.encryption'))->toBe('aes256')
        ->and(config('backup.backup.password'))->toBe(env('BACKUP_ARCHIVE_PASSWORD'));
});

test('retention keeps 30 days of dailies and 12 monthly, with no size-based deletion', function () {
    $strategy = config('backup.cleanup.default_strategy');

    expect($strategy['keep_all_backups_for_days'] + $strategy['keep_daily_backups_for_days'])->toBe(30)
        ->and($strategy['keep_weekly_backups_for_weeks'])->toBe(0)
        ->and($strategy['keep_monthly_backups_for_months'])->toBe(12)
        ->and($strategy['delete_oldest_backups_when_using_more_megabytes_than'])->toBeNull()
        ->and(array_keys(config('backup.monitor_backups.0.health_checks')))->toBe([MaximumAgeInDays::class]);
});
