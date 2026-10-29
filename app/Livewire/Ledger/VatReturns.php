<?php

namespace App\Livewire\Ledger;

use App\Actions\Ledger\RecordVatReturn;
use App\Actions\Ledger\ReverseMoneyEntry;
use App\Enums\DisbursementMethod;
use App\Enums\PermissionName;
use App\Ledger\Balances;
use App\Livewire\Concerns\WithActor;
use App\Models\VatReturn;
use App\Support\Fils;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/** Ledger money screens spec §4: Finance records and reverses VAT returns; Finance and Admin view each period's figure and difference. */
class VatReturns extends Component
{
    use WithActor, WithPagination;

    // Untyped on purpose: a tampered payload reaches the action's validator, never a TypeError (a 500).
    public mixed $period_from = '';

    public mixed $period_to = '';

    public mixed $date = '';

    public mixed $method = 'bank_transfer';

    public mixed $reference = '';

    public mixed $amount = '';

    public mixed $note = '';

    #[Locked]
    public ?int $reversingId = null;

    public mixed $reverseDate = '';

    public mixed $reverseReason = '';

    public function mount(): void
    {
        abort_unless($this->actor()->can(PermissionName::LedgerView), 403);
        $this->date = $this->reverseDate = now('Asia/Bahrain')->toDateString();
    }

    /** Spec §4: once both ends of the period are set, the amount is prefilled with the period's figure from the ledger. */
    public function updated(string $property): void
    {
        $figure = in_array($property, ['period_from', 'period_to'], true) ? $this->figure() : null;
        if ($figure !== null) {
            $this->amount = Fils::toDecimal(abs($figure));
        }
    }

    public function save(): void
    {
        app(RecordVatReturn::class)->handle($this->actor(), ['period_from' => $this->period_from, 'period_to' => $this->period_to, 'date' => $this->date,
            'method' => $this->method, 'reference' => $this->reference, 'amount' => $this->amount, 'note' => $this->note]);
        $this->reset('period_from', 'period_to', 'reference', 'amount', 'note');
        Flux::toast(variant: 'success', text: __('Return recorded.'));
    }

    public function startReverse(mixed $id): void
    {
        abort_unless($this->actor()->can(PermissionName::LedgerManage), 403);
        abort_unless(is_int($id) || (is_string($id) && ctype_digit($id) && strlen($id) < 19), 404);
        $this->reversingId = VatReturn::query()->findOrFail((int) $id)->id;
        $this->reverseReason = '';
        $this->resetErrorBag();
    }

    public function cancelReverse(): void
    {
        $this->reversingId = null;
        $this->resetErrorBag();
    }

    public function reverse(): void
    {
        abort_if($this->reversingId === null, 404);
        app(ReverseMoneyEntry::class)->handle($this->actor(), VatReturn::query()->findOrFail($this->reversingId), $this->reverseDate, $this->reverseReason);
        $this->reversingId = null;
        Flux::toast(variant: 'success', text: __('Return reversed.'));
    }

    /** The figure for the period typed so far, or null until both ends are real dates in order. */
    private function figure(): ?int
    {
        $from = self::day($this->period_from);
        $to = self::day($this->period_to);

        return $from !== null && $to !== null && $from <= $to ? Balances::vatDue($from, $to) : null;
    }

    private static function day(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }

    public function render(): View
    {
        $figure = $this->figure();
        $rows = VatReturn::query()->whereNull('reverses_id')->with('reversal')->latest('period_from')->latest('id')->paginate(50);
        $lines = [];
        foreach ($rows as $row) {
            $due = Balances::vatDue($row->period_from->toDateString(), $row->period_to->toDateString());
            $paid = ($row->direction === 'refund' ? -1 : 1) * Fils::fromDecimal($row->amount);
            $lines[$row->id] = ['figure' => Fils::toDecimal($due), 'difference' => $row->reversal === null ? Fils::toDecimal($due - $paid) : null];
        }

        return view('livewire.ledger.vat-returns', [
            'rows' => $rows,
            'lines' => $lines,
            'figure' => $figure,
            'figureAmount' => $figure === null ? null : Fils::toDecimal(abs($figure)),
            'methods' => DisbursementMethod::cases(),
            'canManage' => $this->actor()->can(PermissionName::LedgerManage),
        ])->title(__('VAT returns'));
    }
}
