<?php

namespace App\Livewire\Import;

use App\Actions\Import\RunImport;
use App\Enums\ImportKind;
use App\Enums\PermissionName;
use App\Livewire\Concerns\WithActor;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Title('Data import')]
class Index extends Component
{
    use WithActor, WithFileUploads;

    /** @var array<string, UploadedFile|null> */
    public array $files = [];

    /** @var array{errors: array<string, array<int, list<string>>>, counts: array<string, int>, totals: array<string, string>, committed: bool, commit: bool}|null */
    public ?array $result = null;

    public function mount(): void
    {
        abort_unless($this->actor()->can(PermissionName::ImportRun), 403);
    }

    public function run(RunImport $import, bool $commit = false): void
    {
        $this->files = array_filter($this->files);
        $this->validate([
            'files' => ['required', 'array:'.implode(',', array_map(fn (ImportKind $k) => $k->value, ImportKind::cases()))],
            // By extension: finfo often reports .xlsx as application/zip. A bad file is reported by RunImport.
            'files.*' => ['file', 'extensions:xlsx,csv', 'max:10240'],
        ]);

        $stored = [];
        foreach ($this->files as $kind => $file) {
            $extension = strtolower($file->getClientOriginalExtension()) === 'csv' ? 'csv' : 'xlsx';
            $stored[$kind] = $file->storeAs('imports', Str::uuid().'.'.$extension, 'local') ?: abort(500);
        }

        try {
            $result = $import->handle($this->actor(), array_map(fn ($path) => Storage::disk('local')->path($path), $stored), $commit);
        } catch (AuthorizationException) {
            abort(403);
        } finally {
            Storage::disk('local')->delete(array_values($stored));
        }

        $this->result = ['errors' => $result->errors, 'counts' => $result->counts, 'totals' => $result->totals, 'committed' => $result->committed, 'commit' => $commit];

        if ($result->committed) {
            $this->reset('files');
            Flux::toast(variant: 'success', text: __('Import saved.'));
        }
    }

    public function render(): View
    {
        $closed = null;
        try {
            RunImport::ensureOpen();
        } catch (ValidationException $e) {
            $closed = collect($e->errors())->flatten()->first();
        }

        return view('livewire.import.index', [
            'kinds' => ImportKind::cases(),
            'closed' => $closed,
            'cutover' => $closed === null ? rescue(fn () => RunImport::cutover(), null, false) : null,
        ]);
    }
}
