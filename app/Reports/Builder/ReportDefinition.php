<?php

namespace App\Reports\Builder;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A user's report, validated against its subject's whitelist (spec §8). Built only through fromArray(), so every
 * instance is safe to compile. Stored as toArray() in saved_reports.definition.
 */
final readonly class ReportDefinition
{
    public const int VERSION = 1;

    public const int MAX_COLUMNS = 30;

    public const int MAX_FILTERS = 20;

    public const int MAX_GROUPS = 2;

    public const array BUCKETS = ['day', 'month', 'quarter', 'year'];

    public const array TOTALS = ['sum', 'avg', 'min', 'max'];

    /** @return array<string, list<string>> operators per field type (spec §4.1) */
    public static function operators(): array
    {
        return [
            FieldType::Text->value => ['is', 'is_not', 'contains', 'empty', 'not_empty'],
            FieldType::Enum->value => ['in', 'not_in'],
            FieldType::Money->value => ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'between'],
            FieldType::Number->value => ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'between'],
            FieldType::Date->value => ['on', 'before', 'after', 'between'],
            FieldType::Boolean->value => ['yes', 'no'],
        ];
    }

    /**
     * @param  list<string>  $columns
     * @param  list<array{field: string, op: string, value: mixed}>  $filters
     * @param  list<array{field: string, bucket: ?string}>  $groups
     * @param  array<string, string>  $totals
     * @param  array{field: string, dir: string}  $sort
     * @param  array{type: string, total: string}|null  $chart
     */
    private function __construct(
        public string $subject,
        public array $columns,
        public array $filters,
        public ?string $periodField,
        public ?string $periodPreset,
        public ?string $periodFrom,
        public ?string $periodTo,
        public ?string $asAt,
        public ?int $buildingId,
        public array $groups,
        public array $totals,
        public array $sort,
        public bool $summaryOnly,
        public ?string $compare,
        public ?array $chart,
    ) {}

    public static function fresh(Subject $subject, User $user): self
    {
        $period = $subject->periodFields() === [] ? [] : ['field' => $subject->periodFields()[0], 'preset' => 'this_month'];

        return self::fromArray(['subject' => $subject->key(), 'columns' => $subject->defaultColumns(), 'period' => $period, 'sort' => $subject->defaultSort()], $user);
    }

    /**
     * Spec §8: a shared report may use fields this viewer lacks. Drops every reference to them (columns, filters, groups,
     * totals, sort, chart) so the rest still runs, and says which fields went.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: list<string>} the cleaned data and the dropped field labels
     */
    public static function forViewer(array $data, User $user): array
    {
        $subject = is_string($data['subject'] ?? null) ? (Registry::forUser($user)[$data['subject']] ?? null) : null;
        if ($subject === null) {
            return [$data, []]; // fromArray reports it
        }
        $asAt = self::isDate($data['as_at'] ?? null) ? $data['as_at'] : 'today';
        $hidden = $subject->hiddenFrom($user, CarbonImmutable::parse($asAt, 'Asia/Bahrain'));
        $known = $subject->fields($user, CarbonImmutable::parse($asAt, 'Asia/Bahrain'));
        $dropped = [];
        $gone = function (mixed $key) use ($hidden, $known, &$dropped): bool {
            // Custom fields plan ruling 5: a deleted custom field's key is left out the same way, named by its key.
            $deleted = is_string($key) && preg_match('/^cf[bt]?_/', $key) === 1 && ! isset($known[$key]) && ! isset($hidden[$key]);
            if (is_string($key) && (isset($hidden[$key]) || $deleted)) {
                $dropped[$key] = $hidden[$key] ?? $key;

                return true;
            }

            return false;
        };
        $field = fn (mixed $row) => is_array($row) ? ($row['field'] ?? null) : null;

        $data['columns'] = array_values(array_filter((array) ($data['columns'] ?? []), fn ($c) => ! $gone($c)));
        $data['filters'] = array_values(array_filter((array) ($data['filters'] ?? []), fn ($f) => ! $gone($field($f))));
        $data['groups'] = array_values(array_filter((array) ($data['groups'] ?? []), fn ($g) => ! $gone($field($g))));
        $data['totals'] = array_filter((array) ($data['totals'] ?? []), fn ($fn, $key) => ! $gone($key), ARRAY_FILTER_USE_BOTH);
        if (isset($data['sort']) && $gone($field($data['sort']))) {
            $data['sort'] = $subject->defaultSort();
        }
        $chart = $data['chart'] ?? null;
        if (is_array($chart) && is_string($chart['total'] ?? null) && ($gone(explode(':', $chart['total'])[0]) || $data['groups'] === [])) {
            $data['chart'] = null;
        }

        return [$data, array_values($dropped)];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data, User $user): self
    {
        $subjects = Registry::forUser($user);
        Validator::make($data, ['subject' => ['required', 'string', 'in:'.implode(',', array_keys($subjects))]],
            ['subject.in' => __('You cannot report on this subject.')])->validate();
        $subject = $subjects[$data['subject']];
        $rawAsAt = $data['as_at'] ?? null;
        $asAt = self::isDate($rawAsAt) ? $rawAsAt : null;
        $fields = $subject->fields($user, CarbonImmutable::parse($asAt ?? 'today', 'Asia/Bahrain'));
        $keys = array_keys($fields);
        $numeric = array_keys(array_filter($fields, fn (Field $f) => $f->type->numeric()));
        $groupable = array_keys(array_filter($fields, fn (Field $f) => $f->groupable));

        $errors = [];
        $columns = array_values((array) ($data['columns'] ?? []));
        if ($columns === [] || count($columns) > self::MAX_COLUMNS || array_filter($columns, fn ($c) => ! is_string($c)) !== [] || array_diff($columns, $keys) !== [] || count(array_unique($columns)) !== count($columns)) {
            $errors['columns'] = __('Choose between 1 and :n of the listed columns.', ['n' => self::MAX_COLUMNS]);
        }

        $filters = array_values((array) ($data['filters'] ?? []));
        if (count($filters) > self::MAX_FILTERS) {
            $errors['filters'] = __('At most :n filters.', ['n' => self::MAX_FILTERS]);
        }
        foreach ($filters as $i => $filter) {
            $field = is_array($filter) && is_string($filter['field'] ?? null) ? ($fields[$filter['field']] ?? null) : null;
            if ($field === null || ! $field->filterable) {
                $errors["filters.$i.field"] = __('Choose a field to filter on.');

                continue;
            }
            if (! is_string($filter['op'] ?? null) || ! in_array($filter['op'], self::operators()[$field->type->value], true)) {
                $errors["filters.$i.op"] = __('Choose how to compare.');

                continue;
            }
            if (($message = self::badValue($field, (string) $filter['op'], $filter['value'] ?? null)) !== null) {
                $errors["filters.$i.value"] = $message;
            }
        }

        $period = (array) ($data['period'] ?? []);
        if ($period !== [] && $subject->periodFields() !== []) {
            if (! in_array($period['field'] ?? null, $subject->periodFields(), true)) {
                $errors['period.field'] = __('Choose the date the period uses.');
            }
            if (! is_string($period['preset'] ?? null) || ! array_key_exists($period['preset'], Period::presets())) {
                $errors['period.preset'] = __('Choose a period.');
            } elseif ($period['preset'] === 'custom' && (! self::isDate($period['from'] ?? null) || ! self::isDate($period['to'] ?? null) || $period['from'] > $period['to'])) {
                $errors['period.from'] = __('Enter a valid from and to date.');
            }
        }
        if (filled($rawAsAt) && $asAt === null) {
            $errors['as_at'] = __('Enter a valid date.');
        }

        $groups = array_values((array) ($data['groups'] ?? []));
        if (count($groups) > self::MAX_GROUPS) {
            $errors['groups'] = __('At most :n group levels.', ['n' => self::MAX_GROUPS]);
        }
        foreach ($groups as $i => $group) {
            $field = is_array($group) && is_string($group['field'] ?? null) ? ($fields[$group['field']] ?? null) : null;
            if ($field === null || ! in_array($field->key, $groupable, true)) {
                $errors["groups.$i.field"] = __('Choose a field to group by.');
            } elseif ($field->type === FieldType::Date && ! in_array($group['bucket'] ?? null, self::BUCKETS, true)) {
                $errors["groups.$i.bucket"] = __('Group dates by day, month, quarter or year.');
            }
        }

        $totals = (array) ($data['totals'] ?? []);
        foreach ($totals as $key => $fn) {
            if (! in_array($key, $numeric, true) || ! in_array($fn, self::TOTALS, true)) {
                $errors['totals'] = __('Totals apply to money and number columns: sum, average, minimum or maximum.');
            }
        }

        $sort = (array) ($data['sort'] ?? $subject->defaultSort());
        if (! in_array($sort['field'] ?? null, $keys, true) || ! in_array($sort['dir'] ?? null, ['asc', 'desc'], true)) {
            $errors['sort'] = __('Choose a column to sort by.');
        }

        $compare = $data['compare'] ?? null;
        if ($compare !== null && (! in_array($compare, ['previous', 'last_year'], true) || $subject->periodFields() === [] || $period === [])) {
            $errors['compare'] = __('Comparison needs a report with a period.');
        }

        $chart = $data['chart'] ?? null;
        if ($chart !== null && (! is_array($chart) || ! in_array($chart['type'] ?? null, ['bars', 'columns'], true) || $groups === []
            || ! is_string($chart['total'] ?? null) || ! self::validTotalKey($chart['total'], $totals))) {
            $errors['chart'] = __('A chart needs a grouped report and one of its totals.');
        }

        $building = $data['building_id'] ?? null;
        if ($building === '') {
            $building = null; // an empty select means all buildings
        }
        if ($building !== null && (! is_scalar($building) || ! ctype_digit((string) $building))) {
            $errors['building_id'] = __('Choose a building.');
        }

        $periodOn = $period !== [] && $subject->periodFields() !== [];

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new self(
            subject: $subject->key(),
            columns: $columns,
            filters: array_map(fn (array $f) => ['field' => (string) $f['field'], 'op' => (string) $f['op'], 'value' => $f['value'] ?? null], $filters),
            periodField: $periodOn ? $period['field'] : null,
            periodPreset: $periodOn ? $period['preset'] : null,
            periodFrom: $periodOn && $period['preset'] === 'custom' ? $period['from'] : null,
            periodTo: $periodOn && $period['preset'] === 'custom' ? $period['to'] : null,
            asAt: $asAt,
            buildingId: $building === null ? null : (int) $building,
            groups: array_map(fn (array $g) => ['field' => (string) $g['field'], 'bucket' => ($fields[$g['field']]->type === FieldType::Date) ? $g['bucket'] : null], $groups),
            totals: $totals,
            sort: ['field' => (string) $sort['field'], 'dir' => (string) $sort['dir']],
            summaryOnly: (bool) ($data['summary_only'] ?? false) && $groups !== [],
            compare: $compare,
            chart: $chart === null ? null : ['type' => (string) $chart['type'], 'total' => (string) $chart['total']],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'subject' => $this->subject,
            'columns' => $this->columns,
            'filters' => $this->filters,
            'period' => $this->periodField === null ? [] : array_filter(['field' => $this->periodField, 'preset' => $this->periodPreset,
                'from' => $this->periodFrom, 'to' => $this->periodTo], fn ($v) => $v !== null),
            'as_at' => $this->asAt,
            'building_id' => $this->buildingId,
            'groups' => $this->groups,
            'totals' => $this->totals,
            'sort' => $this->sort,
            'summary_only' => $this->summaryOnly,
            'compare' => $this->compare,
            'chart' => $this->chart,
        ];
    }

    /**
     * "count" or "<field>:<fn>" naming one of the report's totals.
     *
     * @param  array<array-key, mixed>  $totals
     */
    private static function validTotalKey(string $key, array $totals): bool
    {
        if ($key === 'count') {
            return true;
        }
        [$field, $fn] = array_pad(explode(':', $key, 2), 2, '');

        return ($totals[$field] ?? null) === $fn;
    }

    private static function isDate(mixed $v): bool
    {
        return is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function isAmount(mixed $v): bool
    {
        return is_scalar($v) && preg_match('/^-?\d{1,12}(\.\d{1,3})?$/', (string) $v) === 1;
    }

    /** @return array<array-key, mixed>|string|null a validation message, or null when the value is fine */
    private static function badValue(Field $field, string $op, mixed $value): array|string|null
    {
        return match ($field->type) {
            FieldType::Text => in_array($op, ['empty', 'not_empty'], true) || (is_string($value) && $value !== '' && mb_strlen($value) <= 100)
                ? null : __('Enter up to 100 characters.'),
            FieldType::Enum => is_array($value) && $value !== [] && array_filter($value, fn ($v) => ! is_string($v)) === [] && array_diff($value, array_keys($field->options)) === []
                ? null : __('Pick one or more of the listed values.'),
            FieldType::Money, FieldType::Number => $op === 'between'
                ? (is_array($value) && self::isAmount($value[0] ?? null) && self::isAmount($value[1] ?? null) ? null : __('Enter two numbers.'))
                : (self::isAmount($value) ? null : __('Enter a number.')),
            FieldType::Date => $op === 'between'
                ? (is_array($value) && self::isDate($value[0] ?? null) && self::isDate($value[1] ?? null) ? null : __('Enter two dates.'))
                : (self::isDate($value) ? null : __('Enter a date.')),
            FieldType::Boolean => null,
        };
    }
}
