@props(['status'])
{{--
    A status badge coloured by what the status means, always with its words (colour is never the only cue).
    Takes a status enum (uses its label()) or a display label such as an invoice's "Overdue" or "Partially paid".
--}}
@php
    $label = $status instanceof \BackedEnum ? (method_exists($status, 'label') ? $status->label() : str($status->value)->headline()->toString()) : (string) $status;
    $key = $status instanceof \BackedEnum ? $status->value : str($label)->snake()->toString();
    $color = match ($key) {
        'active', 'paid', 'cleared', 'confirmed', 'available', 'finalised', 'approved', 'completed', 'recorded' => 'green',
        'issued', 'occupied', 'deposited', 'unpaid', 'partially_paid' => 'blue',
        'pending_approval', 'reserved', 'held', 'notice_given', 'draft' => 'amber',
        'overdue', 'bounced', 'blocked', 'reversed', 'rejected', 'terminated', 'expired' => 'red',
        default => 'zinc', // scheduled, closed, renewed, ended, replaced, returned, cancelled, credit note
    };
@endphp
<flux:badge size="sm" :color="$color" {{ $attributes }}>{{ $label }}</flux:badge>
