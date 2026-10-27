<?php

namespace App\Livewire\Ledger;

use App\Actions\Ledger\SaveOpeningBalances;
use App\Actions\Ledger\SetLedgerStart;
use App\Enums\PermissionName;
use App\Livewire\Concerns\WithActor;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\LedgerSetting;
use App\Support\Fils;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** Spec §3 "Go-live" (plan ruling 6): Admin's start date and one opening balance per account. */
class Setup extends Component
{
    use WithActor;

    // Untyped on purpose: a tampered payload reaches the action, never a TypeError (a 500).
    public mixed $startDate = '';

    /** @var mixed account id => ['debit' => string, 'credit' => string] */
    public mixed $balances = [];

    public function mount(): void
    {
        abort_unless($this->actor()->can(PermissionName::LedgerAdmin), 403);
        $this->startDate = LedgerSetting::current()->start_date?->toDateString() ?? '';
        $this->loadBalances();
    }

    public function setStart(): void
    {
        app(SetLedgerStart::class)->handle($this->actor(), $this->startDate);
        $this->loadBalances();
        Flux::toast(variant: 'success', text: __('Start date saved. The ledger is being rebuilt.'));
    }

    public function saveOpening(): void
    {
        app(SaveOpeningBalances::class)->handle($this->actor(), is_array($this->balances) ? $this->balances : []);
        $this->loadBalances();
        Flux::toast(variant: 'success', text: __('Opening balances saved.'));
    }

    private function loadBalances(): void
    {
        $opening = [];
        foreach (JournalLine::query()->whereIn('journal_id', Journal::query()->where('kind', 'opening')->select('id'))->get() as $line) {
            $opening[$line->ledger_account_id] = ($opening[$line->ledger_account_id] ?? 0) + Fils::fromDecimal($line->debit) - Fils::fromDecimal($line->credit);
        }
        $balances = [];
        foreach (LedgerAccount::query()->where('active', true)->orderBy('code')->pluck('id') as $id) {
            $n = $opening[$id] ?? 0;
            $balances[$id] = ['debit' => $n > 0 ? Fils::toDecimal($n) : '', 'credit' => $n < 0 ? Fils::toDecimal(-$n) : ''];
        }
        $this->balances = $balances;
    }

    public function render(): View
    {
        $difference = 0;
        foreach (is_array($this->balances) ? $this->balances : [] as $row) {
            foreach (['debit' => 1, 'credit' => -1] as $side => $sign) {
                $value = is_array($row) ? ($row[$side] ?? '') : '';
                if (is_string($value) && preg_match('/^\d{1,9}(\.\d{1,3})?$/', $value) === 1) {
                    $difference += $sign * Fils::fromDecimal($value);
                }
            }
        }

        return view('livewire.ledger.setup', [
            'settings' => LedgerSetting::current(),
            'accounts' => LedgerAccount::query()->where('active', true)->orderBy('code')->get(),
            'difference' => Fils::toDecimal($difference),
        ])->title(__('Start date and opening balances'));
    }
}
