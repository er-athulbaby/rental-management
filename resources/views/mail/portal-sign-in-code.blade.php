<x-mail.bilingual :subject-line="$subjectLine">
    <x-slot:en>
        <p>{{ __('Dear :name,', ['name' => $nameEn]) }}</p>
        <p>{{ __('Your sign-in code is') }} <strong style="font-size: 20px; letter-spacing: 4px;">{{ $code }}</strong></p>
        <p>{{ __('It works once, for :minutes minutes. If you did not ask for it, you can ignore this email.', ['minutes' => $minutes]) }}</p>
    </x-slot:en>
    <x-slot:ar>
        <p>عزيزي {{ $nameAr }}،</p>
        <p>رمز الدخول الخاص بكم هو <strong style="font-size: 20px; letter-spacing: 4px;">{{ $code }}</strong></p>
        <p>يمكن استخدام الرمز مرة واحدة خلال {{ $minutes }} دقائق. إذا لم تطلبوا هذا الرمز، يمكنكم تجاهل هذه الرسالة.</p>
    </x-slot:ar>
</x-mail.bilingual>
