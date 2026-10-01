<?php

namespace App\Livewire\Disbursements;

use App\Actions\Cheques\ClearIssuedCheque;
use App\Actions\Disbursements\PayDisbursement;
use App\Actions\Disbursements\RequestDisbursementReversal;
use App\Enums\ApprovalAction;
use App\Enums\DisbursementMethod;
use App\Livewire\Concerns\WithActor;
use App\Models\Approval;
use App\Models\Cheque;
use App\Models\Disbursement;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use WithActor;

    #[Locked]
    public int $disbursementId;

    /** @var array<string, string> */
    public array $pay = ['method' => 'bank_transfer', 'paid_on' => '', 'reference' => '', 'cheque_no' => '', 'bank_name' => '', 'cheque_date' => ''];

    public string $reversalReason = '';

    public string $clearedOn = '';

    public function mount(Disbursement $disbursement): void
    {
        abort_unless($this->actor()->can('view', $disbursement), 403);
        $this->disbursementId = $disbursement->id;
        $this->pay['paid_on'] = $this->pay['cheque_date'] = $this->clearedOn = now('Asia/Bahrain')->toDateString();
    }

    protected function disbursement(): Disbursement
    {
        return Disbursement::with(['creator:id,name', 'recorder:id,name', 'cheque'])->findOrFail($this->disbursementId);
    }

    public function payNow(PayDisbursement $pay): void
    {
        try {
            $pay->handle($this->actor(), $this->disbursement(), $this->pay);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["pay.$k" => $m])->all());
        }
        Flux::modal('pay')->close();
        Flux::toast(variant: 'success', text: __('Payment out recorded as paid.'));
    }

    public function requestReversal(RequestDisbursementReversal $request): void
    {
        try {
            $request->handle($this->actor(), $this->disbursement(), $this->reversalReason);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['reversalReason' => Arr::flatten($e->errors())]);
        }
        $this->reset('reversalReason');
        Flux::modal('reverse')->close();
        Flux::toast(variant: 'success', text: __('Reversal sent for approval.'));
    }

    public function clearCheque(ClearIssuedCheque $clear): void
    {
        $cheque = $this->disbursement()->cheque;
        abort_if($cheque === null, 404);
        try {
            $clear->handle($this->actor(), $cheque, $this->clearedOn);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['clearedOn' => Arr::flatten($e->errors())]);
        }
        Flux::toast(variant: 'success', text: __('Cheque marked cleared.'));
    }

    public function render(): View
    {
        $out = $this->disbursement();
        $canManage = $this->actor()->can('create', Disbursement::class);
        $pending = fn (ApprovalAction $action) => Approval::query()->pending()->where('approvable_type', $out->getMorphClass())
            ->where('approvable_id', $out->id)->where('action', $action)->exists();

        return view('livewire.disbursements.show', [
            'out' => $out,
            'methods' => DisbursementMethod::cases(),
            'canPay' => $canManage && $out->status->value === 'approved',
            'canReverse' => $this->actor()->can('reverse', $out) && $out->status->value === 'paid',
            'pendingReversal' => $pending(ApprovalAction::PaymentOutReversal),
            'canClearCheque' => $this->actor()->can('manage', Cheque::class) && $out->cheque?->status->value === 'issued',
        ])->title($out->label());
    }
}
