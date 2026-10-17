<?php

namespace App\Reports\Packages;

use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * Spec 2026-10-17 §4: one sheet per item in package order. Each has a title row, a period row (plus the dropped-field
 * note, plan ruling 17), a blank row, then the report's own header and rows. A skipped item has its title and reason.
 */
final class PackageWorkbook
{
    public static function write(PackageResult $result, string $path): void
    {
        $writer = SimpleExcelWriter::create($path)->noHeaderRow();
        $used = [];
        foreach ($result->sheets as $i => $sheet) {
            $name = self::sheetName($sheet->title, $used);
            $used[] = mb_strtolower($name);
            if ($i === 0) {
                $writer->nameCurrentSheet($name);
            } else {
                $writer->addNewSheetAndMakeItCurrent($name);
            }
            $writer->addRow([$sheet->title]);
            if ($sheet->skipped !== null) {
                $writer->addRow([$sheet->skipped]);

                continue;
            }
            $writer->addRow([$sheet->periodLabel]);
            if ($sheet->note !== null) {
                $writer->addRow([$sheet->note]);
            }
            $writer->addRow([]);
            $writer->addRow($sheet->columns);
            foreach ($sheet->rows as $row) {
                $writer->addRow($row);
            }
        }
        $writer->close();
    }

    /**
     * Excel's rules: no \ / ? * : [ ], no leading or trailing apostrophe, at most 31 characters, unique ignoring case.
     *
     * @param  list<string>  $used  lower-cased names already taken
     */
    public static function sheetName(string $title, array $used): string
    {
        $base = trim(str_replace(['\\', '/', '?', '*', ':', '[', ']'], '', $title), " '");
        $base = $base === '' ? __('Report') : $base;
        $name = rtrim(mb_substr($base, 0, 31), " '");
        for ($n = 2; in_array(mb_strtolower($name), $used, true); $n++) {
            $suffix = " ({$n})";
            $name = rtrim(mb_substr($base, 0, 31 - mb_strlen($suffix)), " '").$suffix;
        }

        return $name;
    }
}
