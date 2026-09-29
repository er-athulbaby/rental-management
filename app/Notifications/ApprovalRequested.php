<?php

namespace App\Notifications;

use App\Models\Approval;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApprovalRequested extends Notification
{
    public function __construct(public Approval $approval) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $approval = $this->approval;

        $mail = (new MailMessage)
            ->subject(__('Approval needed: :action', ['action' => $approval->action->label()]))
            ->line($approval->handler()->summary($approval))
            ->line(__('Requested by :name.', ['name' => $approval->requester->name ?? '—']));

        if ($approval->reason) {
            $mail->line(__('Reason: :reason', ['reason' => $approval->reason]));
        }

        return $mail->action(__('Open pending approvals'), route('approvals.index'));
    }
}
