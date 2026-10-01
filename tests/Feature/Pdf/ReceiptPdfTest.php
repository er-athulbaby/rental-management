<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName;
use App\Jobs\StoreReceipt;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->customer = Customer::factory()->create();
    issuedInvoice($this->customer, [['net' => '100.000']]);
});

test('recording a payment stores its receipt once, privately', function () {
    $payment = app(RecordPayment::class)->handle($this->finance, $this->customer, ['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => '120.000']);

    $document = StoreReceipt::stored($payment);
    expect($document)->not->toBeNull()
        ->and($document->category->value)->toBe('generated_pdf')
        ->and($document->original_name)->toBe('RCP-2026-000001.pdf');
    Storage::disk('local')->assertExists($document->path);

    StoreReceipt::dispatchSync($payment->id, $this->finance->id); // idempotent
    expect(Document::where('documentable_id', $payment->id)->where('documentable_type', $payment->getMorphClass())->count())->toBe(1);

    $this->actingAs($this->finance)->get(route('payments.receipt', $payment))->assertRedirect(route('documents.download', $document));
});
