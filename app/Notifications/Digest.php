<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Spec §12: a morning digest — counts and links only, never ID numbers. */
class Digest extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  list<array{label: string, count: int, url: string}>  $items */
    public function __construct(public string $subject, public array $items) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject);
        foreach ($this->items as $item) {
            $mail->line(__(':label: :count', ['label' => $item['label'], 'count' => $item['count']]).' — '.$item['url']);
        }

        return $mail->action(__('Open the system'), route('dashboard'));
    }
}
