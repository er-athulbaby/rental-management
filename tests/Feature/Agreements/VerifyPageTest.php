<?php

use App\Models\Customer;
use App\Models\Unit;

beforeEach(function () {
    $this->agreement = activeAgreement([
        'customer_id' => Customer::factory()->create(['name_en' => 'Private Person'])->id,
        'start_date' => '2026-11-01', 'end_date' => '2027-10-31',
    ], [Unit::factory()->create()]);
});

test('the public page shows only number, status and dates, and is not indexed', function () {
    $this->get('/v/'.$this->agreement->verify_token)
        ->assertOk()
        ->assertSee($this->agreement->number)
        ->assertSee('Active')
        ->assertSee('01/11/2026')
        ->assertSee('31/10/2027')
        ->assertDontSee('Private Person')
        ->assertSee('noindex', false)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

test('unknown tokens are 404 and the page is rate-limited', function () {
    $this->get('/v/'.str_repeat('z', 32))->assertNotFound();

    foreach (range(1, 30) as $i) {
        $this->get('/v/'.str_repeat('q', 32));
    }
    $this->get('/v/'.$this->agreement->verify_token)->assertStatus(429);
});
