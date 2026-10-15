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
        foreach ($result->rows as $row) {
            $writer->addRow(self::line($result->columns, $row));
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
        $writer->close();
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
