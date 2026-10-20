<x-mail.bilingual :subject-line="$subjectLine">
    <x-slot:en>
        <p>{{ __('Dear :name,', ['name' => $nameEn]) }}</p>
        <p>{{ __('You can now see your agreements, invoices, receipts, cheques and statement, and send maintenance requests, in our tenant portal.') }}</p>
        <p>{{ __('Sign in with this email address. We email you a code each time; there is no password.') }}</p>
        <p><a href="{{ $url }}">{{ $url }}</a></p>
        <p>{{ __('Thank you.') }}</p>
    </x-slot:en>
    <x-slot:ar>
        <p>عزيزي المستأجر {{ $nameAr }}،</p>
        <p>يمكنكم الآن الاطلاع على عقودكم وفواتيركم وإيصالاتكم وشيكاتكم وكشف حسابكم، وإرسال طلبات الصيانة، عبر بوابة المستأجر.</p>
        <p>سجّلوا الدخول باستخدام هذا البريد الإلكتروني. نرسل إليكم رمزاً في كل مرة، ولا حاجة إلى كلمة مرور.</p>
        <p><a href="{{ $url }}">{{ $url }}</a></p>
        <p>شكراً لكم.</p>
    </x-slot:ar>
</x-mail.bilingual>
