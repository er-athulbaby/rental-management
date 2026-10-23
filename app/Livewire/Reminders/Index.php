<?php

namespace App\Livewire\Reminders;

use App\Audit\Audit;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\ReminderSend;
use App\Models\ReminderSetting;
use App\Support\SafeCells;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Spec 2026-10-17 §6: every reminder logged (sent, failed or skipped), with filters and an Excel export. */
class Index extends Component
{
    use WithActor, WithPagination;

    // Untyped on purpose: a tampered payload (an array, "abc") must reach validation, not throw a TypeError (a 500).
    #[Url]
    public mixed $from = '';

    #[Url]
    public mixed $to = '';

    #[Url]
    public mixed $type = '';

    #[Url]
    public mixed $status = '';

    #[Url]
    public mixed $building = '';

    public function mount(): void
    {
        abort_unless($this->actor()->canAny(['finance.view', 'agreements.view']), 403);
        $today = CarbonImmutable::today('Asia/Bahrain');
        $this->from = $this->from ?: $today->subDays(30)->toDateString();
        $this->to = $this->to ?: $today->toDateString();
        if (validator($this->filters(), $this->filterRules())->fails()) { // a bad URL: the defaults, not an error page
            $this->from = $today->subDays(30)->toDateString();
            $this->to = $today->toDateString();
            $this->type = $this->status = $this->building = '';
        }
    }

    public function updated(): void
    {
        $this->resetPage();
        $this->validate($this->filterRules());
    }

    public function export(): BinaryFileResponse
    {
        abort_unless($this->actor()->canAny(['finance.view', 'agreements.view']), 403);
        $this->validate($this->filterRules());

        $path = sys_get_temp_dir().'/rms-reminders-'.Str::uuid().'.xlsx';
        $writer = SimpleExcelWriter::create($path);
        $writer->addRow(SafeCells::row([__('Date'), __('Tenant'), __('Type'), __('Item'), __('Channel'), __('Status'), __('Reason'), __('Sent by')]));
        foreach ($this->query()->get() as $row) { // ponytail: the whole range in memory; chunk it if a range ever holds tens of thousands
            $writer->addRow(SafeCells::row([$row->sent_on->format('d/m/Y'), $row->customer->name_en ?? '', ReminderSetting::label($row->type), $row->itemLabel(),
                __('Email'), $row->statusLabel(), ReminderSend::reasonText($row->reason), $row->manual ? __('Manual · :name', ['name' => $row->sender->name ?? '']) : __('Automatic')]));
        }
        $writer->close();
        Audit::log('report.exported', properties: ['report' => 'reminders', ...$this->filters()], causer: $this->actor()); // only once the file exists

        return response()->download($path, "reminders-{$this->from}-{$this->to}.xlsx")->deleteFileAfterSend();
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'type' => $this->type, 'status' => $this->status, 'building' => $this->building];
    }

    /** @return array<string, list<mixed>> */
    private function filterRules(): array
    {
        return [
            'from' => ['bail', 'required', 'string', 'date_format:Y-m-d'],
            'to' => ['bail', 'required', 'string', 'date_format:Y-m-d', ...(is_string($this->from) ? ['after_or_equal:from'] : [])],
            'type' => ['nullable', 'string', Rule::in(ReminderSetting::TYPES)],
            'status' => ['nullable', 'string', Rule::in(ReminderSend::STATUSES)],
            'building' => ['nullable', Rule::in(Building::query()->visibleTo($this->actor())->pluck('id')->all())],
        ];
    }

    /** @return Builder<ReminderSend> nothing at all while a filter is invalid */
    private function query(): Builder
    {
        $valid = validator($this->filters(), $this->filterRules())->passes();

        return ReminderSend::query()->visibleTo($this->actor())
            ->when(! $valid, fn (Builder $q) => $q->whereRaw('1 = 0'))
            ->when($valid, fn (Builder $q) => $q->whereBetween('sent_on', [$this->from, $this->to])
                ->when(filled($this->type), fn (Builder $q) => $q->where('type', $this->type))
                ->when(filled($this->status), fn (Builder $q) => $q->where('status', $this->status))
                ->when(filled($this->building), fn (Builder $q) => $q->inBuilding((int) $this->building)))
            ->with(['customer:id,name_en', 'sender:id,name', 'invoice:id,number,status', 'cheque:id,cheque_no', 'agreement:id,number'])
            ->orderByDesc('sent_on')->orderByDesc('id');
    }

    public function render(): View
    {
        return view('livewire.reminders.index', [
            'rows' => $this->query()->paginate(50),
            'valid' => validator($this->filters(), $this->filterRules())->passes(),
            'buildings' => Building::query()->visibleTo($this->actor())->orderBy('code')->get(['id', 'code', 'name']),
            'types' => collect(ReminderSetting::TYPES)->mapWithKeys(fn (string $t) => [$t => ReminderSetting::label($t)])->all(),
            'statuses' => ['sent' => __('Sent'), 'failed' => __('Failed'), 'skipped' => __('Skipped')],
        ])->title(__('Reminders'));
    }
}
