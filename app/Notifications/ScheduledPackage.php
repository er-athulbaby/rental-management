<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Spec 2026-10-17 §5: one line per item, the skipped ones, the workbook attached under 10 MB, a link to the package. */
class ScheduledPackage extends Notification
{
    use Queueable;

    /**
     * @param  list<string>  $lines  PackageResult::lines()
     * @param  list<string>  $skipped  PackageResult::skippedLines()
     */
    public function __construct(public string $packageName, public string $periodLabel, public array $lines, public array $skipped,
        public string $path, public bool $attach, public string $url) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject(__(':name — :period', ['name' => $this->packageName, 'period' => $this->periodLabel]));
        foreach ($this->lines as $line) {
            $mail->line($line);
        }
        if ($this->skipped !== []) {
            $mail->line(__('Skipped:'));
            foreach ($this->skipped as $line) {
                $mail->line($line);
            }
        }
        if ($this->attach) {
            $mail->attach($this->path, ['as' => str($this->packageName)->slug().'.xlsx']);
        } else {
            $mail->line(__('The file is too large to attach; open the package to download it.'));
        }

        return $mail->action(__('Open in the app'), $this->url);
    }
}
