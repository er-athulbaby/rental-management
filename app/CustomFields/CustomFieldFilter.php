<?php

namespace App\CustomFields;

use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Spec 2026-10-21 custom fields §5 "More filters": one custom field condition on a list. It only adds a where on the
 * list's own, already scoped query, so it can narrow but never widen building scope; a bad filter is ignored (plan ruling 6).
 */
final class CustomFieldFilter
{
    /** The conditions each type offers, keyed by CustomFieldType value. */
    public const array CONDITIONS = [
        'text' => ['contains', 'empty'],
        'long_text' => ['contains', 'empty'],
        'number' => ['range', 'empty'],
        'date' => ['range', 'empty'],
        'bool' => ['yes', 'no', 'empty'],
        'choice' => ['in', 'empty'],
        'file' => ['empty'],
    ];

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  mixed  $filter  {field, op, value, from, to, choices} from the browser, untrusted
     * @return Builder<TModel>
     */
    public static function apply(Builder $query, CustomFieldEntity $entity, mixed $filter): Builder
    {
        if (! is_array($filter) || ! is_string($filter['field'] ?? null) || ! is_string($filter['op'] ?? null)) {
            return $query;
        }
        $field = CustomFields::active($entity)->firstWhere('key', $filter['field']);
        if ($field === null || ! in_array($filter['op'], self::CONDITIONS[$field->type->value], true)) {
            return $query;
        }

        $column = $field->type->column();
        $key = $query->getModel()->getQualifiedKeyName();
        $filled = CustomFieldValue::query()->select('entity_id')->where('custom_field_id', $field->id)->whereNotNull($column);

        if ($filter['op'] === 'empty') {
            return $query->whereNotIn($key, $filled);
        }

        $matching = match ($filter['op']) {
            'contains' => self::contains($filled, $column, $filter['value'] ?? null),
            'range' => self::range($filled, $field->type, $filter['from'] ?? null, $filter['to'] ?? null),
            'yes', 'no' => $filled->where($column, $filter['op'] === 'yes'),
            'in' => self::choices($filled, $field, $filter['choices'] ?? null),
        };

        return $matching === null ? $query : $query->whereIn($key, $matching);
    }

    /**
     * @param  Builder<CustomFieldValue>  $values
     * @return Builder<CustomFieldValue>|null
     */
    private static function contains(Builder $values, string $column, mixed $text): ?Builder
    {
        return is_string($text) && trim($text) !== '' && mb_strlen($text) <= 100
            ? $values->where($column, 'like', '%'.addcslashes(trim($text), '%_\\').'%')
            : null;
    }

    /**
     * Either end may be left out; an invalid end is ignored, and the filter too when neither end is valid.
     *
     * @param  Builder<CustomFieldValue>  $values
     * @return Builder<CustomFieldValue>|null
     */
    private static function range(Builder $values, CustomFieldType $type, mixed $from, mixed $to): ?Builder
    {
        $ok = fn (mixed $v): bool => is_string($v) && ($type === CustomFieldType::Number
            ? preg_match(CustomFields::NUMBER_PATTERN, $v) === 1
            : preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]));
        if (! $ok($from) && ! $ok($to)) {
            return null;
        }

        // Numbers compare as DECIMAL, never as a double (plan ruling 1).
        $number = $type === CustomFieldType::Number;
        if ($ok($from)) {
            $number ? $values->whereRaw('value_number >= CAST(? AS DECIMAL(15,3))', [$from]) : $values->whereRaw('value_date >= ?', [$from]);
        }
        if ($ok($to)) {
            $number ? $values->whereRaw('value_number <= CAST(? AS DECIMAL(15,3))', [$to]) : $values->whereRaw('value_date <= ?', [$to]);
        }

        return $values;
    }

    /**
     * Any of the field's choices, hidden ones included: records keep them (plan ruling 11).
     *
     * @param  Builder<CustomFieldValue>  $values
     * @return Builder<CustomFieldValue>|null
     */
    private static function choices(Builder $values, CustomField $field, mixed $picked): ?Builder
    {
        $ids = is_array($picked) ? array_values(array_intersect($field->choices->modelKeys(),
            array_map(intval(...), array_filter($picked, fn (mixed $c) => is_int($c) || (is_string($c) && ctype_digit($c)))))) : [];

        return $ids === [] ? null : $values->whereIn('choice_id', $ids);
    }
}
