<?php

namespace App\Reports\Builder;

enum FieldType: string
{
    case Text = 'text';
    case Enum = 'enum';
    case Money = 'money';
    case Number = 'number';
    case Date = 'date';
    case Boolean = 'boolean';

    /** Money and numbers take sum / average / min / max (spec §4.1). */
    public function numeric(): bool
    {
        return $this === self::Money || $this === self::Number;
    }
}
