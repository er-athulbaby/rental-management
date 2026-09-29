<?php

namespace App\Console\Commands;

use App\Audit\Audit;
use App\Models\CompanySetting;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SetInstallSetting extends Command
{
    protected $signature = 'rms:setting {key : require_different_approver | go_live_at} {value : true|false, or Y-m-d|null}';

    protected $description = 'Change an install-level setting (spec §3). Audited.';

    public function handle(): int
    {
        $key = (string) $this->argument('key');
        $raw = (string) $this->argument('value');

        // [valid, value to store, value to show in the audit entry]
        [$valid, $value, $shown] = match (true) {
            $key === 'require_different_approver' && in_array($raw, ['true', 'false'], true) => [true, $raw === 'true', $raw === 'true'],
            $key === 'go_live_at' && $raw === 'null' => [true, null, null],
            $key === 'go_live_at' && CarbonImmutable::canBeCreatedFromFormat($raw, 'Y-m-d') => [true, CarbonImmutable::createFromFormat('Y-m-d', $raw)->startOfDay(), $raw],
            default => [false, null, null],
        };

        if (! $valid) {
            $this->error("Unknown key or invalid value: {$key} = {$raw}");

            return self::FAILURE;
        }

        $settings = CompanySetting::current();
        $old = $settings->getAttribute($key);

        $settings->forceFill([$key => $value])->save();

        Audit::log('settings.install_level.changed', $settings,
            [$key => $old instanceof \DateTimeInterface ? $old->format('Y-m-d') : $old],
            [$key => $shown],
        );

        $this->info("{$key} updated.");

        return self::SUCCESS;
    }
}
