<?php

namespace App\Actions\Agreements;

use App\Actions\Billing\BuildCreditNote;
use App\Actions\Billing\CreateDepositInvoice;
use App\Actions\Billing\IssueCreditNote;
use App\Actions\Deposits\CreateDepositSettlement;
use App\Enums\AgreementStatus;
use App\Enums\DepositMovementType;
use App\Enums\InvoiceChargeType;
use App\Enums\InvoiceStatus;
use App\Models\Agreement;
use App\Models\AgreementUnit;
use App\Models\DepositMovement;
use App\Models\DepositSettlementUnit;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Spec §5.8 on a renewal's activation (plan ruling 9): credit any unpaid old deposit, carry min(held, new deposit), leave
 * the rest for a settlement on the old agreement, and invoice only what is still missing. Internal; the customer is locked.
 */
final class TransferDeposits
{
    public function __construct(
        private BuildCreditNote $buildCreditNote,
        private IssueCreditNote $issueCreditNote,
        private CreateDepositSettlement $settlement,
        private CreateDepositInvoice $depositInvoice,
    ) {}

    public function handle(Agreement $renewal, User $approver): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('TransferDeposits must run inside the caller\'s transaction.');
        }

        $old = Agreement::query()->lockForUpdate()->findOrFail($renewal->previous_agreement_id);
        $newUnitIds = AgreementUnit::query()->where('agreement_id', $renewal->id)->pluck('unit_id');
        $oldUnits = AgreementUnit::query()->where('agreement_id', $old->id)->whereIn('unit_id', $newUnitIds)->orderBy('id')->lockForUpdate()->get()->keyBy('unit_id');
        if (! in_array($old->status, [AgreementStatus::Active, AgreementStatus::Expired], true)
            || $oldUnits->contains(fn (AgreementUnit $au) => $au->move_out_date !== null || DepositSettlementUnit::query()->where('agreement_unit_id', $au->id)->exists())) {
            throw ValidationException::withMessages(['approval' => __('The agreement being renewed has changed; a carried unit has moved out or is being settled.')]);
        }
        $amounts = [];
        $leftover = [];
        $now = now();

        foreach (AgreementUnit::query()->where('agreement_id', $renewal->id)->orderBy('id')->get() as $newAu) {
            $oldAu = $oldUnits->get($newAu->unit_id);
            $wanted = Fils::fromDecimal($newAu->deposit_amount);
            if ($oldAu === null) {
                $amounts[$newAu->id] = $wanted;

                continue;
            }

            // 1. The old deposit line's unpaid balance is credited (spec §5.8), so nothing stays owed on the old agreement.
            $unpaid = InvoiceLine::query()->where('agreement_unit_id', $oldAu->id)->where('charge_type', InvoiceChargeType::Deposit)
                ->whereHas('invoice', fn ($q) => $q->where('status', InvoiceStatus::Issued))->orderBy('id')->get()
                ->filter(fn (InvoiceLine $l) => $l->balanceFils() > 0);
            foreach ($unpaid->groupBy('invoice_id') as $lines) {
                $entries = [];
                foreach ($lines as $l) {
                    $entries[] = [$l, $l->balanceFils()];
                }
                $cn = $this->buildCreditNote->handle(Invoice::query()->findOrFail($lines->first()?->invoice_id), $entries,
                    __('Unpaid deposit closed on renewal :n', ['n' => $renewal->label()]), $approver);
                $cn->forceFill(['status' => InvoiceStatus::PendingApproval])->save();
                $this->issueCreditNote->handle($cn, $approver);
            }

            // 2. Carry min(held, new deposit).
            $held = DepositMovement::heldFils($oldAu->id, lock: true);
            $carry = min($held, $wanted);
            if ($carry > 0) {
                $owner = DepositMovement::ownerContractFor($oldAu->id);
                $out = DepositMovement::create(['agreement_unit_id' => $oldAu->id, 'owner_contract_id' => $owner, 'type' => DepositMovementType::TransferOut,
                    'amount' => Fils::toDecimal(-$carry), 'source_type' => 'agreement', 'source_id' => $renewal->id, 'posted_at' => $now]);
                DepositMovement::create(['agreement_unit_id' => $newAu->id, 'owner_contract_id' => $out->owner_contract_id, 'type' => DepositMovementType::TransferIn,
                    'amount' => Fils::toDecimal($carry), 'source_type' => 'agreement', 'source_id' => $old->id, 'posted_at' => $now]);
            }

            // 3. What stays on the old unit is refunded through a settlement; 4. the renewal bills only what is missing.
            if ($held - $carry > 0) {
                $leftover[] = $oldAu->id;
            }
            $amounts[$newAu->id] = $wanted - $carry;
        }

        if ($leftover !== []) {
            $this->settlement->handle($old, $leftover, $approver);
        }
        $this->depositInvoice->handle($renewal, $approver, $amounts);
    }
}
