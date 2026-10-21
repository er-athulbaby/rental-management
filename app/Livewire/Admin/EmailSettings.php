<?php

namespace App\Livewire\Admin;

use App\Actions\Settings\SaveMailSettings;
use App\Livewire\Concerns\WithActor;
use App\Mail\TestEmail;
use App\Models\MailSetting;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Title('Email settings')]
class EmailSettings extends Component
{
    use WithActor;

    /**
     * The fields of MailSetting::EDITABLE plus `password`, which is write-only: it starts empty and is never filled from
     * the saved one. Untyped on purpose: a tampered payload must reach validation, not throw a TypeError (a 500).
     */
    public mixed $form = [];

    /** Where "Send test email" goes; the signed-in user's address to start with. */
    public mixed $testTo = '';

    /** The last test's outcome, shown under the button. */
    public bool $testOk = false;

    public string $testMessage = '';

    public function mount(): void
    {
        $actor = $this->actor();
        abort_unless($actor->can('settings.manage'), 403);

        $row = MailSetting::current() ?? new MailSetting(['port' => 587, 'encryption' => 'tls']);
        $this->form = Arr::only($row->attributesToArray(), MailSetting::EDITABLE) + ['password' => ''];
        $this->testTo = $actor->email;
    }

    public function save(SaveMailSettings $save): void
    {
        abort_unless($this->actor()->can('settings.manage'), 403);

        try {
            $save->handle($this->actor(), $this->formData());
        } catch (ValidationException $e) {
            throw $this->prefixed($e);
        }

        $this->form['password'] = '';
        Flux::toast(variant: 'success', text: __('Email settings saved.'));
    }

    /** Sends one short test email now (not queued) with the form as it stands, saved or not. */
    public function sendTest(): void
    {
        abort_unless($this->actor()->can('settings.manage'), 403);
        $form = $this->formData();

        try {
            $v = Validator::make($form, SaveMailSettings::rules(true))->validate();
        } catch (ValidationException $e) {
            throw $this->prefixed($e);
        }
        $this->validate(['testTo' => ['required', 'string', 'email', 'max:255']]);

        $key = 'mail-test:'.$this->actor()->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->testOk = false;
            $this->testMessage = __('Too many test emails. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]);

            return;
        }
        RateLimiter::hit($key, 60);

        // A blank password uses the saved one, as a save would.
        $password = filled($v['password'] ?? null) ? $v['password'] : MailSetting::current()?->savedPassword();

        try {
            $mailer = Mail::build(MailSetting::smtpConfig($v, $password) + ['timeout' => 10]);
            $mailer->alwaysFrom($v['from_address'], $v['from_name'] ?? null);
            $mailer->to($this->testTo)->send(new TestEmail);
        } catch (Throwable $e) {
            $this->testOk = false;
            $this->testMessage = __('The test email was not sent: :error', ['error' => $this->plain($e, $password)]);

            return;
        }

        $this->testOk = true;
        $this->testMessage = __('Test email sent to :address', ['address' => $this->testTo]);
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return is_array($this->form) ? $this->form : [];
    }

    /** The server's own words, on one line, without the password. */
    private function plain(Throwable $e, ?string $password): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $e->getMessage()));
        if (filled($password)) {
            $text = str_replace($password, '***', $text);
        }

        return mb_substr($text, 0, 300);
    }

    // Field names get the form. prefix so errors appear next to wire:model="form.x".
    private function prefixed(ValidationException $e): ValidationException
    {
        return ValidationException::withMessages(
            collect($e->errors())->mapWithKeys(fn ($messages, $key) => ["form.$key" => $messages])->all()
        );
    }

    public function render(): View
    {
        return view('livewire.admin.email-settings', [
            'hasPassword' => filled(MailSetting::current()?->savedPassword()),
            'encryptions' => ['none' => __('None'), 'tls' => __('TLS (STARTTLS, usually port 587)'), 'ssl' => __('SSL (usually port 465)')],
        ])->title(__('Email settings'));
    }
}
