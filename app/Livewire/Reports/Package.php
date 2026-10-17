<?php

namespace App\Livewire\Reports;

use App\Audit\Audit;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\ReportPackage;
use App\Models\ReportPackageItem;
use App\Models\SavedReport;
use App\Models\User;
use App\Reports\Packages\PackageReport;
use App\Reports\Packages\PackageRunner;
use App\Reports\Packages\PackageWorkbook;
use App\Reports\Packages\ReadyMade;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Spec 2026-10-17 §3, §6: a package's items (add, remove, move, settings), Save, Share, Delete and Download now.
 * `items` is client-controlled: save() checks every item again, and render() tolerates anything.
 *
 * @phpstan-import-type Form from PackageReport
 */
class Package extends Component
{
    use WithActor;

    #[Locked]
    public ?int $packageId = null;

    // Untyped on purpose: a tampered payload must reach validation, not throw a TypeError (a 500).
    public mixed $name = '';

    public mixed $items = [];

    public mixed $add = '';

    public function mount(?ReportPackage $package = null): void
    {
        $user = $this->actor();
        abort_unless($user->can('reports.operational') || $user->can('reports.financial'), 403);
        if ($package?->exists) {
            abort_unless($user->can('view', $package), 403);
            $this->packageId = $package->id;
            $this->name = $package->name;
            $this->items = $package->items()->get()->map(fn (ReportPackageItem $i) => ['kind' => $i->kind, 'saved_report_id' => $i->saved_report_id,
                'report' => $i->report, 'settings' => $i->settings])->all();
        }
    }

    public function addItem(): void
    {
        $this->authorizeEdit();
        $user = $this->actor();
        $items = $this->itemList();
        if (count($items) >= PackageRunner::MAX_ITEMS) {
            $this->addError('add', __('A package holds at most :n reports.', ['n' => PackageRunner::MAX_ITEMS]));

            return;
        }
        [$kind, $ref] = array_pad(explode(':', is_string($this->add) ? $this->add : '', 2), 2, '');
        $form = $kind === 'ready' && isset(ReadyMade::REPORTS[$ref]) ? ReadyMade::make($ref)->packageForm($user) : null;
        if ($kind === 'saved' && ctype_digit($ref) && SavedReport::visibleTo($user)->whereKey((int) $ref)->exists()) {
            $items[] = ['kind' => 'saved', 'saved_report_id' => (int) $ref, 'report' => null, 'settings' => []];
        } elseif ($form !== null && $form['allowed']) {
            $items[] = ['kind' => 'ready_made', 'saved_report_id' => null, 'report' => $ref, 'settings' => self::defaults($form)];
        } else {
            $this->addError('add', __('Pick a report from the list.'));

            return;
        }
        $this->items = $items;
        $this->add = '';
    }

    public function removeItem(int $index): void
    {
        $this->authorizeEdit();
        $items = $this->itemList();
        unset($items[$index]);
        $this->items = array_values($items);
    }

    public function moveItem(int $index, int $by): void
    {
        $this->authorizeEdit();
        $items = $this->itemList();
        $to = $index + $by;
        if (isset($items[$index], $items[$to])) {
            [$items[$index], $items[$to]] = [$items[$to], $items[$index]];
            $this->items = $items;
        }
    }

    public function save(): void
    {
        $user = $this->actor();
        $this->authorizeEdit();
        $max = PackageRunner::MAX_ITEMS;
        $this->validate(['name' => ['required', 'string', 'max:120'], 'items' => ['required', 'array', 'min:1', "max:{$max}"]], [
            'name.required' => __('Give the package a name.'), 'items.required' => __('Add at least one report.'),
            'items.min' => __('Add at least one report.'), 'items.max' => __('A package holds at most :n reports.', ['n' => $max]),
        ]);
        $checked = [];
        foreach (array_values((array) $this->items) as $i => $item) {
            $checked[] = $this->checkedItem($user, $i, $item);
        }
        $name = (string) $this->name;
        $package = DB::transaction(function () use ($user, $checked, $name) {
            if ($this->packageId === null) {
                $package = ReportPackage::create(['name' => $name, 'visibility' => 'private', 'created_by' => $user->id, 'updated_by' => $user->id]);
            } else {
                $package = ReportPackage::findOrFail($this->packageId);
                $package->update(['name' => $name, 'updated_by' => $user->id]);
                $package->items()->delete();
            }
            foreach ($checked as $n => $item) {
                $package->items()->create([...$item, 'position' => $n + 1]);
            }

            return $package;
        });
        Audit::log('report.saved', $package, properties: ['name' => $package->name, 'package' => true, 'items' => count($checked)], causer: $user);
        Flux::toast(variant: 'success', text: __('Package saved.'));
        $this->redirectRoute('reports.package', $package, navigate: true);
    }

    public function share(bool $shared): void
    {
        $package = $this->editable();
        $package->update(['visibility' => $shared ? 'shared' : 'private']);
        Audit::log('report.shared', $package, properties: ['visibility' => $package->visibility, 'package' => true], causer: $this->actor());
        Flux::toast(text: $shared ? __('Shared with everyone who has report access.') : __('Now only you can see it.'));
    }

    /** Plan ruling 5: the schedules go first, in code, because their foreign key has no cascade. */
    public function deletePackage(): void
    {
        $package = ReportPackage::findOrFail((int) $this->packageId);
        abort_unless($this->actor()->can('delete', $package), 403);
        DB::transaction(function () use ($package) {
            $package->schedules()->delete();
            $package->delete();
        });
        Audit::log('report.deleted', $package, properties: ['name' => $package->name, 'package' => true], causer: $this->actor());
        $this->redirectRoute('reports.index', navigate: true);
    }

    /** Spec §6: the saved package, built for the person clicking (plan ruling 15). */
    public function download(): BinaryFileResponse
    {
        $user = $this->actor();
        $package = ReportPackage::findOrFail((int) $this->packageId);
        abort_unless($user->can('view', $package), 403);
        $result = app(PackageRunner::class)->run($package, $user, CarbonImmutable::now('Asia/Bahrain'));
        $path = sys_get_temp_dir().'/rms-package-'.Str::uuid().'.xlsx';
        PackageWorkbook::write($result, $path);
        Audit::log('report.exported', properties: ['report' => 'package', 'package' => $package->id], causer: $user);

        return response()->download($path, (Str::slug($package->name) ?: 'package').'-'.now('Asia/Bahrain')->toDateString().'.xlsx')->deleteFileAfterSend();
    }

    /** The saved package, if the user may change it: 404 when unsaved, 403 otherwise. */
    private function editable(): ReportPackage
    {
        $package = ReportPackage::findOrFail((int) $this->packageId);
        abort_unless($this->actor()->can('update', $package), 403);

        return $package;
    }

    private function authorizeEdit(): void
    {
        if ($this->packageId !== null) {
            $this->editable();
        }
    }

    /**
     * One item checked for $user, as stored. Errors are keyed items.<i> or items.<i>.settings.<key>.
     *
     * @return array{kind: string, saved_report_id: int|null, report: string|null, settings: array<array-key, mixed>}
     */
    private function checkedItem(User $user, int $i, mixed $item): array
    {
        $kind = is_array($item) ? ($item['kind'] ?? null) : null;
        if ($kind === 'saved') {
            $id = $item['saved_report_id'] ?? null;
            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && SavedReport::visibleTo($user)->whereKey((int) $id)->exists()) {
                return ['kind' => 'saved', 'saved_report_id' => (int) $id, 'report' => null, 'settings' => []];
            }
            throw ValidationException::withMessages(["items.{$i}" => __('Pick a saved report you can see.')]);
        }
        $key = is_array($item) ? ($item['report'] ?? null) : null;
        if ($kind !== 'ready_made' || ! is_string($key) || ! isset(ReadyMade::REPORTS[$key])) {
            throw ValidationException::withMessages(["items.{$i}" => __('Pick a report from the list.')]);
        }
        $report = ReadyMade::make($key);
        $form = $report->packageForm($user);
        if (! $form['allowed']) {
            throw ValidationException::withMessages(["items.{$i}" => __('You do not have access to this report.')]);
        }
        $settings = PackageRunner::clean($form, is_array($item['settings'] ?? null) ? $item['settings'] : []);
        try {
            $report->checkSettings($user, PackageRunner::resolve($form, $settings, CarbonImmutable::now('Asia/Bahrain'))['settings']);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn (array $m, string $k) => ["items.{$i}.settings.{$k}" => $m])->all());
        }

        return ['kind' => 'ready_made', 'saved_report_id' => null, 'report' => $key, 'settings' => $settings];
    }

    /** @return list<array<array-key, mixed>> the items that are at least arrays, in order */
    private function itemList(): array
    {
        return array_values(array_filter(is_array($this->items) ? $this->items : [], 'is_array'));
    }

    /**
     * Plan ruling 16: all buildings, "Today" for an as-at report, "Last month" otherwise, each option's first choice.
     *
     * @param  Form  $form
     * @return array<string, mixed>
     */
    private static function defaults(array $form): array
    {
        $settings = $form['building'] ? ['building' => null] : [];
        if ($form['dates'] !== 'none') {
            $settings['period'] = $form['dates'] === 'single' ? 'today' : 'last_month';
        }
        foreach ($form['options'] as $property => $choices) {
            $settings[$property] = (string) array_key_first($choices);
        }

        return $settings;
    }

    /**
     * What the list shows per item. A tampered entry shows as "Unknown report", never a 500. `index` is the item's key in
     * $items (for wire:model); the list position is the view's loop index (for move / remove).
     *
     * @param  array<string, Form>  $forms
     * @param  array<array-key, mixed>  $savedNames
     * @return list<array{index: int|string, title: string, kind: string, form: Form|null, locked: bool}>
     */
    private function itemViews(array $forms, array $savedNames): array
    {
        $views = [];
        foreach (is_array($this->items) ? $this->items : [] as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            if (($item['kind'] ?? null) === 'saved') {
                $id = $item['saved_report_id'] ?? null;
                $name = is_int($id) || (is_string($id) && ctype_digit($id)) ? ($savedNames[(int) $id] ?? null) : null;
                $views[] = ['index' => $index, 'title' => $name === null ? __('Saved report (not available)') : (string) $name, 'kind' => __('Saved report'), 'form' => null, 'locked' => false];

                continue;
            }
            $key = $item['report'] ?? null;
            $form = is_string($key) ? ($forms[$key] ?? null) : null;
            $views[] = ['index' => $index, 'title' => $form['title'] ?? __('Unknown report'), 'kind' => __('Ready-made report'),
                'form' => $form !== null && $form['allowed'] ? $form : null, 'locked' => $form !== null && ! $form['allowed']];
        }

        return $views;
    }

    public function render(): View
    {
        $user = $this->actor();
        $package = $this->packageId === null ? null : ReportPackage::findOrFail($this->packageId);
        $forms = [];
        foreach (array_keys(ReadyMade::REPORTS) as $key) {
            $forms[$key] = ReadyMade::make($key)->packageForm($user);
        }
        $saved = SavedReport::visibleTo($user)->orderBy('name')->get(['id', 'name']);

        return view('livewire.reports.package', [
            'rows' => $this->itemViews($forms, $saved->pluck('name', 'id')->all()),
            'ready' => array_filter($forms, fn (array $f) => $f['allowed']),
            'saved' => $saved,
            'presets' => PackageRunner::presets(),
            'buildings' => Building::visibleTo($user)->orderBy('code')->get(['id', 'code', 'name']),
            'canEdit' => $package === null || $user->can('update', $package),
            'shared' => $package?->visibility === 'shared',
        ])->title(is_string($this->name) && $this->name !== '' ? $this->name : __('New package'));
    }
}
