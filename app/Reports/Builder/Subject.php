<?php

namespace App\Reports\Builder;

use App\Enums\PermissionName;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/** A reportable subject (spec §4.2): where its rows come from, scoped to the user, and its whitelisted fields. */
abstract class Subject
{
    abstract public function key(): string;

    abstract public function label(): string;

    /** Money in, Leasing, Owners or Costs (the subject picker's groups). */
    abstract public function group(): string;

    abstract public function permission(): PermissionName;

    /** Rows the user may see, already narrowed to the building when one is chosen. */
    abstract public function query(User $user, ?int $buildingId, CarbonImmutable $asAt): Builder;

    /** @return list<Field> */
    abstract protected function define(CarbonImmutable $asAt): array;

    /** @return list<string> */
    abstract public function defaultColumns(): array;

    /** @return list<string> date field keys a period can use; [] when the subject has none */
    public function periodFields(): array
    {
        return [];
    }

    /** True for subjects read "as at" a date (Units, Deposits held). */
    public function usesAsAt(): bool
    {
        return false;
    }

    /** @return array{field: string, dir: string} */
    public function defaultSort(): array
    {
        return ['field' => $this->defaultColumns()[0], 'dir' => 'asc'];
    }

    /** @return array<string, Field> the fields this user may use, keyed */
    public function fields(User $user, CarbonImmutable $asAt): array
    {
        $fields = [];
        foreach ($this->define($asAt) as $field) {
            if ($field->permission === null || $user->can($field->permission)) {
                $fields[$field->key] = $field;
            }
        }

        return $fields;
    }
}
