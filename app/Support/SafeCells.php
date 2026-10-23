<?php

namespace App\Support;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;

/**
 * Every export row goes through here. OpenSpout's Cell::fromValue() turns any string starting with "=" into a live
 * formula; a staff-typed name or note must stay text. We force a StringCell for those (no visible apostrophe, the
 * value reads back exactly). Ints, floats and every other value keep the cell type fromValue() gave them.
 * There is no CSV export; if one is added, prefix "'" to non-numeric strings starting with = + - @ tab or CR.
 */
final class SafeCells
{
    /** @param  array<array-key, mixed>  $values */
    public static function row(array $values): Row
    {
        return new Row(array_map(
            fn (mixed $v) => is_string($v) && isset($v[0]) && $v[0] === '=' ? new StringCell($v, null) : Cell::fromValue($v),
            array_values($values),
        ));
    }
}
