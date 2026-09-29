<?php

namespace App\Console\Commands;

use App\Audit\Audit;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VendorSupportCommand extends Command
{
    protected $signature = 'rms:vendor-support {--enable} {--disable}';

    protected $description = 'Enable or disable the Vendor Support account (spec §8.1: re-enabling happens only here)';

    public function handle(): int
    {
        if ($this->option('enable') === $this->option('disable')) {
            $this->error('Pass exactly one of --enable or --disable.');

            return self::FAILURE;
        }

        $vendor = User::role('vendor-support')->firstOrFail();
        $enable = (bool) $this->option('enable');

        DB::transaction(function () use ($vendor, $enable) {
            $vendor->forceFill(['active' => $enable])->save();

            if (! $enable) {
                $vendor->logoutEverywhere();
            }

            Audit::log($enable ? 'vendor_support.enabled' : 'vendor_support.disabled', $vendor);
        });

        $this->info('Vendor Support '.($enable ? 'enabled.' : 'disabled.'));

        return self::SUCCESS;
    }
}
