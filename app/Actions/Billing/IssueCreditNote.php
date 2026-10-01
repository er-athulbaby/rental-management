<?php

namespace App\Actions\Billing;

use App\Actions\NextDocumentNumber;
use App\Actions\Payments\ReverseAllocations;
use App\Billing\CreditNoteSplit;
use App\Enums\InvoiceStatus;
use App\Enums\NumberSequenceKey;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Spec §6.5. Internal: runs inside DecideApproval's transaction. Locks customer → target lines → invoices (spec §7.2). */
final class IssueCreditNote
{
    public function __construct(private NextDocumentNumber $next, private ReverseAllocations $reverse) {}

    public function handle(Invoice $creditNote, User $approver): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('IssueCreditNote must run inside the caller\'s transaction.');
        }

        Customer::query()->lockForUpdate()->findOrFail($creditNote->customer_id);
        $cnLines = InvoiceLine::query()->where('invoice_id', $creditNote->id)->orderBy('id')->get();
        $targets = InvoiceLine::query()->whereIn('id', $cnLines->pluck('credited_line_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $lineOf = fn (InvoiceLine $cnLine): InvoiceLine => $targets->get($cnLine->credited_line_id) ?? throw new LogicException('Credited line missing.');
        $target = Invoice::query()->lockForUpdate()->findOrFail($creditNote->related_invoice_id);
        $cn = Invoice::query()->lockForUpdate()->findOrFail($creditNote->id);

        if ($cn->status !== InvoiceStatus::PendingApproval || $target->status !== InvoiceStatus::Issued) {
            throw ValidationException::withMessages(['approval' => __('This credit note can no longer be issued.')]);
        }

        // 1. Check every line still fits and its tax split is unchanged since the request.
        foreach ($cnLines as $cnLine) {
            $line = $lineOf($cnLine);
            $amount = Fils::fromDecimal($cnLine->total);
            $left = Fils::fromDecimal($line->total) - Fils::fromDecimal((string) $line->credited);

            if ($amount > $left || CreditNoteSplit::of($amount, $line)['tax'] !== Fils::fromDecimal($cnLine->tax_amount)) {
                throw ValidationException::withMessages(['approval' => __('Line ":d" changed since this credit note was requested: reject it and request a new one.', ['d' => $line->description])]);
            }
        }

        // 2. De-allocate max(0, c − b) per line, newest allocation first (partial rows allowed).
        $cuts = [];
        foreach ($cnLines as $cnLine) {
            $line = $lineOf($cnLine);
            $need = max(0, Fils::fromDecimal($cnLine->total) - $line->balanceFils());

            $live = PaymentAllocation::query()->where('invoice_line_id', $line->id)->whereNull('reverses_allocation_id')
                ->orderByDesc('posted_at')->orderByDesc('id')->get();
            foreach ($live as $allocation) {
                if ($need <= 0) {
                    break;
                }
                $take = min($need, $allocation->liveFils());
                if ($take > 0) {
                    $cuts[] = ['allocation' => $allocation, 'amount' => $take];
                    $need -= $take;
                }
            }
        }
        $this->reverse->handle($cuts, $approver);

        // 3. Apply the credit to the lines and the target invoice.
        $credited = 0;
        foreach ($cnLines as $cnLine) {
            $line = InvoiceLine::query()->findOrFail($cnLine->credited_line_id); // allocated changed in step 2
            $amount = Fils::fromDecimal($cnLine->total);
            $line->forceFill(['credited' => Fils::toDecimal(Fils::fromDecimal((string) $line->credited) + $amount)])->save();
            $credited += $amount;
        }
        $target = Invoice::query()->findOrFail($target->id);
        $target->forceFill(['credited' => Fils::toDecimal(Fils::fromDecimal((string) $target->credited) + $credited)])->save();

        // 4. Number and issue the credit note itself (one UPDATE: the CHECKs tie number, issued_at and grace_until to issued).
        $cn->forceFill([
            'number' => ($this->next)(NumberSequenceKey::CreditNote),
            'issue_date' => now('Asia/Bahrain')->toDateString(),
            'due_date' => now('Asia/Bahrain')->toDateString(),
            'grace_until' => now('Asia/Bahrain')->toDateString(),
            'issued_at' => now(),
            'issued_by' => $approver->id,
            'status' => InvoiceStatus::Issued,
        ])->save();
    }
}
