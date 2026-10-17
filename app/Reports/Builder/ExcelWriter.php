<?php

namespace App\Reports\Builder;

use Spatie\SimpleExcel\SimpleExcelWriter;

/** The builder's Excel file: one header row, raw values, then the grand total (spec §5). */
final class ExcelWriter
{
    /** Streams the rows to the file, so a large report is not held twice in memory (final review minor 4). */
    public static function write(ReportResult $result, string $path): void
    {
        $writer = SimpleExcelWriter::create($path);
        $writer->addHeader(self::columns($result));
        foreach (self::rows($result) as $row) {
            $writer->addRow($row);
        }
        $writer->close();
    }

    /**
     * The file's header and rows; also one sheet of a report package (spec 2026-10-17 §4).
     *
     * @return array{columns: list<string>, rows: list<array<array-key, mixed>>}
     */
    public static function sheet(ReportResult $result): array
    {
        return ['columns' => self::columns($result), 'rows' => iterator_to_array(self::rows($result), false)];
    }

    /** @return list<string> */
    private static function columns(ReportResult $result): array
    {
        return array_map(fn (Field $f) => $f->label, $result->columns);
    }

    /** @return \Generator<int, array<array-key, mixed>> */
    private static function rows(ReportResult $result): \Generator
    {
        if ($result->groups === []) {
            foreach ($result->rows as $row) {
                yield self::line($result->columns, $row);
            }
        } else {
            $deepest = collect($result->groups)->max('level');
            $byGroup = Grouping::rowsByGroup($result->rows);
            foreach ($result->groups as $g) {
                yield self::subtotal($result->columns, $g);
                if ($g['level'] === $deepest) {
                    foreach ($byGroup[Grouping::keyId($g['keys'])] ?? [] as $row) {
                        yield self::line($result->columns, $row);
                    }
                }
            }
        }
        $total = [];
        foreach ($result->columns as $i => $col) {
            $total[$col->label] = $i === 0 ? __('Total (:n rows)', ['n' => $result->rowCount]) : '';
            foreach ($result->grandTotals as $key => $value) {
                if (str_starts_with($key, $col->key.':')) {
                    $total[$col->label] = is_numeric($value) ? (float) $value : $value;
                }
            }
        }
        yield $total;
        if ($result->comparison !== null) {
            yield from self::comparison($result);
        }
    }

    /**
     * A blank row, then Compared | per total: this period, comparison period, change, change %. Plain lists, so the main header does not apply.
     *
     * @return list<list<mixed>>
     */
    private static function comparison(ReportResult $result): array
    {
        $cmp = $result->comparison ?? [];
        $labels = collect($result->columns)->mapWithKeys(fn (Field $f) => [$f->key => $f->label]);
        $keys = array_map(strval(...), array_keys($cmp['rows'][0]['current'] ?? ['count' => 0]));
        $rows = [[], [__('Comparison')]];
        $head = [__('Compared')];
        foreach ($keys as $k) {
            $name = $k === 'count' ? __('Rows') : ($labels[explode(':', $k)[0]] ?? $k).' ('.__(explode(':', $k)[1] ?? '').')';
            array_push($head, "{$name}: {$cmp['label']}", "{$name}: {$cmp['compareLabel']}", "{$name}: ".__('Change'), "{$name}: ".__('Change %'));
        }
        $rows[] = $head;
        foreach ($cmp['rows'] as $r) {
            $line = [$r['label']];
            foreach ($keys as $k) {
                $ch = Comparison::change($r['current'][$k] ?? null, $r['previous'][$k] ?? null);
                $num = fn (mixed $v) => is_numeric($v) ? (float) $v : '';
                array_push($line, $num($r['current'][$k] ?? null), $num($r['previous'][$k] ?? null), $num($ch['diff']), $num($ch['pct']));
            }
            $rows[] = $line;
        }

        return $rows;
    }

    /**
     * @param  list<Field>  $columns
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    private static function subtotal(array $columns, array $group): array
    {
        $line = [];
        foreach ($columns as $i => $col) {
            $line[$col->label] = $i === 0 ? ($group['level'] === 2 ? '  ' : '').__('Subtotal: :label (:n)', ['label' => $group['label'], 'n' => $group['totals']['count']]) : '';
            foreach ($group['totals'] as $key => $value) {
                if (str_starts_with($key, $col->key.':')) {
                    $line[$col->label] = is_numeric($value) ? (float) $value : (string) $value;
                }
            }
        }

        return $line;
    }

    /**
     * @param  list<Field>  $columns
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function line(array $columns, array $row): array
    {
        $line = [];
        foreach ($columns as $col) {
            $raw = $row[$col->key] ?? null;
            $line[$col->label] = match (true) {
                $raw === null => '',
                $col->type->numeric() => (float) $raw,
                $col->type === FieldType::Enum, $col->type === FieldType::Date, $col->type === FieldType::Boolean => Formatter::display($col, $raw),
                default => (string) $raw,
            };
        }

        return $line;
    }
}
