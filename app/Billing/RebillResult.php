<?php

namespace App\Billing;

final readonly class RebillResult
{
    /**
     * @param  list<int>  $creditNoteIds
     * @param  list<int>  $chequesToReturn  cheques whose invoice was cancelled with no replacement (spec §6.3)
     */
    public function __construct(
        public int $cancelled,
        public int $replaced,
        public array $creditNoteIds,
        public ?int $manualInvoiceId,
        public array $chequesToReturn,
    ) {}
}
