<?php

namespace App\Mail;

use App\Models\CompanySetting;
use App\Models\PortalAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Spec 2026-10-18 tenant portal §3 and 2026-10-21 owner portal §3: the invite, English then Arabic, for a tenant or an owner. */
class PortalInvitation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 1;

    /** @param  'tenant'|'owner'  $kind  the side the invite was sent from */
    public function __construct(public PortalAccount $account, public string $kind = 'tenant')
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
        $parts = $this->kind === 'owner' ? [__('Your owner portal'), 'بوابة المالك'] : [__('Your tenant portal'), 'بوابة المستأجر'];

        return str_replace(["\r", "\n"], ' ', implode(' · ', [...$parts, CompanySetting::current()->name_en]));
    }

    public function content(): Content
    {
        $person = $this->kind === 'owner' ? $this->account->owner : $this->account->customer;

        return new Content(view: 'mail.portal-invitation', with: [
            'subjectLine' => $this->subjectLine(),
            'kind' => $this->kind,
            'nameEn' => (string) $person?->name_en,
            'nameAr' => (string) $person?->displayName('ar'),
            'url' => route('portal.login'),
        ]);
    }
}
