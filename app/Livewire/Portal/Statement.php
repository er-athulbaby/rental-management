<?php

namespace App\Livewire\Portal;

use App\Billing\CustomerStatement;
use App\Livewire\Concerns\InPortal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Spec 2026-10-18 tenant portal §4: the existing tenant statement for a date range, on screen and as PDF. */
#[Layout('layouts::portal')]
class Statement extends Component
{
    use InPortal;

    // Untyped: a tampered value shows "Choose a valid date range." instead of a TypeError (a 500).
    #[Url]
    public mixed $from = '';

    #[Url]
    public mixed $to = '';

    public function mount(): void
    {
        $this->from = self::isDate($this->from) ? $this->from : now('Asia/Bahrain')->startOfYear()->toDateString();
        $this->to = self::isDate($this->to) ? $this->to : now('Asia/Bahrain')->toDateString();
    }

    private static function isDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    public function render(): View
    {
        $customer = $this->customer();
        $valid = self::isDate($this->from) && self::isDate($this->to) && $this->from <= $this->to;

        return view('livewire.portal.statement', [
            'valid' => $valid,
            'receivables' => $valid ? CustomerStatement::receivables($customer, $this->from, $this->to) : null,
            'deposits' => $valid ? CustomerStatement::deposits($customer, $this->from, $this->to) : [],
        ])->title(__('Statement'));
    }
}
