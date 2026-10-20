@props(['status'])
{{--
    A status badge coloured by what the status means, always with its words (colour is never the only cue).
    Takes a status enum (uses its label()) or a display label such as an invoice's "Overdue" or "Partially paid".
--}}
@php
    $english = $status instanceof \BackedEnum ? (method_exists($status, 'label') ? $status->label() : str($status->value)->headline()->toString()) : (string) $status;
    $key = $status instanceof \BackedEnum ? $status->value : str($english)->snake()->toString();
    $label = __($english); // the portal's Arabic (spec 2026-10-18 tenant portal §4); English is unchanged
    $color = match ($key) {
        'active', 'paid', 'cleared', 'confirmed', 'available', 'finalised', 'approved', 'completed', 'recorded', 'resolved' => 'green',
        'issued', 'occupied', 'deposited', 'unpaid', 'partially_paid', 'in_progress', 'assigned' => 'blue',
        'pending_approval', 'reserved', 'held', 'notice_given', 'draft', 'open' => 'amber',
        'overdue', 'bounced', 'blocked', 'reversed', 'rejected', 'terminated', 'expired' => 'red',
        default => 'zinc', // scheduled, closed, renewed, ended, replaced, returned, cancelled, credit note
    };
@endphp
<flux:badge size="sm" :color="$color" {{ $attributes }}>{{ $label }}</flux:badge>
