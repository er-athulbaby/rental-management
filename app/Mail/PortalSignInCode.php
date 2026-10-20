<?php

namespace App\Mail;

use App\Actions\Portal\SendLoginCode;
use App\Models\CompanySetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Spec 2026-10-18 tenant portal §3: the 6-digit sign-in code, English then Arabic, in the tenant emails' layout (plan ruling 6). */
class PortalSignInCode extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public string $code, public string $nameEn, public string $nameAr)
    {
        $this->locale('en'); // the layout carries both languages; the English half stays English
    }

    public function envelope(): Envelope
    {
        $company = CompanySetting::current();

        return new Envelope(
            from: new Address((string) config('mail.from.address'), $company->name_en),
            replyTo: filled($company->email) ? [new Address((string) $company->email, $company->name_en)] : [],
            subject: $this->subjectLine(),
        );
    }

    public function subjectLine(): string
    {
        return str_replace(["\r", "\n"], ' ', implode(' · ', [__('Your sign-in code'), 'رمز الدخول', CompanySetting::current()->name_en]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.portal-sign-in-code', with: [
            'subjectLine' => $this->subjectLine(),
            'code' => $this->code,
            'nameEn' => $this->nameEn,
            'nameAr' => $this->nameAr,
            'minutes' => SendLoginCode::VALID_MINUTES,
        ]);
    }
}
