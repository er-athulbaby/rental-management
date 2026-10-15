<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ScheduledReportFailed extends Notification
{
    use Queueable;

    public function __construct(public string $reportName, public string $reason) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject(__('Scheduled report not sent: :name', ['name' => $this->reportName]))->line($this->reason);
    }
}
