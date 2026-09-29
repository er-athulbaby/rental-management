<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

test('no registration, email verification or passkey routes exist', function () {
    $uris = collect(Route::getRoutes()->getRoutes())->map->uri();

    expect($uris->filter(fn (string $uri) => Str::contains($uri, ['register', 'email/verif', 'passkey', '.well-known'])))
        ->toBeEmpty();
});

test('the register page is gone', function () {
    $this->get('/register')->assertNotFound();
});

test('the profile page offers no account deletion', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee('Delete account');
});

test('the login page has no sign-up link or remember-me box', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('Sign up')
        ->assertDontSee('Remember me');
});
