<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A scheduled report: totals in the body, Excel attached under 10 MB, a link to open it (spec §7). */
class ScheduledReport extends Notification
{
    use Queueable;

    /**
     * @param  array<string, string>  $totals
     * @param  list<string>  $comparison  ready-made lines (Comparison::lines)
     */
    public function __construct(public string $reportName, public string $periodLabel, public int $rows, public array $totals,
        public string $path, public bool $attach, public string $url, public array $comparison = [], public ?string $note = null) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject(__(':name — :period', ['name' => $this->reportName, 'period' => $this->periodLabel]))
            ->line(__(':n rows.', ['n' => number_format($this->rows)]));
        if ($this->note !== null) {
            $mail->line($this->note);
        }
        foreach ($this->totals as $label => $value) {
            $mail->line("{$label}: {$value}");
        }
        foreach ($this->comparison as $line) {
            $mail->line($line);
        }
        if ($this->attach) {
            $mail->attach($this->path, ['as' => str($this->reportName)->slug().'.xlsx']);
        } else {
            $mail->line(__('The file is too large to attach; open the report to download it.'));
        }

        return $mail->action(__('Open the report'), $this->url);
    }
}
