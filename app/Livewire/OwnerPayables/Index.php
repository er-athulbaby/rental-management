<?php

namespace App\Livewire\OwnerPayables;

use App\Actions\Disbursements\RecordDisbursement;
use App\Audit\Audit;
use App\Enums\DisbursementMethod;
use App\Enums\OwnerPayableStatus;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\Disbursement;
use App\Models\OwnerContract;
use App\Models\OwnerPayable;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Spec §10: head-lease payments due — scheduled payables to owners, paid from here (§7.5). */
class Index extends Component
{
    use WithActor;

    #[Url]
    public ?int $building = null;

    #[Url]
    public string $dueBy = '';

    #[Locked]
    public ?int $payingId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(): void
    {
        abort_unless($this->actor()->can('finance.view'), 403);
        $this->dueBy = $this->dueBy ?: now('Asia/Bahrain')->addDays(30)->toDateString();
    }

    public function startPaying(int $payableId): void
    {
        abort_unless($this->actor()->can('create', Disbursement::class), 403);
        $payable = $this->payables()->findOrFail($payableId);
        $this->payingId = $payable->id;
        $this->form = ['amount' => $payable->amount, 'method' => DisbursementMethod::BankTransfer->value, 'paid_on' => now('Asia/Bahrain')->toDateString()];
        Flux::modal('pay-head-lease')->show();
    }

    public function pay(RecordDisbursement $record): void
    {
        try {
            $out = $record->handle($this->actor(), [...$this->form, 'purpose' => 'head_lease', 'owner_payable_id' => $this->payingId]);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["form.$k" => $m])->all());
        }

        Flux::modal('pay-head-lease')->close();
        $this->reset('payingId', 'form');
        Flux::toast(variant: 'success', text: $out->status->value === 'paid' ? __('Paid as :n.', ['n' => $out->number]) : __('Sent to Management for approval.'));
    }

    /** @return Builder<OwnerPayable> */
    private function payables(): Builder
    {
        return OwnerPayable::query()->where('status', OwnerPayableStatus::Scheduled)
            ->whereIn('owner_contract_id', OwnerContract::visibleTo($this->actor())->select('id'));
    }

    /** @return Collection<int, OwnerPayable> */
    private function rows(): Collection
    {
        return $this->payables()
            ->with(['contract:id,number,owner_id,building_id', 'contract.owner:id,name_en', 'contract.building:id,code'])
            ->when($this->building, fn ($q, $b) => $q->whereIn('owner_contract_id', OwnerContract::query()->where('building_id', $b)->select('id')))
            ->where('due_date', '<=', $this->dueBy)
            ->orderBy('due_date')->orderBy('id')->get();
    }

    public function export(): BinaryFileResponse
    {
        abort_unless($this->actor()->can('reports.financial'), 403);
        Audit::log('report.exported', properties: ['report' => 'head_lease_due', 'due_by' => $this->dueBy, 'building' => $this->building], causer: $this->actor());

        $path = sys_get_temp_dir().'/rms-head-lease-'.Str::uuid().'.xlsx';
        $writer = SimpleExcelWriter::create($path);
        foreach ($this->rows() as $p) {
            $writer->addRow(['Due' => $p->due_date->toDateString(), 'Contract' => $p->contract?->number, 'Building' => $p->contract?->building?->code,
                'Owner' => $p->contract?->owner?->name_en, 'From' => $p->period_start->toDateString(), 'To' => $p->period_end->toDateString(), 'Amount' => $p->amount]);
        }
        $writer->close();

        return response()->download($path, "head-lease-due-{$this->dueBy}.xlsx")->deleteFileAfterSend();
    }

    public function render(): View
    {
        return view('livewire.owner-payables.index', [
            'rows' => $this->rows(),
            'buildings' => Building::visibleTo($this->actor())->orderBy('code')->get(['id', 'code', 'name']),
            'methods' => DisbursementMethod::cases(),
            'canPay' => $this->actor()->can('create', Disbursement::class),
        ])->title(__('Head-lease payments due'));
    }
}
