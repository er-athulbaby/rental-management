<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class IntegrityCheckFailed extends Notification
{
    /** @param  list<string>  $failures */
    public function __construct(public array $failures) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->error()
            ->subject(__(':app: nightly integrity check failed', ['app' => config('app.name')]))
            ->line(__('The integrity check found :n problem(s):', ['n' => count($this->failures)]));

        foreach (array_slice($this->failures, 0, 50) as $failure) {
            $mail->line('• '.$failure);
        }

        return $mail->line(__('Nothing was changed. Investigate before the next business day.'));
    }
}
