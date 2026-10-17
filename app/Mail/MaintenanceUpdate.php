<?php

namespace App\Mail;

use App\Models\CompanySetting;
use App\Models\MaintenanceRequest;
use App\Reminders\EmailChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Spec 2026-10-17 maintenance §4 steps 1 and 5: "request received" and "request completed", English then Arabic, in the
 * tenant reminders' layout and under their rules (plan ruling 9). No amounts.
 */
class MaintenanceUpdate extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public const string RECEIVED = 'received';

    public const string COMPLETED = 'completed';

    public int $tries = 1;

    public function __construct(public MaintenanceRequest $request, public string $kind) {}

    /**
     * Queues the email, sent once the transaction commits, when the tenant can get it: an email on the tenant record,
     * not opted out of reminders, and mail set up. True when it was queued. A failing queue never blocks the action.
     */
    public static function sendFor(MaintenanceRequest $request, string $kind): bool
    {
        $tenant = $request->customer;
        if ($tenant === null || blank($tenant->email) || $tenant->no_reminders || ! (new EmailChannel)->ready()) {
            return false;
        }
        try {
            Mail::to(new Address((string) $tenant->email, $tenant->name_en))->queue((new self($request, $kind))->afterCommit());
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return true;
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
        [$en, $ar] = $this->kind === self::COMPLETED
            ? [__('Maintenance request completed'), 'تم إنجاز طلب الصيانة']
            : [__('Maintenance request received'), 'تم استلام طلب الصيانة'];

        return str_replace(["\r", "\n"], ' ', implode(' · ', [$en, $ar, CompanySetting::current()->name_en]));
    }

    public function content(): Content
    {
        $r = $this->request;
        $date = $this->kind === self::COMPLETED ? ($r->resolved_at ?? now()) : $r->created_at;

        return new Content(view: 'mail.maintenance-update', with: [
            'subjectLine' => $this->subjectLine(),
            'completed' => $this->kind === self::COMPLETED,
            'nameEn' => $r->customer?->name_en,
            'nameAr' => $r->customer?->displayName('ar') ?? '',
            'number' => $r->number,
            'place' => $r->place(),
            'description' => $r->description,
            'date' => $date->timezone('Asia/Bahrain')->format('d/m/Y'),
        ]);
    }
}
