<?php

use App\Actions\EnsureNumberSequences;
use App\Mail\TenantReminder;
use App\Models\Building;
use App\Models\Cheque;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\ReminderSetting;
use App\Models\Unit;
use App\Reminders\Channel;
use App\Reminders\EmailChannel;
use App\Reminders\ReminderItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    CompanySetting::factory()->create(['phone' => '+973 1700 0000', 'address_en' => 'Building 1, Road 2, Seef', 'address_ar' => 'مبنى 1، طريق 2، السيف']);
    config(['mail.from.address' => 'no-reply@rms.test']);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 08:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->tenant = Customer::factory()->create(['name_en' => 'Ali <b>& Sons</b>', 'name_ar' => 'علي وأولاده', 'email' => 'ali@tenant.test']);
    $unit = Unit::factory()->for(Building::factory()->create(['name' => 'Seef Tower']))->create(['code' => '101']);
    $this->agreement = activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2027-01-09', 'grace_days' => 5], [$unit]);
    // Due 04/11 with 5 grace days (grace until 09/11): 1 day overdue today.
    $this->invoice = issuedInvoice($this->tenant, [['net' => '1250.500', 'au' => $this->agreement->agreementUnits->first()]], '2026-11-04', $this->agreement);
    $this->cheque = (new Cheque)->forceFill(['cheque_no' => '501', 'bank_name' => 'NBB', 'cheque_date' => '2026-11-12', 'amount' => '400.000']);
    $this->item = fn (string $type) => match ($type) {
        'overdue' => new ReminderItem('overdue', $this->invoice, 1),
        'rent_due' => new ReminderItem('rent_due', $this->invoice, 3),
        'cheque' => new ReminderItem('cheque', $this->cheque, 2),
        default => new ReminderItem('renewal', $this->agreement, 60),
    };
    $this->mail = fn (string ...$types) => new TenantReminder($this->tenant, array_map($this->item, $types));
});

test('the subject names the most urgent type in English and Arabic, then the company', function (array $types, string $subject) {
    expect(($this->mail)(...$types)->subjectLine())->toBe($subject.' · Demo Properties W.L.L.');
})->with([
    'overdue beats everything' => [['renewal', 'cheque', 'rent_due', 'overdue'], 'Rent overdue · إيجار متأخر'],
    'rent due beats cheque and renewal' => [['renewal', 'cheque', 'rent_due'], 'Rent due soon · إيجار مستحق قريباً'],
    'cheque beats renewal' => [['renewal', 'cheque'], 'Cheque date coming up · اقتراب موعد الشيك'],
    'renewal alone' => [['renewal'], 'Agreement ending soon · اقتراب انتهاء العقد'],
]);

test('from the company name at MAIL_FROM_ADDRESS, replying to the company email', function () {
    ($this->mail)('overdue')->assertFrom('no-reply@rms.test', 'Demo Properties W.L.L.')->assertHasReplyTo('office@demo.test')
        ->assertHasSubject('Rent overdue · إيجار متأخر · Demo Properties W.L.L.');
});

test('English then Arabic: each type with its intro line and the figures from the records, dates dd/mm/yyyy', function () {
    $intro = ReminderSetting::query()->get()->keyBy('type');
    $n = $this->invoice->number;
    $ag = $this->agreement->number;

    ($this->mail)('renewal', 'cheque', 'overdue')->assertSeeInOrderInHtml([
        'dir="ltr"', e('Dear Ali <b>& Sons</b>,'),
        $intro['overdue']->intro_en, "Invoice {$n}, Seef Tower 101, due 04/11/2026, balance BHD 1,250.500, 1 day overdue",
        $intro['cheque']->intro_en, 'Cheque 501, NBB, dated 12/11/2026, BHD 400.000: please ensure funds are available.',
        $intro['renewal']->intro_en, "Agreement {$ag}, Seef Tower 101, ends 09/01/2027: please contact us to renew.",
        'dir="rtl"', 'عزيزي المستأجر علي وأولاده،',
        $intro['overdue']->intro_ar, "فاتورة {$n}، Seef Tower 101، تاريخ الاستحقاق 04/11/2026، الرصيد 1,250.500 د.ب، أيام التأخير 1",
        $intro['cheque']->intro_ar, 'شيك رقم 501، NBB، بتاريخ 12/11/2026، المبلغ 400.000 د.ب: يرجى التأكد من توفر الرصيد.',
        $intro['renewal']->intro_ar, "العقد {$ag}، Seef Tower 101، ينتهي في 09/01/2027: يرجى التواصل معنا للتجديد.",
    ], false);
});

test('names and the Admin\'s intro lines are escaped; the footer carries the company details', function () {
    ReminderSetting::query()->where('type', 'overdue')->sole()->update(['intro_en' => 'Pay <script>now</script> & thanks']);

    ($this->mail)('overdue')
        ->assertSeeInHtml('Dear Ali <b>& Sons</b>,') // assertSeeInHtml escapes the needle: this checks the escaped form
        ->assertDontSeeInHtml('<b>&', false)
        ->assertSeeInHtml('Pay <script>now</script> & thanks')
        ->assertDontSeeInHtml('<script>', false)
        ->assertSeeInOrderInHtml(['Demo Properties W.L.L.', '+973 1700 0000', 'office@demo.test', 'Building 1, Road 2, Seef', 'شركة ديمو للعقارات ذ.م.م', 'مبنى 1، طريق 2، السيف']);
});

test('the Arabic greeting falls back to the English name', function () {
    $this->tenant->update(['name_ar' => null]);

    ($this->mail)('renewal')->assertSeeInHtml('عزيزي المستأجر Ali <b>& Sons</b>،');
});

test('the email channel: ready only with a real mailer; no address means "No email"; it sends one combined email', function () {
    $channel = app(Channel::class);
    expect($channel)->toBeInstanceOf(EmailChannel::class);

    foreach (['log' => false, 'array' => false, 'smtp' => true] as $mailer => $ready) {
        config(['mail.default' => $mailer]);
        expect($channel->ready())->toBe($ready, $mailer);
    }
    expect($channel->name())->toBe('email')
        ->and($channel->unavailableReason($this->tenant))->toBeNull()
        ->and($channel->unavailableReason(Customer::factory()->make(['email' => null])))->toBe('No email')
        ->and($channel->unavailableReason(Customer::factory()->make(['email' => ' '])))->toBe('No email');

    Mail::fake();
    $channel->send($this->tenant, [($this->item)('overdue'), ($this->item)('renewal')]);
    Mail::assertSent(TenantReminder::class, fn (TenantReminder $m) => $m->hasTo('ali@tenant.test') && count($m->items) === 2);
});
