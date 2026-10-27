<?php

namespace App\Livewire\Ledger;

use App\Actions\Ledger\LockLedger;
use App\Actions\Ledger\RebuildLedger;
use App\Actions\Ledger\UnlockLedger;
use App\Enums\PermissionName;
use App\Ledger\LedgerHealth;
use App\Livewire\Concerns\WithActor;
use App\Models\LedgerSetting;
use App\Support\Fils;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** General ledger home (plan rulings 4, 5 and 8): Ledger health, the lock, and rebuilds with their progress. Finance and Admin. */
class Home extends Component
{
    use WithActor;

    // Untyped on purpose: a tampered payload (an array) reaches the action's validator, never a TypeError (a 500).
    public mixed $lockDate = '';

    public mixed $unlockDate = '';

    public mixed $fromMonth = '';

    public mixed $toMonth = '';

    public function mount(): void
    {
        abort_unless($this->actor()->can(PermissionName::LedgerView), 403);
        $this->fromMonth = $this->toMonth = now('Asia/Bahrain')->format('Y-m');
    }

    public function checkNow(): void
    {
        abort_unless($this->actor()->can(PermissionName::LedgerView), 403);
        LedgerHealth::store();
        Flux::toast(variant: 'success', text: __('Ledger health checked.'));
    }

    public function lock(): void
    {
        $this->resetErrorBag();
        app(LockLedger::class)->handle($this->actor(), $this->lockDate);
        $this->lockDate = '';
        Flux::toast(variant: 'success', text: __('Locked.'));
    }

    public function unlock(): void
    {
        $this->resetErrorBag();
        app(UnlockLedger::class)->handle($this->actor(), $this->unlockDate);
        $this->unlockDate = '';
        Flux::toast(variant: 'success', text: __('Unlocked.'));
    }

    public function rebuild(): void
    {
        $this->resetErrorBag();
        app(RebuildLedger::class)->handle($this->actor(), $this->fromMonth, $this->toMonth);
        Flux::toast(variant: 'success', text: __('Rebuild started.'));
    }

    public function render(): View
    {
        $user = $this->actor();
        $settings = LedgerSetting::current();
        $show = fn (int $value, bool $money): string => $money ? Fils::toDecimal($value) : (string) $value;

        return view('livewire.ledger.home', [
            'settings' => $settings,
            'health' => array_map(fn (array $r) => [
                'label' => __($r['check']),
                'ledger' => $show($r['ledger'], $r['money']),
                'app' => $show($r['app'], $r['money']),
                'difference' => $show($r['ledger'] - $r['app'], $r['money']),
                'ok' => $r['ledger'] === $r['app'],
            ], $settings->health ?? []),
            'rebuildMessage' => __(in_array($settings->rebuild_message, [LedgerSetting::STALE_MESSAGE, LedgerSetting::FAILED_MESSAGE], true) ? $settings->rebuild_message : LedgerSetting::FAILED_MESSAGE),
            'canManage' => $user->can(PermissionName::LedgerManage),
            'canAdmin' => $user->can(PermissionName::LedgerAdmin),
        ])->title(__('Ledger'));
    }
}
