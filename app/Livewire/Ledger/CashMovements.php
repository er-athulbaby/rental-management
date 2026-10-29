<?php

namespace App\Livewire\Ledger;

use App\Actions\Ledger\RecordCashMovement;
use App\Actions\Ledger\ReverseMoneyEntry;
use App\Enums\CashMovementKind;
use App\Enums\PermissionName;
use App\Livewire\Concerns\WithActor;
use App\Models\CashMovement;
use App\Models\LedgerAccount;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Ledger money screens spec §3: Finance records and reverses transfers, bank charges and bank interest; Finance and Admin view the list. */
class CashMovements extends Component
{
    use WithActor, WithPagination;

    // Untyped on purpose: a tampered payload reaches the action's validator, never a TypeError (a 500).
    public mixed $kind = 'transfer';

    public mixed $from_account_id = '';

    public mixed $to_account_id = '';

    public mixed $amount = '';

    public mixed $date = '';

    public mixed $reference = '';

    public mixed $note = '';

    #[Locked]
    public ?int $reversingId = null;

    public mixed $reverseDate = '';

    public mixed $reverseReason = '';

    #[Url]
    public mixed $filterFrom = '';

    #[Url]
    public mixed $filterTo = '';

    #[Url]
    public mixed $filterKind = '';

    #[Url]
    public mixed $filterAccount = '';

    public function mount(): void
    {
        abort_unless($this->actor()->can(PermissionName::LedgerView), 403);
        $this->date = $this->reverseDate = now('Asia/Bahrain')->toDateString();
    }

    public function updating(string $property): void
    {
        if (str_starts_with($property, 'filter')) {
            $this->resetPage();
        }
    }

    public function save(): void
    {
        app(RecordCashMovement::class)->handle($this->actor(), ['kind' => $this->kind, 'from_account_id' => $this->from_account_id,
            'to_account_id' => $this->to_account_id, 'amount' => $this->amount, 'date' => $this->date, 'reference' => $this->reference, 'note' => $this->note]);
        $this->reset('amount', 'reference', 'note');
        Flux::toast(variant: 'success', text: __('Movement recorded.'));
    }

    public function startReverse(mixed $id): void
    {
        abort_unless($this->actor()->can(PermissionName::LedgerManage), 403);
        abort_unless(is_int($id) || (is_string($id) && ctype_digit($id) && strlen($id) < 19), 404);
        $this->reversingId = CashMovement::query()->findOrFail((int) $id)->id;
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
        app(ReverseMoneyEntry::class)->handle($this->actor(), CashMovement::query()->findOrFail($this->reversingId), $this->reverseDate, $this->reverseReason);
        $this->reversingId = null;
        Flux::toast(variant: 'success', text: __('Movement reversed.'));
    }

    /** A real Y-m-d date, or null: a filter never sends a bad date to the database. */
    private static function day(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }

    public function render(): View
    {
        $account = is_string($this->filterAccount) && ctype_digit($this->filterAccount) && strlen($this->filterAccount) < 19 ? (int) $this->filterAccount : null;
        $kind = is_string($this->filterKind) ? CashMovementKind::tryFrom($this->filterKind) : null;
        $rows = CashMovement::query()->with(['fromAccount', 'toAccount', 'reversal'])
            ->when(self::day($this->filterFrom), fn ($q, string $d) => $q->where('date', '>=', $d))
            ->when(self::day($this->filterTo), fn ($q, string $d) => $q->where('date', '<=', $d))
            ->when($kind, fn ($q, CashMovementKind $k) => $q->where('kind', $k->value))
            ->when($account, fn ($q, int $a) => $q->where(fn ($w) => $w->where('from_account_id', $a)->orWhere('to_account_id', $a)))
            ->latest('date')->latest('id')
            ->paginate(50);

        return view('livewire.ledger.cash-movements', [
            'rows' => $rows,
            'kinds' => CashMovementKind::cases(),
            'money' => LedgerAccount::query()->where('is_money', true)->where('active', true)->orderBy('code')->get(),
            'accounts' => LedgerAccount::query()->where('is_money', true)->orderBy('code')->get(),
            'canManage' => $this->actor()->can(PermissionName::LedgerManage),
        ])->title(__('Cash and bank movements'));
    }
}
