@props(['buildings', 'label' => __('Building'), 'model' => 'building'])

<flux:select wire:model.live="{{ $model }}" :label="$label ?: null" {{ $attributes->merge(['class' => 'sm:max-w-56']) }}>
    <option value="">{{ __('All buildings') }}</option>
    @foreach ($buildings as $b)
        <option value="{{ $b->id }}">{{ $b->code }} — {{ $b->name }}</option>
    @endforeach
</flux:select>
