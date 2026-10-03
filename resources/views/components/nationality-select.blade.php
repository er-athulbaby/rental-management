@props(['current' => null])
{{-- config/nationalities.php. $current is the record's saved nationality, offered even if it is off the list. --}}
@php($nationalities = config('nationalities'))
<flux:select {{ $attributes }} :label="__('Nationality')">
    <option value="">{{ __('Choose…') }}</option>
    @if (filled($current) && ! in_array($current, $nationalities, true))<option value="{{ $current }}">{{ $current }}</option>@endif
    @foreach ($nationalities as $nationality)<option value="{{ $nationality }}">{{ $nationality }}</option>@endforeach
</flux:select>
