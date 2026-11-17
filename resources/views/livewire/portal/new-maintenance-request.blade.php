<section class="space-y-6">
    <x-ui.page-header :title="__('New maintenance request')" :back="route('portal.maintenance')" :back-label="__('Maintenance')" />

    @if ($places === [])
        <x-ui.section>
            <x-ui.empty :title="__('You have no current agreement, so requests cannot be sent here. Please call the office.')" icon="home" />
        </x-ui.section>
    @else
        <form wire:submit="save" class="space-y-6">
            <x-ui.section :title="__('Problem')" :hint="__('Say where the problem is and what is wrong.')">
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
            </x-ui.section>

            <x-ui.section :title="__('Photos')" :hint="__('A photo helps the vendor see the problem.')">
                <flux:field>
                    <flux:label>{{ __('Photos (up to 5; JPG, PNG or WEBP; 10 MB each)') }}</flux:label>
                    <input type="file" wire:model="photos" multiple accept="image/jpeg,image/png,image/webp" class="block w-full rounded-lg border border-dashed border-zinc-300 p-3 text-sm file:me-3 file:min-h-11 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:text-sm file:font-medium dark:border-zinc-600 dark:file:bg-zinc-800" />
                    <span wire:loading wire:target="photos" class="text-sm text-zinc-500">{{ __('Uploading…') }}</span>
                    <flux:error name="photos" />
                    @foreach (range(0, 4) as $i)
                        <flux:error name="photos.{{ $i }}" />
                    @endforeach
                </flux:field>
            </x-ui.section>
            <flux:error name="request" />
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <flux:button :href="route('portal.maintenance')">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" class="min-h-11 sm:min-w-40">{{ __('Send request') }}</flux:button>
            </div>
        </form>
    @endif
</section>
