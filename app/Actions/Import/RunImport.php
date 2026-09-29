<?php

namespace App\Actions\Import;

use App\Actions\Buildings\SaveBuilding;
use App\Actions\OwnerContracts\ActivateOwnerContract;
use App\Actions\OwnerContracts\SaveOwnerContract;
use App\Actions\Owners\SaveOwner;
use App\Actions\Units\SaveUnit;
use App\Audit\Audit;
use App\Enums\ApprovalAction;
use App\Enums\ApprovalStatus;
use App\Enums\ImportKind;
use App\Enums\OwnerContractStatus;
use App\Enums\PermissionName;
use App\Models\Approval;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Owner;
use App\Models\Unit;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\SimpleExcel\SimpleExcelReader;
use Throwable;

/**
 * Spec §11. Dry run and real run are the same code: every row goes through the normal Actions
 * inside one transaction, which is committed only when asked AND every row of every file passed.
 */
final class RunImport
{
    public function __construct(
        private SaveBuilding $buildings,
        private SaveUnit $units,
        private SaveOwner $owners,
        private SaveOwnerContract $contracts,
        private ActivateOwnerContract $activate,
    ) {}

    /** @param  array<string, string>  $paths  ImportKind value => local .xlsx or .csv path */
    public function handle(User $actor, array $paths, bool $commit): ImportResult
    {
        if (! $actor->can(PermissionName::ImportRun)) {
            throw new AuthorizationException;
        }

        self::ensureOpen();

        $errors = [];
        $counts = [];

        DB::beginTransaction();

        try {
            foreach (ImportKind::cases() as $kind) {
                if (! isset($paths[$kind->value])) {
                    continue;
                }

                $counts[$kind->value] = 0;

                try {
                    $reader = SimpleExcelReader::create($paths[$kind->value])->headersToSnakeCase();
                    $headers = $reader->getHeaders() ?? [];
                } catch (Throwable) {
                    $errors[$kind->value][1] = [__('This file cannot be read as .xlsx or .csv.')];

                    continue;
                }

                $missing = array_values(array_diff($kind->headers(), $headers));

                if ($missing !== []) {
                    $errors[$kind->value][1] = [__('Missing columns: :list', ['list' => implode(', ', $missing)])];

                    continue;
                }

                $passed = 0;

                foreach ($reader->getRows() as $index => $row) {
                    try {
                        // A savepoint per row: a failed row leaves nothing behind for later rows to trip on.
                        DB::transaction(fn () => $this->importRow($actor, $kind, $this->normalise($kind, $row)));
                        $passed++;
                    } catch (ValidationException $e) {
                        $errors[$kind->value][(int) $index + 2] = array_values(Arr::flatten($e->errors()));
                    }
                }

                $counts[$kind->value] = $passed;
            }

            $committed = $commit && $errors === [];

            if ($committed) {
                Audit::log('import.run', properties: ['counts' => $counts], causer: $actor);
                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return new ImportResult($errors, $counts, $committed);
    }

    /** After cutover the import screens and Actions refuse to run (spec §11). */
    public static function ensureOpen(): void
    {
        $goLive = CompanySetting::current()->go_live_at;

        if ($goLive !== null && $goLive->isPast()) {
            throw ValidationException::withMessages(['import' => __('This company went live on :date; imports are closed.', [
                'date' => $goLive->timezone('Asia/Bahrain')->format('d/m/Y'),
            ])]);
        }
    }

    /** @param  array<string, mixed>  $row */
    private function importRow(User $actor, ImportKind $kind, array $row): void
    {
        match ($kind) {
            ImportKind::Buildings => $this->buildings->handle($actor, null, $row),
            ImportKind::Units => $this->units->handle($actor, null, [
                ...$row,
                'building_id' => $this->buildingId($row['building_code'] ?? null),
                'blocked' => in_array(strtolower((string) ($row['blocked'] ?? '')), ['yes', 'y', 'true', '1'], true),
            ]),
            ImportKind::Owners => $this->owners->handle($actor, null, $row, viaImport: true),
            ImportKind::OwnerContracts => $this->ownerContract($actor, $row),
        };
    }

    /** Imported contracts are created active, with an approval recorded as "Imported by {user}" (spec §11). */
    /** @param  array<string, mixed>  $row */
    private function ownerContract(User $actor, array $row): void
    {
        $buildingId = $this->buildingId($row['building_code'] ?? null);

        $ownerId = Owner::query()->where('id_type', $row['owner_id_type'] ?? '')->where('id_number', $row['owner_id_number'] ?? '')->value('id')
            ?? throw ValidationException::withMessages(['owner_id_number' => __('No owner with ID :type :number.', ['type' => $row['owner_id_type'] ?? '', 'number' => $row['owner_id_number'] ?? ''])]);

        $list = trim((string) ($row['units'] ?? ''));
        $codes = strtoupper($list) === 'ALL' ? null : array_values(array_unique(array_filter(array_map(trim(...), explode(',', $list)))));
        $unitIds = Unit::query()->where('building_id', $buildingId)
            ->when($codes !== null, fn ($q) => $q->whereIn('code', $codes))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($codes !== null && count($unitIds) !== count($codes)) {
            throw ValidationException::withMessages(['units' => __('Unknown unit codes in :list.', ['list' => $list])]);
        }

        $contract = $this->contracts->handle($actor, null, [...$row, 'owner_id' => $ownerId, 'building_id' => $buildingId, 'unit_ids' => $unitIds]);
        $contract->forceFill(['status' => OwnerContractStatus::PendingApproval])->save();

        Approval::create([
            'approvable_type' => $contract->getMorphClass(),
            'approvable_id' => $contract->id,
            'action' => ApprovalAction::OwnerContractActivation,
            'status' => ApprovalStatus::Approved,
            'requested_by' => $actor->id,
            'requested_at' => now(),
            'decided_by' => $actor->id,
            'decided_at' => now(),
            'comment' => __('Imported by :name', ['name' => $actor->name]),
            'ip' => request()->ip(),
        ]);

        $this->activate->handle($contract);
    }

    private function buildingId(mixed $code): int
    {
        return (int) (Building::query()->where('code', (string) $code)->value('id')
            ?? throw ValidationException::withMessages(['building_code' => __('No building with code :code.', ['code' => (string) $code])]));
    }

    /**
     * Cells to strings: dates to Y-m-d (dd/mm/yyyy text too), blanks to null; numbers in Text columns are refused.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalise(ImportKind $kind, array $row): array
    {
        $out = [];

        foreach ($row as $column => $value) {
            if ((is_int($value) || is_float($value)) && in_array($column, $kind->textColumns(), true)) {
                throw ValidationException::withMessages([$column => __('Column :column must be formatted as Text in Excel.', ['column' => $column])]);
            }

            $value = match (true) {
                $value instanceof DateTimeInterface => $value->format('Y-m-d'),
                is_int($value), is_float($value) => (string) $value,
                is_string($value) => trim($value),
                default => $value,
            };

            if (is_string($value) && str_ends_with((string) $column, '_date') && preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $value, $m)) {
                $value = "{$m[3]}-{$m[2]}-{$m[1]}";
            }

            $out[(string) $column] = $value === '' ? null : $value;
        }

        return $out;
    }
}
