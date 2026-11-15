<?php

use App\Enums\AgreementStatus;
use App\Enums\InvoiceStatus;

/*
 * Design pass (spec 2026-11-15 §3, §11): each ui.* component renders its slots, tone classes and Arabic strings.
 */

test('page header: back link, the h1 title, the hint, then the actions', function () {
    $this->blade('<x-ui.page-header title="Invoices" hint="Everything billed" back="/back"><x-slot:actions><button>New</button></x-slot:actions></x-ui.page-header>')
        ->assertSeeInOrder(['href="/back"', 'Back', '<h1', 'Invoices', 'Everything billed', '<button>New</button>'], false);

    $this->blade('<x-ui.page-header title="Units" />')->assertSee('Units')->assertDontSee('href=', false);
});

test('stat: label then value, the tone class and data-tone, an optional link and hint', function () {
    $this->blade('<x-ui.stat label="Overdue" value="100.000 BHD" tone="danger" />')
        ->assertSeeInOrder(['Overdue', '100.000 BHD'])
        ->assertSee('data-tone="danger"', false)
        ->assertSee('text-red-700', false)
        ->assertSee('tabular-nums', false);

    $this->blade('<x-ui.stat label="Held" :value="2" hint="800.000 BHD" href="/cheques" />')
        ->assertSeeInOrder(['Held', '2', '800.000 BHD'])
        ->assertSee('href="/cheques"', false)
        ->assertSee('data-tone="neutral"', false);
});

test('stats: the column count sets the grid', function () {
    $this->blade('<x-ui.stats :cols="2"><span>a</span></x-ui.stats>')->assertSee('grid-cols-2', false)->assertDontSee('lg:grid-cols-4', false);
    $this->blade('<x-ui.stats><span>a</span></x-ui.stats>')->assertSee('lg:grid-cols-4', false);
});

test('filters: "Clear filters" only when a clear link is given, in Arabic too', function () {
    $this->blade('<x-ui.filters><input name="q"></x-ui.filters>')->assertSee('name="q"', false)->assertDontSee('Clear filters');
    $this->blade('<x-ui.filters clear="/list"><input name="q"></x-ui.filters>')->assertSee('Clear filters')->assertSee('href="/list"', false);

    app()->setLocale('ar');
    $this->blade('<x-ui.filters clear="/list" />')->assertSee('مسح عوامل التصفية');
});

test('empty: the default line, the filtered hint, a custom title and the action slot', function () {
    $this->blade('<x-ui.empty />')->assertSee('Nothing to show here.')->assertDontSee('Try clearing the filters.');
    $this->blade('<x-ui.empty :filtered="true" />')->assertSee('Try clearing the filters.');
    $this->blade('<x-ui.empty title="No cheques yet."><a href="/new">Enter cheques</a></x-ui.empty>')
        ->assertSee('No cheques yet.')->assertSee('<a href="/new">Enter cheques</a>', false);

    app()->setLocale('ar');
    $this->blade('<x-ui.empty :filtered="true" />')->assertSee('لا يوجد ما يُعرض هنا.')->assertSee('جرّب مسح عوامل التصفية.');
});

test('section: an h2 title and hint when titled, the id passes through, and no header when untitled', function () {
    $this->blade('<x-ui.section title="Identity" hint="Who they are" id="identity"><p>fields</p></x-ui.section>')
        ->assertSeeInOrder(['id="identity"', '<h2', 'Identity', 'Who they are', '<p>fields</p>'], false);
    $this->blade('<x-ui.section><p>fields</p></x-ui.section>')->assertSee('data-ui-section', false)->assertDontSee('<h2', false);
});

test('form actions: a sticky bar with Cancel before the buttons', function () {
    $this->blade('<x-ui.form-actions cancel="/units"><button>Save</button></x-ui.form-actions>')
        ->assertSee('sticky', false)
        ->assertSeeInOrder(['href="/units"', 'Cancel', '<button>Save</button>'], false);
});

test('summary: title, status badge, actions and facts', function () {
    $this->blade('<x-ui.summary title="AGR-1" :status="$s"><x-slot:actions><button>Renew</button></x-slot:actions><x-slot:facts><x-ui.fact label="Tenant">Sara</x-ui.fact></x-slot:facts></x-ui.summary>',
        ['s' => AgreementStatus::Active])
        ->assertSeeInOrder(['<h1', 'AGR-1', 'Active', '<button>Renew</button>', '<dt', 'Tenant', '<dd', 'Sara'], false)
        ->assertSee('data-tone="green"', false);
});

test('section nav: one in-page link per item', function () {
    $this->blade('<x-ui.section-nav :items="$items" />', ['items' => [['units', 'Units'], ['documents', 'Documents']]])
        ->assertSeeInOrder(['href="#units"', 'Units', 'href="#documents"', 'Documents'], false)
        ->assertSee('aria-label="On this page"', false);
});

test('table: the card carries the ui-table hook', function () {
    $this->blade('<x-ui.table><table></table></x-ui.table>')->assertSee('ui-table', false);
});

test('status colours follow spec §2', function (mixed $status, string $tone) {
    $this->blade('<x-status-badge :status="$s" />', ['s' => $status])->assertSee('data-tone="'.$tone.'"', false);
})->with([
    'draft is grey' => [InvoiceStatus::Draft, 'zinc'],
    'scheduled is blue' => [InvoiceStatus::Scheduled, 'blue'],
    'pending approval is amber' => [AgreementStatus::PendingApproval, 'amber'],
    'active is green' => [AgreementStatus::Active, 'green'],
    'overdue is red' => ['Overdue', 'red'],
    'paid is green' => ['Paid', 'green'],
    'closed is grey' => [AgreementStatus::Closed, 'zinc'],
    'finished is green' => ['Finished', 'green'],
]);

test('an Arabic display label keeps its colour', function () {
    app()->setLocale('ar');

    $this->blade('<x-status-badge :status="$s" />', ['s' => __('Paid')])->assertSee('data-tone="green"', false)->assertSee(__('Paid'));
    $this->blade('<x-status-badge :status="$s" />', ['s' => __('Overdue')])->assertSee('data-tone="red"', false);
});
