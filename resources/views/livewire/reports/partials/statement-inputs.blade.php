{{-- Period (whole months), basis and the Excel button, shared by both income statements. Needs $months (Y-m => label). --}}
<flux:select wire:model.live="from" :label="__('From')">
    @foreach ($months as $value => $label)
        <option value="{{ $value }}">{{ $label }}</option>
    @endforeach
</flux:select>
<flux:select wire:model.live="to" :label="__('To')">
    @foreach ($months as $value => $label)
        <option value="{{ $value }}">{{ $label }}</option>
    @endforeach
</flux:select>
<flux:select wire:model.live="basis" :label="__('Basis')">
    <option value="collected">{{ __('Collected') }}</option>
    <option value="billed">{{ __('Billed') }}</option>
</flux:select>
<div class="flex items-end"><flux:button wire:click="export">{{ __('Export to Excel') }}</flux:button></div>
