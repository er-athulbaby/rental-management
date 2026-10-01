<?php

namespace App\Livewire\Cheques;

use App\Actions\Cheques\BounceCheque;
use App\Actions\Cheques\ClearCheque;
use App\Actions\Cheques\EndCheque;
use App\Actions\Cheques\ReplaceCheque;
use App\Livewire\Concerns\WithActor;
use App\Models\Cheque;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use WithActor;

    #[Locked]
    public int $chequeId;

    public string $clearedOn = '';

    public string $bouncedOn = '';

    public string $bounceReason = '';

    /** @var array<string, string> */
    public array $replacement = ['cheque_no' => '', 'bank_name' => '', 'cheque_date' => '', 'amount' => ''];

    public function mount(Cheque $cheque): void
    {
        abort_unless($this->actor()->can('view', $cheque), 403);
        $this->chequeId = $cheque->id;
        $this->clearedOn = $this->bouncedOn = now('Asia/Bahrain')->toDateString();
        $this->replacement = ['cheque_no' => '', 'bank_name' => $cheque->bank_name, 'cheque_date' => now('Asia/Bahrain')->toDateString(), 'amount' => $cheque->amount];
    }

    private function cheque(): Cheque
    {
        return Cheque::findOrFail($this->chequeId);
    }

    /** @param  callable(): mixed  $run */
    private function run(callable $run, string $done): void
    {
        try {
            $run();
        } catch (AuthorizationException) {
            abort(403);
        }

        Flux::modals()->close();
        Flux::toast(variant: 'success', text: $done);
    }

    public function clear(ClearCheque $clear): void
    {
        $this->run(fn () => $clear->handle($this->actor(), $this->cheque(), $this->clearedOn), __('Cheque cleared and payment recorded.'));
    }

    public function bounce(BounceCheque $bounce): void
    {
        $this->run(function () use ($bounce) {
            try {
                $approval = $bounce->handle($this->actor(), $this->cheque(), $this->bouncedOn, $this->bounceReason);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => [match ($k) {
                    'bounce_reason', 'reversalReason' => 'bounceReason',
                    'bounced_on' => 'bouncedOn',
                    default => $k,
                } => $m])->all());
            }
            if ($approval) {
                Flux::toast(text: __('The payment reversal was sent for approval; the cheque bounces when it is approved.'));
            }
        }, __('Cheque updated.'));
    }

    public function replace(ReplaceCheque $replace): void
    {
        $this->run(function () use ($replace) {
            $new = $replace->handle($this->actor(), $this->cheque(), $this->replacement);
            $this->redirectRoute('cheques.show', $new, navigate: true);
        }, __('Replacement cheque entered.'));
    }

    public function end(EndCheque $end, string $outcome): void
    {
        $this->run(fn () => $end->handle($this->actor(), $this->cheque(), $outcome), __('Cheque updated.'));
    }

    public function render(): View
    {
        $cheque = Cheque::with(['customer', 'agreement:id,number', 'invoice', 'payment', 'replacedBy'])->findOrFail($this->chequeId);

        return view('livewire.cheques.show', [
            'cheque' => $cheque,
            'canManage' => $this->actor()->can('manage', Cheque::class),
        ])->title($cheque->bank_name.' #'.$cheque->cheque_no);
    }
}
