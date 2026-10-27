<?php

namespace App\Livewire\Ledger;

use App\Actions\Ledger\ReverseManualJournal;
use App\Enums\PermissionName;
use App\Ledger\Sources;
use App\Livewire\Concerns\WithActor;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Support\Fils;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** One journal with its lines, a link to its source, and (manual, not yet reversed, Finance) the reversal form. */
class JournalShow extends Component
{
    use WithActor;

    #[Locked]
    public int $journalId;

    public mixed $reverseDate = '';

    public function mount(string $journal): void
    {
        abort_unless($this->actor()->can(PermissionName::LedgerView), 403);
        $this->journalId = Journal::query()->findOrFail(ctype_digit($journal) && strlen($journal) < 19 ? (int) $journal : 0)->id;
        $this->reverseDate = now('Asia/Bahrain')->toDateString();
    }

    public function reverse(): void
    {
        $reversal = app(ReverseManualJournal::class)->handle($this->actor(), Journal::query()->findOrFail($this->journalId), $this->reverseDate);
        Flux::toast(variant: 'success', text: __('Journal reversed.'));
        $this->redirectRoute('ledger.journals.show', ['journal' => $reversal->id], navigate: true);
    }

    public function render(): View
    {
        $journal = Journal::query()->with(['lines.account', 'lines.customer:id,name_en', 'lines.owner:id,name_en', 'lines.building:id,code', 'lines.unit:id,code'])
            ->findOrFail($this->journalId);
        $reversal = Journal::query()->where('reverses_journal_id', $journal->id)->value('id');
        $paymentId = $journal->source_type === 'allocation' ? DB::table('payment_allocations')->where('id', $journal->source_id)->value('payment_id') : null;

        return view('livewire.ledger.journal-show', [
            'journal' => $journal,
            'source' => Sources::label($journal->source_type ?? $journal->kind),
            'sourceUrl' => $journal->kind === 'auto' ? Sources::url($journal->kind, $journal->source_type, $journal->source_id, $paymentId === null ? null : (int) $paymentId, $journal->id) : null,
            'reversal' => $reversal === null ? null : (int) $reversal,
            'canReverse' => $journal->kind === 'manual' && $journal->reverses_journal_id === null && $reversal === null && $this->actor()->can(PermissionName::LedgerManage),
            'debits' => Fils::toDecimal((int) $journal->lines->sum(fn (JournalLine $l) => Fils::fromDecimal($l->debit))),
            'credits' => Fils::toDecimal((int) $journal->lines->sum(fn (JournalLine $l) => Fils::fromDecimal($l->credit))),
        ])->title(__('Journal :id', ['id' => $journal->id]));
    }
}
