<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName;
use App\Integrity\IntegrityCheck;
use App\Livewire\Payments\Create;
use App\Livewire\Reports\CollectionsReport;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentTender;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->customer = Customer::factory()->create();
    $this->a = issuedInvoice($this->customer, [['net' => '400.000']], '2026-10-01');
    $this->b = issuedInvoice($this->customer, [['net' => '300.000']], '2026-11-01');
});

function splitPay(object $test, array $tenders, array $extra = []): Payment
{
    return app(RecordPayment::class)->handle($test->finance, $test->customer, ['received_on' => '2026-10-05', 'tenders' => $tenders, ...$extra]);
}

test('card 300 + cash 200 is one payment and one receipt that pays oldest first', function () {
    $payment = splitPay($this, [
        ['method' => 'card', 'amount' => '300', 'reference' => 'SLIP-9'],
        ['method' => 'cash', 'amount' => '200.000'],
        ['method' => '', 'amount' => ''], // a blank row is ignored
    ]);

    expect(Payment::count())->toBe(1)
        ->and($payment->method->value)->toBe('split')
        ->and($payment->amount)->toBe('500.000')
        ->and($payment->number)->toBe('RCP-2026-000001')
        ->and($payment->tenders()->count())->toBe(2)
        ->and($payment->fresh()->methodSummary())->toBe('Card 300.000 (SLIP-9) + Cash 200.000')
        ->and($this->a->fresh()->balance)->toBe('0.000')
        ->and($this->b->fresh()->balance)->toBe('200.000');

    $receipt = view('pdf.receipt', ['payment' => $payment->fresh(['customer', 'tenders']), 'company' => CompanySetting::current(), 'logo' => null, 'byInvoice' => collect(), 'credit' => '0.000'])->render();
    expect($receipt)->toContain('Card 300.000 (SLIP-9) + Cash 200.000')->toContain('500.000');
    expect(app(IntegrityCheck::class)->run())->toBe([]);
});

test('the parts must be manual methods with amounts, and match the amount when one is given', function () {
    expect(fn () => splitPay($this, [['method' => 'card', 'amount' => '300'], ['method' => 'cash', 'amount' => '200']], ['amount' => '450']))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('tenders'));
    expect(fn () => splitPay($this, [['method' => 'cheque', 'amount' => '300'], ['method' => 'cash', 'amount' => '0']]))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKeys(['tenders.0.method', 'tenders.1.amount']));
    expect(Payment::count())->toBe(0);
});

test('a single part is just a payment by that method', function () {
    $payment = splitPay($this, [['method' => 'card', 'amount' => '50', 'reference' => 'SLIP-1']]);

    expect($payment->method->value)->toBe('card')->and($payment->reference)->toBe('SLIP-1')->and($payment->tenders()->count())->toBe(0);
});

test('parts are write-once', function () {
    $tender = splitPay($this, [['method' => 'card', 'amount' => '300'], ['method' => 'cash', 'amount' => '200']])->tenders()->firstOrFail();

    expect(fn () => DB::table('payment_tenders')->where('id', $tender->id)->update(['amount' => '1']))->toThrow(QueryException::class);
    expect(fn () => DB::table('payment_tenders')->where('id', $tender->id)->delete())->toThrow(QueryException::class);
    expect(PaymentTender::count())->toBe(2);
});

test('the collections report counts each part under its own method', function () {
    splitPay($this, [['method' => 'card', 'amount' => '300'], ['method' => 'cash', 'amount' => '200']]);
    app(RecordPayment::class)->handle($this->finance, $this->customer, ['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => '50']);

    $html = Livewire::actingAs($this->finance)->test(CollectionsReport::class)->set('from', '2026-10-01')->set('to', '2026-10-31')->html();
    expect(reportRowText($html, 'Card'))->toBe('05/10/2026 Card 1 300.000')
        ->and(reportRowText($html, 'Cash'))->toBe('05/10/2026 Cash 2 250.000')
        ->and(reportRowText($html, 'Total collected'))->toBe('Total collected 2 550.000');
});

test('Finance records a split payment on the form, with a running total', function () {
    Livewire::withQueryParams(['customer' => $this->customer->id])->actingAs($this->finance)->test(Create::class)
        ->set('form.method', 'split')
        ->assertCount('tenders', 2)
        ->set('tenders.0.amount', '300')->set('tenders.0.reference', 'SLIP-9')
        ->set('tenders.1.amount', '150.5')
        ->assertSee('Total: 450.500 BHD')
        ->call('addTender')->set('tenders.2.method', 'bank_transfer')->set('tenders.2.amount', '0.5')
        ->call('save')
        ->assertHasNoErrors();

    expect(Payment::sole())->amount->toBe('451.000')->method->value->toBe('split');
});
