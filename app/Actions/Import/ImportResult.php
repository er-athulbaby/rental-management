<?php

namespace App\Actions\Import;

final readonly class ImportResult
{
    /**
     * @param  array<string, array<int, list<string>>>  $errors  kind => spreadsheet line => messages
     * @param  array<string, int>  $counts  rows that passed, per kind
     * @param  array<string, string>  $totals  kind => BHD total of the passed rows' money column
     */
    public function __construct(public array $errors, public array $counts, public bool $committed, public array $totals = []) {}
}
