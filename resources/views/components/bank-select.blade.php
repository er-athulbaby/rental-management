@props(['current' => null])
{{-- The active banks from Administration → Banks. $current is the record's saved bank, offered even if it is off the list. --}}
@php($banks = \App\Models\Bank::activeNames())
<flux:select {{ $attributes }} :label="__('Bank')">
    <option value="">{{ __('Choose…') }}</option>
    @if (filled($current) && ! in_array($current, $banks, true))<option value="{{ $current }}">{{ $current }}</option>@endif
    @foreach ($banks as $bank)<option value="{{ $bank }}">{{ $bank }}</option>@endforeach
</flux:select>
