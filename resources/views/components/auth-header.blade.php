@props([
    'title',
    'description',
])

<div class="flex w-full flex-col text-start">
    <flux:heading size="xl" level="1">{{ $title }}</flux:heading>
    <flux:subheading>{{ $description }}</flux:subheading>
</div>
