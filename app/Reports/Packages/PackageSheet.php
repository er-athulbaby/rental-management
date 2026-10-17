<?php

namespace App\Reports\Packages;

/** One item's sheet in a package (spec 2026-10-17 §4). A skipped item carries only its title and the reason. */
final readonly class PackageSheet
{
    /**
     * @param  list<string>  $columns
     * @param  list<array<array-key, mixed>>  $rows
     */
    public function __construct(
        public string $title,
        public string $periodLabel,
        public array $columns = [],
        public array $rows = [],
        public int $rowCount = 0,
        public ?string $headline = null,
        public ?string $skipped = null,
        public ?string $note = null,
    ) {}

    public static function skip(string $title, string $reason): self
    {
        return new self($title, '', skipped: $reason);
    }
}
