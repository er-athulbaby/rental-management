<?php

namespace App\Livewire\Reminders;

use App\Livewire\Concerns\WithActor;
use App\Mail\TenantReminder;
use App\Models\Agreement;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\ReminderSend;
use App\Reminders\ReminderFinder;
use App\Reminders\ReminderSender;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Spec 2026-10-17 §6: the "Send reminder" button, with a preview of the exact email, on invoice, cheque and agreement
 * pages, and the record's reminders. send() is the only browser-callable action; it re-checks everything (plan ruling 13).
 */
class RecordPanel extends Component
{
    use WithActor;

    /** Record classes the panel accepts => the kind it stores (reminder_sends.item_type, or 'customer'). */
    private const array KINDS = [Invoice::class => 'invoice', Cheque::class => 'cheque', Agreement::class => 'agreement', Customer::class => 'customer'];

    #[Locked]
    public string $kind = '';

    #[Locked]
    public int $recordId = 0;

    public function mount(Model $record): void
    {
        abort_unless(isset(self::KINDS[$record::class]), 403);
        $this->kind = self::KINDS[$record::class];
        $this->recordId = (int) $record->getKey();
        $this->record(); // 403 now, not at the first render
    }

    /** The record, if the user may open it: permission and building scope, through its policy. */
    private function record(): Invoice|Cheque|Agreement|Customer
    {
        $record = match ($this->kind) {
            'invoice' => Invoice::query()->find($this->recordId),
            'cheque' => Cheque::query()->find($this->recordId),
            'agreement' => Agreement::query()->find($this->recordId),
            'customer' => Customer::query()->find($this->recordId),
            default => null,
        };
        abort_unless($record !== null && $this->actor()->can('view', $record), 403);

        return $record;
    }

    public function send(ReminderSender $sender): void
    {
        $record = $this->record();
        $today = CarbonImmutable::today('Asia/Bahrain');
        $item = $record instanceof Customer ? null : ReminderFinder::manual($record, $today);
        if ($item === null) {
            throw ValidationException::withMessages(['reminder' => __('There is nothing to remind the tenant about on this record.')]);
        }
        $tenant = $item->tenant();
        $refusal = $sender->refusal($tenant);
        if ($refusal !== null) {
            throw ValidationException::withMessages(['reminder' => __('Can\'t send: :reason', ['reason' => ReminderSend::reasonText($refusal)])]);
        }

        $status = $sender->send($tenant, [$item], $today, $this->actor());

        Flux::modal('send-reminder')->close();
        if ($status === 'sent') {
            Flux::toast(variant: 'success', text: __('Reminder sent to :email.', ['email' => $tenant->email]));
        } else {
            Flux::toast(variant: 'danger', text: __('The reminder could not be sent. The reason is in the reminders list.'));
        }
    }

    public function render(): View
    {
        $record = $this->record();
        $item = $record instanceof Customer ? null : ReminderFinder::manual($record, CarbonImmutable::today('Asia/Bahrain'));
        $refusal = $item === null ? null : app(ReminderSender::class)->refusal($item->tenant());
        $mail = $item !== null && $refusal === null ? new TenantReminder($item->tenant(), [$item]) : null;

        // Only what the viewer could open (plan ruling 14); on a tenant, every item of theirs.
        $sends = ReminderSend::query()->visibleTo($this->actor())
            ->when($record instanceof Customer,
                fn (Builder $q) => $q->where('customer_id', $record->getKey()),
                fn (Builder $q) => $q->where('item_type', $this->kind)->where('item_id', $record->getKey()))
            ->with(['invoice:id,number,status', 'cheque:id,cheque_no', 'agreement:id,number', 'sender:id,name'])
            ->latest('id')->limit(20)->get();

        return view('livewire.reminders.record-panel', [
            'sendable' => $item !== null,
            'refusal' => $refusal === null ? null : ReminderSend::reasonText($refusal),
            'email' => $item?->tenant()->email,
            'subject' => $mail?->subjectLine(),
            'previewHtml' => $mail?->render(),
            'sends' => $sends,
            'showItem' => $record instanceof Customer,
        ]);
    }
}
