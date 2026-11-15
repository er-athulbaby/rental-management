@props(['status'])
{{--
    A status badge coloured by what the status means, always with its words (colour is never the only cue).
    Takes a status enum (uses its label()) or a display label such as an invoice's "Overdue" or "Partially paid",
    in English or already translated (the portal's Arabic): a translated display label maps back to its key.
    Design pass §2: green = done or good, red = needs fixing, amber = waiting for someone, blue = information, grey = not live.
--}}
@php
    $english = $status instanceof \BackedEnum ? (method_exists($status, 'label') ? $status->label() : str($status->value)->headline()->toString()) : (string) $status;
    $displayKeys = ['paid' => 'Paid', 'overdue' => 'Overdue', 'partially_paid' => 'Partially paid', 'unpaid' => 'Unpaid', 'credit_note' => 'Credit note'];
    $key = $status instanceof \BackedEnum
        ? $status->value
        : (collect($displayKeys)->search(fn (string $en) => __($en) === $english) ?: str($english)->snake()->toString());
    $label = __($english); // the portal's Arabic (spec 2026-10-18 tenant portal §4); English is unchanged
    $color = match ($key) {
        'active', 'paid', 'cleared', 'confirmed', 'available', 'finalised', 'approved', 'completed', 'recorded', 'resolved', 'finished' => 'green',
        'issued', 'scheduled', 'occupied', 'deposited', 'unpaid', 'partially_paid', 'in_progress', 'assigned' => 'blue',
        'pending_approval', 'pending', 'reserved', 'held', 'notice_given', 'open' => 'amber',
        'overdue', 'bounced', 'blocked', 'reversed', 'rejected', 'terminated', 'expired' => 'red',
        default => 'zinc', // draft, closed, renewed, ended, replaced, returned, cancelled, credit note
    };
@endphp
<flux:badge size="sm" :color="$color" data-tone="{{ $color }}" {{ $attributes }}>{{ $label }}</flux:badge>
