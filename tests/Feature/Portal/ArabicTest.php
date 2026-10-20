<?php

use App\Enums\AgreementStatus;
use App\Enums\ChequeStatus;
use App\Enums\DepositMovementType;
use App\Enums\DocumentCategory;
use App\Enums\MaintenanceStatus;
use App\Enums\PaymentMethod;
use App\Enums\Trade;

/** @return array<string, string> */
function portalArabic(): array
{
    return json_decode((string) file_get_contents(lang_path('ar.json')), true, flags: JSON_THROW_ON_ERROR);
}

test('every __() string in the portal files has an Arabic translation', function () {
    $files = [
        ...glob(resource_path('views/livewire/portal/*.blade.php')),
        resource_path('views/layouts/portal.blade.php'),
        ...glob(app_path('Livewire/Portal/*.php')),
        app_path('Http/Middleware/PortalSession.php'),
        app_path('Actions/Portal/SendLoginCode.php'),
        app_path('Actions/Portal/VerifyLoginCode.php'),
    ];
    $ar = portalArabic();
    $missing = [];
    foreach ($files as $file) {
        preg_match_all('/__\(\'((?:\\\\.|[^\'\\\\])*)\'/', (string) file_get_contents($file), $m);
        foreach ($m[1] as $key) {
            if (! array_key_exists(stripslashes($key), $ar)) {
                $missing[] = basename($file).': '.stripslashes($key);
            }
        }
    }

    expect($files)->not->toBeEmpty()->and($missing)->toBe([]);
});

test('the labels the portal shows from shared code have Arabic translations', function () {
    $labels = [
        ...array_map(fn (Trade $t) => $t->label(), Trade::cases()),
        ...array_map(fn (MaintenanceStatus $s) => $s->label(), MaintenanceStatus::cases()),
        ...array_map(fn (AgreementStatus $s) => $s->label(), [AgreementStatus::Active, AgreementStatus::Expired, AgreementStatus::Renewed, AgreementStatus::Closed, AgreementStatus::Terminated]),
        ...array_map(fn (ChequeStatus $s) => $s->label(), ChequeStatus::cases()),
        ...array_map(fn (PaymentMethod $m) => $m->label(), PaymentMethod::cases()),
        ...array_map(fn (DocumentCategory $c) => $c->label(), DocumentCategory::cases()),
        ...array_map(fn (DepositMovementType $t) => str($t->value)->headline()->toString(), DepositMovementType::cases()),
        'Confirmed', 'Reversed', // payment statuses, through <x-status-badge>
        'Paid', 'Unpaid', 'Partially paid', 'Overdue', // Invoice::displayLabel()
        'Invoice', 'Credit note', 'Payment', 'Payment reversal', 'Credit refund', 'Credit refund reversal', // CustomerStatement kinds
        'Building common area — :building', // LogMaintenanceRequest::portalPlaces()
        'You have sent :count requests today. Please call the office for anything else.', // LogMaintenanceRequest::forPortal()
        'Choose one of your units or a building common area.', 'Choose a category.', 'Describe the problem in up to 2000 characters.',
        'Say whether the vendor may enter.', 'Send up to 5 photos.', 'Each photo must be a JPG, PNG or WEBP picture of up to 10 MB.',
    ];

    expect(array_values(array_diff($labels, array_keys(portalArabic()))))->toBe([]);
});
