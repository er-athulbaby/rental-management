<?php

namespace App\Livewire\Ledger;

use App\Actions\Ledger\DeleteBankUpload;
use App\Actions\Ledger\ImportBankStatement;
use App\Actions\Ledger\StartBankReconciliation;
use App\Enums\PermissionName;
use App\Livewire\Concerns\WithActor;
use App\Models\BankMapping;
use App\Models\BankReconciliation;
use App\Models\BankUpload;
use App\Models\LedgerAccount;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Bank reconciliation spec §2–§3: per money account, Finance uploads statements (with the saved column mapping) and starts a reconciliation; Finance and Admin view. */
class BankReconciliations extends Component
{
    use WithActor, WithFileUploads;

    /** The mapping fields, as ImportBankStatement takes them. */
    private const array MAPPING = ['header_row', 'date_column', 'date_format', 'description_column', 'reference_column', 'amount_mode', 'amount_column',
        'debit_column', 'credit_column', 'balance_column'];

    // Untyped on purpose: a tampered payload reaches the action's validator, never a TypeError (a 500).
    #[Url]
    public mixed $account = '';

    public mixed $file = null;

    public mixed $header_row = '1';

    public mixed $date_column = '1';

    public mixed $date_format = 'd/m/Y';

    public mixed $description_column = '2';

    public mixed $reference_column = '';

    public mixed $amount_mode = 'signed';

    public mixed $amount_column = '3';

    public mixed $debit_column = '';

    public mixed $credit_column = '';

    public mixed $balance_column = '';

    /** @var array{imported: int, skipped: int, failures: list<array{row: int, reason: string}>}|null */
    #[Locked]
    public ?array $result = null;

    public mixed $end_date = '';

    public mixed $closing_balance = '';

    public mixed $opening_balance = '';

    public function mount(): void
    {
        abort_unless($this->actor()->can(PermissionName::LedgerView), 403);
        $this->end_date = now('Asia/Bahrain')->toDateString();
        $this->loadMapping();
    }

    public function updatedAccount(): void
    {
        $this->result = null;
        $this->resetErrorBag();
        $this->loadMapping();
    }

    public function upload(ImportBankStatement $import): void
    {
        abort_unless($this->actor()->can(PermissionName::LedgerManage), 403);
        $this->resetErrorBag();
        $this->validate(['file' => ['required', 'file', 'extensions:csv,xlsx', 'max:5120']], ['file.max' => __('The file must be 5 MB or smaller.')]);
        $file = $this->file;
        if (! $file instanceof UploadedFile) {
            return;
        }
        $type = strtolower($file->getClientOriginalExtension()) === 'csv' ? 'csv' : 'xlsx';
        $stored = $file->storeAs('bank-statements', Str::uuid().'.'.$type, 'local') ?: abort(500);
        try {
            $out = $import->handle($this->actor(), $this->account, Storage::disk('local')->path($stored), $type, $file->getClientOriginalName(),
                array_combine(self::MAPPING, array_map(fn (string $field) => $this->{$field}, self::MAPPING)));
        } finally {
            Storage::disk('local')->delete($stored);
        }
        $this->result = ['imported' => $out['upload']->imported, 'skipped' => $out['upload']->skipped, 'failures' => $out['failures']];
        $this->reset('file');
        Flux::toast(variant: 'success', text: __('Statement uploaded.'));
    }

    public function deleteUpload(mixed $id): void
    {
        abort_unless(is_int($id) || (is_string($id) && ctype_digit($id) && strlen($id) < 19), 404);
        $this->resetErrorBag();
        app(DeleteBankUpload::class)->handle($this->actor(), BankUpload::query()->where('ledger_account_id', $this->accountId())->findOrFail((int) $id));
        Flux::toast(variant: 'success', text: __('Upload deleted.'));
    }

    public function start(): void
    {
        $this->resetErrorBag();
        $rec = app(StartBankReconciliation::class)->handle($this->actor(), ['ledger_account_id' => $this->account, 'end_date' => $this->end_date,
            'closing_balance' => $this->closing_balance, 'opening_balance' => $this->opening_balance]);
        Flux::toast(variant: 'success', text: __('Reconciliation started.'));
        $this->redirectRoute('ledger.bank-reconciliation.show', ['reconciliation' => $rec->id], navigate: true);
    }

    /** The chosen account's id when it is a money account; null otherwise (a tampered URL just shows the picker). */
    private function accountId(): ?int
    {
        $id = is_string($this->account) && ctype_digit($this->account) && strlen($this->account) < 19 ? (int) $this->account : 0;
        $found = LedgerAccount::query()->whereKey($id)->where('is_money', true)->value('id');

        return $found === null ? null : (int) $found;
    }

    /** The account's saved mapping (plan ruling 7), or the defaults when it has none. */
    private function loadMapping(): void
    {
        $id = $this->accountId();
        $mapping = $id === null ? null : BankMapping::query()->where('ledger_account_id', $id)->first();
        if ($mapping === null) {
            $this->reset(...self::MAPPING);

            return;
        }
        foreach (self::MAPPING as $field) {
            $value = $mapping->{$field};
            $this->{$field} = $value === null ? '' : (string) $value;
        }
    }

    public function render(): View
    {
        $id = $this->accountId();

        return view('livewire.ledger.bank-reconciliations', [
            'accounts' => LedgerAccount::query()->where('is_money', true)->orderBy('code')->get(),
            'selected' => $id,
            'columns' => array_combine(range(1, 26), range('A', 'Z')),
            'formats' => ImportBankStatement::DATE_FORMATS,
            'uploads' => $id === null ? collect() : BankUpload::query()->with('uploader')->where('ledger_account_id', $id)
                ->withExists(['lines as matched' => fn (Builder $q) => $q->has('matches')])->latest('id')->limit(50)->get(),
            'reconciliations' => $id === null ? collect() : BankReconciliation::query()->where('ledger_account_id', $id)->orderByDesc('end_date')->get(),
            'hasOpen' => $id !== null && BankReconciliation::query()->where('ledger_account_id', $id)->where('status', 'open')->exists(),
            'first' => $id !== null && ! BankReconciliation::query()->where('ledger_account_id', $id)->exists(),
            'canManage' => $this->actor()->can(PermissionName::LedgerManage),
        ])->title(__('Bank reconciliation'));
    }
}
