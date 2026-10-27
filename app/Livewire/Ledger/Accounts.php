<?php

namespace App\Livewire\Ledger;

use App\Actions\Ledger\DeleteLedgerAccount;
use App\Actions\Ledger\RebuildLedger;
use App\Actions\Ledger\SaveLedgerAccount;
use App\Actions\Ledger\SaveLedgerRules;
use App\Actions\Ledger\SetLedgerAccountActive;
use App\Enums\LedgerAccountType;
use App\Enums\PermissionName;
use App\Ledger\Rules;
use App\Livewire\Concerns\WithActor;
use App\Models\LedgerAccount;
use App\Models\LedgerSetting;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Spec §3: Admin's chart of accounts and posting rules. After a rule change the page offers to rebuild the open months. */
class Accounts extends Component
{
    use WithActor;

    #[Locked]
    public ?int $editingId = null;

    // Untyped on purpose: a tampered payload reaches the action's validator, never a TypeError (a 500).
    public mixed $code = '';

    public mixed $name_en = '';

    public mixed $name_ar = '';

    public mixed $type = 'expense';

    /** @var mixed account ids in Rules::keys() order (rule keys contain dots, which Livewire would read as nesting) */
    public mixed $ruleAccounts = [];

    public bool $offerRebuild = false;

    public function mount(): void
    {
        abort_unless($this->actor()->can(PermissionName::LedgerAdmin), 403);
        $this->loadRules();
    }

    public function edit(mixed $id): void
    {
        $account = $this->find($id);
        $this->resetErrorBag();
        $this->editingId = $account->id;
        $this->code = $account->code;
        $this->name_en = $account->name_en;
        $this->name_ar = $account->name_ar;
        $this->type = $account->type->value;
    }

    public function cancel(): void
    {
        $this->resetErrorBag();
        $this->reset('editingId', 'code', 'name_en', 'name_ar', 'type');
    }

    public function save(): void
    {
        $account = $this->editingId === null ? null : LedgerAccount::query()->findOrFail($this->editingId);
        app(SaveLedgerAccount::class)->handle($this->actor(), $account, ['code' => $this->code, 'name_en' => $this->name_en, 'name_ar' => $this->name_ar, 'type' => $this->type]);
        $this->cancel();
        Flux::toast(variant: 'success', text: __('Account saved.'));
    }

    public function toggle(mixed $id): void
    {
        $account = $this->find($id);
        app(SetLedgerAccountActive::class)->handle($this->actor(), $account, ! $account->active);
    }

    public function delete(mixed $id): void
    {
        app(DeleteLedgerAccount::class)->handle($this->actor(), $this->find($id));
        Flux::toast(variant: 'success', text: __('Account deleted.'));
    }

    public function saveRules(): void
    {
        $chosen = array_values(is_array($this->ruleAccounts) ? $this->ruleAccounts : []);
        $map = [];
        foreach (Rules::keys() as $i => $key) {
            $map[$key] = $chosen[$i] ?? null;
        }
        $this->offerRebuild = app(SaveLedgerRules::class)->handle($this->actor(), $map) || $this->offerRebuild;
        $this->loadRules();
        Flux::toast(variant: 'success', text: __('Rules saved.'));
    }

    public function rebuildOpen(): void
    {
        $first = LedgerSetting::current()->firstOpenDay();
        app(RebuildLedger::class)->handle($this->actor(), ($first ?? now('Asia/Bahrain'))->format('Y-m'), now('Asia/Bahrain')->format('Y-m'));
        $this->offerRebuild = false;
        Flux::toast(variant: 'success', text: __('Rebuild started.'));
    }

    private function find(mixed $id): LedgerAccount
    {
        abort_unless(is_int($id) || (is_string($id) && ctype_digit($id) && strlen($id) < 19), 404);

        return LedgerAccount::query()->findOrFail((int) $id);
    }

    private function loadRules(): void
    {
        $map = DB::table('ledger_rules')->pluck('ledger_account_id', 'key');
        $this->ruleAccounts = array_map(fn (string $key) => isset($map[$key]) ? (string) $map[$key] : '', Rules::keys());
    }

    public function render(): View
    {
        $keys = Rules::keys();

        return view('livewire.ledger.accounts', [
            'accounts' => LedgerAccount::query()->withExists('lines')->orderBy('code')->get(),
            'active' => LedgerAccount::query()->where('active', true)->orderBy('code')->get(),
            'types' => LedgerAccountType::cases(),
            'ruleKeys' => $keys,
            'ruleLabels' => array_combine($keys, array_map(fn (string $k) => Rules::label($k), $keys)),
        ])->title(__('Accounts and rules'));
    }
}
