<?php

namespace App\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Wnx\LaravelBackupRestore\HealthChecks\HealthCheck;
use Wnx\LaravelBackupRestore\HealthChecks\Result;
use Wnx\LaravelBackupRestore\PendingRestore;

/** Weekly restore test (spec §13.3): sanity counts plus the archive's private files. Run backup:restore with --keep. */
class RestoredBackupIsSane extends HealthCheck
{
    /** table => minimum rows expected after a restore */
    private const array MIN_ROWS = ['users' => 1, 'company_settings' => 1, 'roles' => 6];

    public function run(PendingRestore $pendingRestore): Result
    {
        $result = Result::make($this);
        $db = DB::connection($pendingRestore->connection);

        foreach (self::MIN_ROWS as $table => $min) {
            $count = $db->table($table)->count();
            if ($count < $min) {
                return $result->failed("Restored table {$table} has {$count} rows, expected at least {$min}.");
            }
        }

        $extracted = $pendingRestore->getAbsolutePathToLocalDecompressedBackup().DIRECTORY_SEPARATOR.'private';
        if (! File::isDirectory($extracted) || count(File::allFiles($extracted, true)) === 0) {
            return $result->failed("The archive has no private files at {$extracted} (was --keep passed?).");
        }

        // ponytail: also check each documents.path exists in $extracted once documents are in regular use.
        File::deleteDirectory($pendingRestore->getAbsolutePathToLocalDecompressedBackup());

        return $result->ok();
    }
}
