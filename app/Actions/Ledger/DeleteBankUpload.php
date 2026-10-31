<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Models\BankMatch;
use App\Models\BankStatementLine;
use App\Models\BankUpload;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Bank reconciliation spec §2 (plan ruling 7): an upload is deleted, with its lines, only while none of its lines is matched. */
final class DeleteBankUpload
{
    public function handle(User $actor, BankUpload $upload): void
    {
        if (! $actor->can(PermissionName::LedgerManage)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($actor, $upload) {
            LedgerAccount::query()->lockForUpdate()->findOrFail($upload->ledger_account_id); // plan ruling 10: no match lands while we look
            $upload = BankUpload::query()->findOrFail($upload->id);
            $lines = BankStatementLine::query()->where('bank_upload_id', $upload->id)->select('id');
            if (BankMatch::query()->whereIn('bank_statement_line_id', $lines)->exists()) {
                throw ValidationException::withMessages(['upload' => __('Lines of this upload are matched. Remove those matches first.')]);
            }
            $deleted = BankStatementLine::query()->where('bank_upload_id', $upload->id)->delete();
            Audit::log('bank.statement_deleted', $upload, old: ['file' => $upload->file_name, 'lines' => $deleted], causer: $actor);
            $upload->delete();
        });
    }
}
