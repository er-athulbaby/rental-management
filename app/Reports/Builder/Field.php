<?php

namespace App\Reports\Builder;

use App\Enums\PermissionName;
use BackedEnum;

/**
 * One reportable field of a subject (spec §4.1). `sql` is an expression over the subject's base query, written by
 * us; it may embed code-made 'Y-m-d' literals (Sql::date) and never user input.
 */
final readonly class Field
{
    /** @param  array<string, string>  $options  value => label, for enum fields */
    public function __construct(
        public string $key,
        public string $label,
        public string $section,
        public FieldType $type,
        public string $sql,
        public array $options = [],
        public bool $groupable = true,
        public bool $filterable = true,
        public ?PermissionName $permission = null,
    ) {}

    public static function text(string $key, string $label, string $section, string $sql, bool $groupable = true, bool $filterable = true, ?PermissionName $permission = null): self
    {
        return new self($key, $label, $section, FieldType::Text, $sql, [], $groupable, $filterable, $permission);
    }

    /** @param  array<string, string>  $options */
    public static function enum(string $key, string $label, string $section, string $sql, array $options, ?PermissionName $permission = null): self
    {
        return new self($key, $label, $section, FieldType::Enum, $sql, $options, true, true, $permission);
    }

    public static function money(string $key, string $label, string $section, string $sql, ?PermissionName $permission = null): self
    {
        return new self($key, $label, $section, FieldType::Money, $sql, [], false, true, $permission);
    }

    public static function number(string $key, string $label, string $section, string $sql, ?PermissionName $permission = null): self
    {
        return new self($key, $label, $section, FieldType::Number, $sql, [], false, true, $permission);
    }

    public static function date(string $key, string $label, string $section, string $sql): self
    {
        return new self($key, $label, $section, FieldType::Date, $sql);
    }

    public static function boolean(string $key, string $label, string $section, string $sql): self
    {
        return new self($key, $label, $section, FieldType::Boolean, $sql);
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     * @return array<string, string>
     */
    public static function optionsFrom(string $enum): array
    {
        $options = [];
        foreach ($enum::cases() as $case) {
            $options[(string) $case->value] = method_exists($case, 'label') ? $case->label() : str((string) $case->value)->headline()->toString();
        }

        return $options;
    }
}
