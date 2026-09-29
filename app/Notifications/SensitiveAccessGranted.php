<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SensitiveAccessGranted extends Notification
{
    /** @param  list<string>  $permissions */
    public function __construct(public User $subject, public array $permissions, public User $actor) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Sensitive access granted to :name', ['name' => $this->subject->name]))
            ->line(__(':actor granted :name these permissions: :permissions.', [
                'actor' => $this->actor->name,
                'name' => $this->subject->name,
                'permissions' => implode(', ', $this->permissions),
            ]))
            ->line(__('If you did not expect this, review the change in the audit log.'));
    }
}
