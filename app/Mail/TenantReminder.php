<?php

namespace App\Mail;

use App\Models\AgreementUnit;
use App\Models\Cheque;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\ReminderSetting;
use App\Models\Unit;
use App\Reminders\ReminderItem;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/**
 * Spec 2026-10-17 §4: one email per tenant per run, English then Arabic (RTL). Every figure comes from the records at
 * send time; Blade escapes names and intro lines. A plain HTML view, not markdown (plan ruling 11).
 */
class TenantReminder extends Mailable
{
    /** @param  list<ReminderItem>  $items */
    public function __construct(public Customer $tenant, public array $items) {}

    public function envelope(): Envelope
    {
        $company = CompanySetting::current();

        return new Envelope(
            from: new Address((string) config('mail.from.address'), $company->name_en),
            replyTo: filled($company->email) ? [new Address((string) $company->email, $company->name_en)] : [],
            subject: $this->subjectLine(),
        );
    }

    /** The most urgent type present (overdue → rent due → cheque → renewal), in English · Arabic, then the company. */
    public function subjectLine(): string
    {
        $present = array_map(fn (ReminderItem $i) => $i->type, $this->items);
        $type = collect(ReminderSetting::TYPES)->first(fn (string $t) => in_array($t, $present, true));
        [$en, $ar] = match ($type) {
            'overdue' => [__('Rent overdue'), 'إيجار متأخر'],
            'rent_due' => [__('Rent due soon'), 'إيجار مستحق قريباً'],
            'cheque' => [__('Cheque date coming up'), 'اقتراب موعد الشيك'],
            default => [__('Agreement ending soon'), 'اقتراب انتهاء العقد'],
        };

        return implode(' · ', [$en, $ar, CompanySetting::current()->name_en]);
    }

    public function content(): Content
    {
        $company = CompanySetting::current();
        $settings = ReminderSetting::query()->get()->keyBy('type');
        $today = CarbonImmutable::today('Asia/Bahrain');
        $sections = [];
        foreach (ReminderSetting::TYPES as $type) {
            $items = array_values(array_filter($this->items, fn (ReminderItem $i) => $i->type === $type));
            if ($items !== []) {
                $setting = $settings->get($type);
                $sections[] = ['intro_en' => $setting->intro_en ?? '', 'intro_ar' => $setting->intro_ar ?? '',
                    'lines' => array_map(fn (ReminderItem $i) => self::line($i, $today), $items)];
            }
        }

        return new Content(view: 'mail.tenant-reminder', with: [
            'subjectLine' => $this->subjectLine(),
            'nameEn' => $this->tenant->name_en,
            'nameAr' => $this->tenant->displayName('ar'),
            'sections' => $sections,
            'company' => ['name_en' => $company->name_en, 'name_ar' => $company->name_ar, 'phone' => $company->phone, 'email' => $company->email,
                'address_en' => $company->address_en, 'address_ar' => $company->address_ar],
        ]);
    }

    /** @return array{en: string, ar: string} one item's line, built from the record */
    private static function line(ReminderItem $item, CarbonImmutable $today): array
    {
        $r = $item->record;
        if ($r instanceof Invoice) {
            $units = self::units($r->lines->map(fn (InvoiceLine $line) => $line->unit));
            $balance = Fils::grouped(Fils::fromDecimal($r->balance));
            $due = $r->due_date->format('d/m/Y');
            $days = $item->type === 'overdue' && $r->grace_until !== null ? (int) $r->grace_until->diffInDays($today) : null; // plan ruling 1

            return [
                'en' => implode(', ', array_filter([__('Invoice :number', ['number' => $r->label()]), $units, __('due :date', ['date' => $due]),
                    __('balance BHD :amount', ['amount' => $balance]), $days === null ? null : trans_choice(':count day overdue|:count days overdue', $days)])),
                'ar' => implode('، ', array_filter(['فاتورة '.$r->label(), $units, 'تاريخ الاستحقاق '.$due, 'الرصيد '.$balance.' د.ب',
                    $days === null ? null : 'أيام التأخير '.$days])),
            ];
        }
        if ($r instanceof Cheque) {
            $amount = Fils::grouped(Fils::fromDecimal($r->amount));
            $date = $r->cheque_date->format('d/m/Y');

            return [
                'en' => __('Cheque :number, :bank, dated :date, BHD :amount: please ensure funds are available.',
                    ['number' => $r->cheque_no, 'bank' => $r->bank_name, 'date' => $date, 'amount' => $amount]),
                'ar' => 'شيك رقم '.$r->cheque_no.'، '.$r->bank_name.'، بتاريخ '.$date.'، المبلغ '.$amount.' د.ب: يرجى التأكد من توفر الرصيد.',
            ];
        }
        $units = self::units($r->agreementUnits->map(fn (AgreementUnit $au) => $au->unit));
        $end = $r->end_date->format('d/m/Y');

        return [
            'en' => implode(', ', array_filter([__('Agreement :number', ['number' => $r->label()]), $units, __('ends :date', ['date' => $end])]))
                .': '.__('please contact us to renew.'),
            'ar' => implode('، ', array_filter(['العقد '.$r->label(), $units, 'ينتهي في '.$end])).': يرجى التواصل معنا للتجديد.',
        ];
    }

    /**
     * "Seef Tower 101 / Seef Tower 102"; '' when there are none (a manual invoice without units).
     *
     * @param  Collection<int, covariant Unit|null>  $units
     */
    private static function units(Collection $units): string
    {
        return $units->filter()->map(fn (Unit $u) => $u->building->name.' '.$u->code)->unique()->implode(' / ');
    }
}
