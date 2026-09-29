<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserEmailChanged extends Notification
{
    public function __construct(public User $subject, public string $oldEmail, public string $newEmail, public User $actor) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Email address changed for :name', ['name' => $this->subject->name]))
            ->line(__(':actor changed the sign-in email of :name from :old to :new.', [
                'actor' => $this->actor->name,
                'name' => $this->subject->name,
                'old' => $this->oldEmail,
                'new' => $this->newEmail,
            ]))
            ->line(__('If you did not expect this, contact your administrator.'));
    }
}
