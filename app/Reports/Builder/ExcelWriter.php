<?php

namespace App\Reports\Builder;

use Spatie\SimpleExcel\SimpleExcelWriter;

/** The builder's Excel file: one header row, raw values, then the grand total (spec §5). */
final class ExcelWriter
{
    public static function write(ReportResult $result, string $path): void
    {
        $writer = SimpleExcelWriter::create($path);
        $labels = array_map(fn (Field $f) => $f->label, $result->columns);
        $writer->addHeader($labels);
        if ($result->groups === []) {
            foreach ($result->rows as $row) {
                $writer->addRow(self::line($result->columns, $row));
            }
        } else {
            $deepest = collect($result->groups)->max('level');
            $byGroup = Grouping::rowsByGroup($result->rows);
            foreach ($result->groups as $g) {
                $writer->addRow(self::subtotal($result->columns, $g));
                if ($g['level'] === $deepest) {
                    foreach ($byGroup[Grouping::keyId($g['keys'])] ?? [] as $row) {
                        $writer->addRow(self::line($result->columns, $row));
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
        $writer->addRow($total);
        if ($result->comparison !== null) {
            self::comparison($writer, $result);
        }
        $writer->close();
    }

    /** A blank row, then Compared | per total: this period, comparison period, change, change %. Plain lists, so the main header does not apply. */
    private static function comparison(SimpleExcelWriter $writer, ReportResult $result): void
    {
        $cmp = $result->comparison ?? [];
        $labels = collect($result->columns)->mapWithKeys(fn (Field $f) => [$f->key => $f->label]);
        $keys = array_map(strval(...), array_keys($cmp['rows'][0]['current'] ?? ['count' => 0]));
        $writer->addRow([]);
        $writer->addRow([__('Comparison')]);
        $head = [__('Compared')];
        foreach ($keys as $k) {
            $name = $k === 'count' ? __('Rows') : ($labels[explode(':', $k)[0]] ?? $k).' ('.__(explode(':', $k)[1] ?? '').')';
            array_push($head, "{$name}: {$cmp['label']}", "{$name}: {$cmp['compareLabel']}", "{$name}: ".__('Change'), "{$name}: ".__('Change %'));
        }
        $writer->addRow($head);
        foreach ($cmp['rows'] as $r) {
            $line = [$r['label']];
            foreach ($keys as $k) {
                $ch = Comparison::change($r['current'][$k] ?? 0, $r['previous'][$k] ?? 0);
                array_push($line, (float) ($r['current'][$k] ?? 0), (float) ($r['previous'][$k] ?? 0), (float) $ch['diff'], $ch['pct'] === null ? '' : (float) $ch['pct']);
            }
            $writer->addRow($line);
        }
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
