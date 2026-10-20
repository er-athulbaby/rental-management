@php
    $wireModel = $attributes->wire('model');
    $model = $wireModel->value();
    $live = $wireModel->hasModifier('live');
    $pass = $attributes->whereDoesntStartWith('wire:model')->except(['type']);
    $inputAttributes = $pass->except(['class', 'min', 'max']);
@endphp
{{--
    A date field that always reads dd/mm/yyyy, whatever the browser's language (a native date input shows the
    computer's format, e.g. mm/dd/yyyy). Livewire still gets Y-m-d. Type the date, or use the calendar button.
--}}
<div x-data="{
        iso: $wire.$entangle(@js($model), @js($live)),
        text: '',
        show(iso) { const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso ?? ''); return m ? `${m[3]}/${m[2]}/${m[1]}` : (iso ?? ''); },
        commit() {
            const t = this.text.trim();
            const m = /^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/.exec(t);
            this.iso = t === '' ? '' : (m ? `${m[3]}-${m[2].padStart(2, '0')}-${m[1].padStart(2, '0')}` : t); // anything else goes to the server to be refused
            this.text = this.show(this.iso);
        },
    }"
    x-init="text = show(iso); $watch('iso', v => text = show(v))"
    {{ $pass->only('class')->merge(['class' => 'min-w-0']) }}>
    <flux:input x-model="text" x-on:change="commit()" x-on:keydown.enter="commit()" placeholder="dd/mm/yyyy" inputmode="numeric" autocomplete="off"
        :attributes="$inputAttributes">
        <x-slot name="iconTrailing">
            {{-- A real, invisible date input lies over the calendar icon, so a click opens the browser's own picker
                 directly — no showPicker(), which some browsers ignore inside a modal dialog. --}}
            <span class="relative -me-1 inline-flex">
                <flux:button size="sm" variant="subtle" icon="calendar-days" tabindex="-1" aria-hidden="true" />
                <input type="date" x-ref="picker" class="date-picker-overlay absolute inset-0 h-full w-full cursor-pointer opacity-0"
                    :aria-label="@js(__('Choose a date'))" :value="/^\d{4}-\d{2}-\d{2}$/.test(iso ?? '') ? iso : ''"
                    x-on:change="iso = $event.target.value" {{ $pass->only(['min', 'max']) }} />
            </span>
        </x-slot>
    </flux:input>
    <flux:error :name="$model" />
</div>
