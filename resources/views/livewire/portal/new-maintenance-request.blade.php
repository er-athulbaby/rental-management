<section class="space-y-4">
    <flux:heading size="xl" level="1">{{ __('New maintenance request') }}</flux:heading>

    @if ($places === [])
        <flux:text>{{ __('You have no current agreement, so requests cannot be sent here. Please call the office.') }}</flux:text>
    @else
        <form wire:submit="save" class="space-y-4">
            <flux:select wire:model="form.place" :label="__('Where is the problem?')">
                <option value="">{{ __('Choose…') }}</option>
                @foreach ($places as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model="form.category" :label="__('Category')">
                @foreach ($categories as $c)
                    <option value="{{ $c->value }}">{{ $c->label() }}</option>
                @endforeach
            </flux:select>
            <flux:textarea wire:model="form.description" :label="__('What is the problem?')" rows="4" maxlength="2000" />
            <flux:switch wire:model="form.entry_permitted" :label="__('The vendor may enter when I am out')" />
            <flux:field>
                <flux:label>{{ __('Photos (up to 5; JPG, PNG or WEBP; 10 MB each)') }}</flux:label>
                <input type="file" wire:model="photos" multiple accept="image/jpeg,image/png,image/webp" class="block w-full text-sm" />
                <span wire:loading wire:target="photos" class="text-sm text-zinc-500">{{ __('Uploading…') }}</span>
                <flux:error name="photos" />
                @foreach (range(0, 4) as $i)
                    <flux:error name="photos.{{ $i }}" />
                @endforeach
            </flux:field>
            <flux:error name="request" />
            <flux:button type="submit" variant="primary" class="w-full sm:w-auto">{{ __('Send request') }}</flux:button>
        </form>
    @endif
</section>
