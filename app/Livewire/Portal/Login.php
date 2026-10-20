<?php

namespace App\Livewire\Portal;

use App\Actions\Portal\SendLoginCode;
use App\Actions\Portal\VerifyLoginCode;
use App\Reminders\EmailChannel;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Spec 2026-10-18 tenant portal §3: email → code → session on the tenant guard. Nothing here says whether an email has an account. */
#[Layout('layouts::portal')]
class Login extends Component
{
    // Untyped on purpose: a tampered payload reaches the Actions' checks instead of a TypeError (a 500).
    public mixed $email = '';

    public mixed $code = '';

    public mixed $remember = true; // spec §3: "remember this device" defaults on

    #[Locked]
    public bool $codeSent = false;

    public function sendCode(SendLoginCode $send): void
    {
        $send->handle($this->email, (string) request()->ip());

        $this->codeSent = true;
        $this->code = '';
    }

    public function signIn(VerifyLoginCode $verify): void
    {
        $account = $verify->handle($this->email, $this->code, (string) request()->ip());
        if ($account === null) {
            throw ValidationException::withMessages(['code' => __('That code is wrong or has expired. Ask for a new one.')]);
        }

        Auth::guard('tenant')->login($account, $this->remember === true);
        session()->regenerate();
        session()->put('portal_locale', $account->locale);

        $this->redirectRoute('portal.home');
    }

    public function startAgain(): void
    {
        $this->codeSent = false;
        $this->code = '';
        $this->resetValidation();
    }

    public function render(): View
    {
        return view('livewire.portal.login', ['mailReady' => (new EmailChannel)->ready()])->title(__('Sign in'));
    }
}
