<?php

namespace App\Livewire\Ledger;

use App\Actions\Ledger\PostManualJournal;
use App\Enums\PermissionName;
use App\Livewire\Concerns\WithActor;
use App\Models\LedgerAccount;
use App\Support\Fils;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** Spec §4: Finance enters a manual journal. Blank rows are dropped before posting, so error keys match the rows left. */
class NewJournal extends Component
{
    use WithActor;

    private const array BLANK = ['account_id' => '', 'debit' => '', 'credit' => '', 'memo' => ''];

    // Untyped on purpose: a tampered payload reaches the action's validator, never a TypeError (a 500).
    public mixed $date = '';

    public mixed $description = '';

    public mixed $lines = [];

    public function mount(): void
    {
        abort_unless($this->actor()->can(PermissionName::LedgerManage), 403);
        $this->date = now('Asia/Bahrain')->toDateString();
        $this->lines = [self::BLANK, self::BLANK];
    }

    public function addLine(): void
    {
        $lines = $this->rows();
        if (count($lines) < 50) {
            $lines[] = self::BLANK;
        }
        $this->lines = $lines;
    }

    public function removeLine(mixed $index): void
    {
        $lines = $this->rows();
        if ((is_int($index) || (is_string($index) && ctype_digit($index) && strlen($index) < 4)) && isset($lines[(int) $index])) {
            unset($lines[(int) $index]);
        }
        $this->lines = array_values($lines);
    }

    public function save(): void
    {
        $this->lines = array_values(array_filter($this->rows(), fn (array $l) => filled($l['account_id'] ?? null) || filled($l['debit'] ?? null) || filled($l['credit'] ?? null)));
        $journal = app(PostManualJournal::class)->handle($this->actor(), ['date' => $this->date, 'description' => $this->description, 'lines' => $this->lines]);
        Flux::toast(variant: 'success', text: __('Journal posted.'));
        $this->redirectRoute('ledger.journals.show', ['journal' => $journal->id], navigate: true);
    }

    /** @return list<array<array-key, mixed>> the line rows, whatever the browser sent */
    private function rows(): array
    {
        return array_values(array_filter(is_array($this->lines) ? $this->lines : [], 'is_array'));
    }

    public function render(): View
    {
        $sum = function (string $side): string {
            $fils = 0;
            foreach ($this->rows() as $line) {
                $value = $line[$side] ?? '';
                if (is_string($value) && preg_match('/^\d{1,9}(\.\d{1,3})?$/', $value) === 1) {
                    $fils += Fils::fromDecimal($value);
                }
            }

            return Fils::toDecimal($fils);
        };

        return view('livewire.ledger.new-journal', [
            'rows' => $this->rows(),
            'accounts' => LedgerAccount::query()->where('active', true)->orderBy('code')->get(),
            'debits' => $sum('debit'),
            'credits' => $sum('credit'),
        ])->title(__('New manual journal'));
    }
}
