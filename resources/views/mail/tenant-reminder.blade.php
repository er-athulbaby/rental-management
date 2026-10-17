<x-mail.bilingual :subject-line="$subjectLine">
    <x-slot:en>
        <p>{{ __('Dear :name,', ['name' => $nameEn]) }}</p>
        @foreach ($sections as $section)
            <p>{{ $section['intro_en'] }}</p>
            <ul>
                @foreach ($section['lines'] as $line)
                    <li>{{ $line['en'] }}</li>
                @endforeach
            </ul>
        @endforeach
        <p>{{ __('Thank you.') }}</p>
    </x-slot:en>
    <x-slot:ar>
        <p>عزيزي المستأجر {{ $nameAr }}،</p>
        @foreach ($sections as $section)
            <p>{{ $section['intro_ar'] }}</p>
            <ul>
                @foreach ($section['lines'] as $line)
                    <li>{{ $line['ar'] }}</li>
                @endforeach
            </ul>
        @endforeach
        <p>شكراً لكم.</p>
    </x-slot:ar>
</x-mail.bilingual>
