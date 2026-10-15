<?php

namespace App\Reports\Builder;

/** What a run produced. Raw values; the view and Excel format them. */
final readonly class ReportResult
{
    /**
     * @param  list<Field>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $grandTotals  keyed "count" and "field:fn"
     * @param  list<array<string, mixed>>  $groups  stage 3
     * @param  array<string, mixed>|null  $comparison  stage 4
     */
    public function __construct(
        public array $columns,
        public array $rows,
        public int $rowCount,
        public bool $truncated,
        public array $grandTotals,
        public array $groups = [],
        public ?string $periodLabel = null,
        public ?array $comparison = null,
    ) {}
}
