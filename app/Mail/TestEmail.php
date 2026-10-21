<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The short English and Arabic message the email settings page sends. Not queued: the page shows the result at once. */
class TestEmail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine());
    }

    public function content(): Content
    {
        return new Content(view: 'mail.mail-test', with: ['subjectLine' => $this->subjectLine()]);
    }

    private function subjectLine(): string
    {
        return __('Test email').' · رسالة اختبار';
    }
}
