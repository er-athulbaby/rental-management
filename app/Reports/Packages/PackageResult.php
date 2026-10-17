<?php

namespace App\Reports\Packages;

/** A package run for one user: its sheets in order, and the email's lines (spec 2026-10-17 §5). */
final readonly class PackageResult
{
    /** @param  list<PackageSheet>  $sheets */
    public function __construct(public string $name, public array $sheets) {}

    public function allSkipped(): bool
    {
        return array_filter($this->sheets, fn (PackageSheet $s) => $s->skipped === null) === [];
    }

    /** The email subject's period: the first item that ran, else today (plan ruling 10). */
    public function periodLabel(): string
    {
        foreach ($this->sheets as $sheet) {
            if ($sheet->skipped === null) {
                return $sheet->periodLabel;
            }
        }

        return __('As at :date', ['date' => now('Asia/Bahrain')->format('d/m/Y')]);
    }

    /** @return list<string> one line per item that ran: name, real dates, row count, headline total */
    public function lines(): array
    {
        $lines = [];
        foreach ($this->sheets as $s) {
            if ($s->skipped === null) {
                $lines[] = __(':title, :period: :n rows', ['title' => $s->title, 'period' => $s->periodLabel, 'n' => number_format($s->rowCount)])
                    .($s->headline === null ? '' : ' · '.$s->headline);
            }
        }

        return $lines;
    }

    /** @return list<string> "title: reason" for each skipped item */
    public function skippedLines(): array
    {
        $lines = [];
        foreach ($this->sheets as $s) {
            if ($s->skipped !== null) {
                $lines[] = "{$s->title}: {$s->skipped}";
            }
        }

        return $lines;
    }
}
