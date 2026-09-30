<?php

namespace App\Actions\Agreements;

use App\Actions\Billing\CreateDepositInvoice;
use App\Actions\Billing\GenerateRentSchedule;
use App\Actions\Billing\IssueDueInvoices;
use App\Actions\NextDocumentNumber;
use App\Enums\AgreementStatus;
use App\Enums\NumberSequenceKey;
use App\Models\Agreement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Pending → active (spec §5.4, §6.2, §6.3, §9.4). No authorisation here: DecideApproval (and the M5 importer) authorise. */
final class ActivateAgreement
{
    public function __construct(
        private EnsureNoAgreementOverlap $overlap,
        private NextDocumentNumber $next,
        private GenerateRentSchedule $schedule,
        private CreateDepositInvoice $deposit,
        private IssueDueInvoices $issueDue,
    ) {}

    public function handle(Agreement $agreement, User $approver): Agreement
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('ActivateAgreement must run inside the caller\'s transaction.');
        }

        $this->overlap->handle($agreement); // checked again on approval

        $agreement = Agreement::query()->lockForUpdate()->findOrFail($agreement->id);
        if ($agreement->status !== AgreementStatus::PendingApproval) {
            throw new LogicException("Agreement {$agreement->id} is not pending approval.");
        }

        $agreement->forceFill([
            'status' => AgreementStatus::Active,
            'number' => ($this->next)(NumberSequenceKey::Agreement),
            'verify_token' => Str::random(32), // random, not derived from APP_KEY, so it survives a key rotation
        ])->save();

        $this->schedule->handle($agreement, $approver);
        $this->deposit->handle($agreement, $approver);
        ($this->issueDue)($agreement, $approver); // activation itself issues what is already due (spec §6.3)

        return $agreement;
    }
}
