<x-mail.bilingual :subject-line="$subjectLine">
    <x-slot:en>
        <p>{{ __('This is a test email from the rental system. If you can read it, your email settings work.') }}</p>
    </x-slot:en>
    <x-slot:ar>
        <p>هذه رسالة اختبار من نظام الإيجارات. إذا وصلتك هذه الرسالة فإعدادات البريد الإلكتروني تعمل بشكل صحيح.</p>
    </x-slot:ar>
</x-mail.bilingual>
