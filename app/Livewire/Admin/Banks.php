<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\WithActor;
use App\Models\Bank;
use App\Models\Cheque;
use App\Models\Owner;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

/** The Admin-managed list of banks. A bank is switched off rather than deleted; records store its name. */
class Banks extends Component
{
    use WithActor;

    public string $name = '';

    public function mount(): void
    {
        abort_unless($this->actor()->can('settings.manage'), 403);
    }

    public function add(): void
    {
        abort_unless($this->actor()->can('settings.manage'), 403);
        $this->name = trim($this->name);
        $this->validate(['name' => ['required', 'string', 'max:100', Rule::unique('banks', 'name')]]);

        Bank::create(['name' => $this->name]);
        $this->reset('name');
        Flux::toast(variant: 'success', text: __('Bank added.'));
    }

    public function rename(int $id, string $name): void
    {
        abort_unless($this->actor()->can('settings.manage'), 403);
        $name = trim($name);
        validator(['name' => $name], ['name' => ['required', 'string', 'max:100', Rule::unique('banks', 'name')->ignore($id)]])->validate();

        Bank::query()->findOrFail($id)->update(['name' => $name]);
    }

    public function toggle(int $id): void
    {
        abort_unless($this->actor()->can('settings.manage'), 403);
        $bank = Bank::query()->findOrFail($id);
        $bank->update(['active' => ! $bank->active]);
    }

    public function render(): View
    {
        // Owners and cheques that name each bank (the collation matches names case-insensitively, as the list does).
        $uses = collect([Owner::query(), Cheque::query()])
            ->flatMap(fn ($q) => $q->whereNotNull('bank_name')->groupBy('bank_name')->select('bank_name', DB::raw('count(*) as n'))->get())
            ->groupBy(fn ($row) => mb_strtolower((string) $row->bank_name))
            ->map(fn ($rows) => (int) $rows->sum('n'));

        return view('livewire.admin.banks', [
            'banks' => Bank::query()->orderByDesc('active')->orderBy('name')->get(),
            'uses' => $uses,
        ])->title(__('Banks'));
    }
}
