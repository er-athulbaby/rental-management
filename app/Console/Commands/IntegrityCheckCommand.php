<?php

namespace App\Console\Commands;

use App\Enums\RoleName;
use App\Integrity\IntegrityCheck;
use App\Models\User;
use App\Notifications\IntegrityCheckFailed;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/** Spec §7.11, §12 (02:30). Read-only; exits 1 on failure so the Forge heartbeat goes quiet too. */
class IntegrityCheckCommand extends Command
{
    protected $signature = 'rms:integrity-check';

    protected $description = 'Check cached balances, credit, deposits and triggers';

    public function handle(IntegrityCheck $check): int
    {
        $failures = $check->run();

        if ($failures === []) {
            $this->info('Integrity check passed.');

            return self::SUCCESS;
        }

        Log::error('Integrity check failed', ['failures' => $failures]);
        // Vendor Support is emailed even while its access is disabled: it maintains the install (spec §7.11).
        Notification::send(User::role(RoleName::VendorSupport->value)->get(), new IntegrityCheckFailed($failures));
        foreach ($failures as $failure) {
            $this->error($failure);
        }

        return self::FAILURE;
    }
}
