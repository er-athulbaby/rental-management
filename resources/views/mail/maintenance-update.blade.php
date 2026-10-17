<x-mail.bilingual :subject-line="$subjectLine">
    <x-slot:en>
        <p>{{ __('Dear :name,', ['name' => $nameEn]) }}</p>
        @if ($completed)
            <p>{{ __('Your maintenance request :number for :place was completed on :date.', ['number' => $number, 'place' => $place, 'date' => $date]) }}</p>
            <p>{{ __('If the problem is not fixed, please contact us.') }}</p>
        @else
            <p>{{ __('We have received your maintenance request :number for :place on :date.', ['number' => $number, 'place' => $place, 'date' => $date]) }}</p>
            <p style="white-space: pre-line;">{{ $description }}</p>
            <p>{{ __('We will contact you to arrange the visit.') }}</p>
        @endif
        <p>{{ __('Thank you.') }}</p>
    </x-slot:en>
    <x-slot:ar>
        <p>عزيزي المستأجر {{ $nameAr }}،</p>
        @if ($completed)
            <p>تم إنجاز طلب الصيانة رقم {{ $number }} الخاص بـ {{ $place }} بتاريخ {{ $date }}.</p>
            <p>إذا لم تُحل المشكلة، يرجى التواصل معنا.</p>
        @else
            <p>تم استلام طلب الصيانة رقم {{ $number }} الخاص بـ {{ $place }} بتاريخ {{ $date }}.</p>
            <p style="white-space: pre-line;">{{ $description }}</p>
            <p>سنتواصل معكم لتحديد موعد الزيارة.</p>
        @endif
        <p>شكراً لكم.</p>
    </x-slot:ar>
</x-mail.bilingual>
