<?php

namespace App\Livewire\Disbursements;

use App\Actions\Disbursements\RecordDisbursement;
use App\Enums\DisbursementMethod;
use App\Livewire\Concerns\WithActor;
use App\Models\Customer;
use App\Models\Disbursement;
use App\Models\Expense;
use App\Models\Owner;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** A payment out with no approved source (purpose other), or a vendor bill (ledger money screens spec §2, plan ruling 11). */
#[Title('New payment out')]
class Create extends Component
{
    use WithActor;

    /** @var array<string, mixed> */
    public array $form = ['payee_type' => 'owner', 'method' => 'bank_transfer', 'expense_ids' => []];

    /** @var list<int> unpaid expenses added by hand to a vendor bill, beyond the vendor's own work orders */
    #[Locked]
    public array $added = [];

    public mixed $extra = '';

    public function mount(): void
    {
        abort_unless($this->actor()->can('create', Disbursement::class), 403);
    }

    public function updatedFormPayeeType(): void
    {
        unset($this->form['payee_id']);
        $this->clearExpenses();
    }

    public function updatedFormPayeeId(): void
    {
        $this->clearExpenses();
    }

    public function addExpense(): void
    {
        $id = is_string($this->extra) && ctype_digit($this->extra) && strlen($this->extra) < 19 ? (int) $this->extra : 0;
        if (! Expense::query()->unpaid()->visibleTo($this->actor())->whereKey($id)->exists()) {
            throw ValidationException::withMessages(['extra' => __('Choose an unpaid expense.')]);
        }
        $this->added = array_values(array_unique([...$this->added, $id]));
        $this->form['expense_ids'] = array_values(array_unique([...self::ticked($this->form['expense_ids'] ?? []), (string) $id]));
        $this->extra = '';
    }

    public function save(RecordDisbursement $record): void
    {
        $data = ($this->form['payee_type'] ?? null) === 'vendor'
            ? ['purpose' => 'vendor_bill', 'payee_type' => 'vendor', 'payee_id' => $this->form['payee_id'] ?? null,
                'expense_ids' => $this->form['expense_ids'] ?? [], 'method' => $this->form['method'] ?? null]
            : [...Arr::except($this->form, ['expense_ids']), 'purpose' => 'other'];
        try {
            $out = $record->handle($this->actor(), $data);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["form.$k" => $m])->all());
        }

        $this->redirectRoute('disbursements.show', $out, navigate: true);
    }

    private function clearExpenses(): void
    {
        $this->form['expense_ids'] = [];
        $this->added = [];
    }

    /** @return list<string> the ticked expense ids, whatever the browser sent */
    private static function ticked(mixed $ids): array
    {
        return array_values(array_map('strval', array_filter(is_array($ids) ? $ids : [], fn (mixed $id) => is_int($id) || is_string($id))));
    }

    /** @return Collection<int, Expense> spec §2: the vendor's unpaid work-order expenses first, then those added by hand */
    private function candidates(): Collection
    {
        $vendor = $this->form['payee_id'] ?? null;
        $vendor = is_int($vendor) || (is_string($vendor) && ctype_digit($vendor) && strlen($vendor) < 19) ? (int) $vendor : 0;
        $unpaid = fn () => Expense::query()->unpaid()->visibleTo($this->actor())->with('building:id,code')->orderBy('expense_date')->orderBy('id');

        return $unpaid()->whereIn('id', WorkOrder::query()->where('vendor_id', $vendor)->whereNotNull('expense_id')->select('expense_id'))->get()
            ->merge($unpaid()->whereKey($this->added)->get());
    }

    public function render(): View
    {
        $vendorMode = ($this->form['payee_type'] ?? null) === 'vendor';
        $candidates = $vendorMode ? $this->candidates() : new Collection;
        $ticked = self::ticked($this->form['expense_ids'] ?? []);

        return view('livewire.disbursements.create', [
            'payees' => match ($this->form['payee_type'] ?? 'owner') {
                'customer' => Customer::query()->visibleTo($this->actor())->orderBy('name_en')->get(['id', 'name_en']),
                'vendor' => Vendor::query()->where('active', true)->orderBy('name')->get(['id', 'name as name_en']),
                default => Owner::query()->orderBy('name_en')->get(['id', 'name_en']),
            },
            'methods' => DisbursementMethod::cases(),
            'vendorMode' => $vendorMode,
            'candidates' => $candidates,
            // ponytail: the newest 200 other unpaid expenses; a search box when that stops being enough
            'others' => $vendorMode ? Expense::query()->unpaid()->visibleTo($this->actor())->with('building:id,code')->whereNotIn('id', $candidates->modelKeys())
                ->latest('expense_date')->latest('id')->limit(200)->get() : new Collection,
            'total' => Fils::toDecimal((int) $candidates->filter(fn (Expense $e) => in_array((string) $e->id, $ticked, true))->sum(fn (Expense $e) => Fils::fromDecimal($e->total))),
        ]);
    }
}
