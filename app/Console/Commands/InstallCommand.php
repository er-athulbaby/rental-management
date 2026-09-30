<?php

namespace App\Console\Commands;

use App\Actions\ContractTemplates\EnsureDefaultContractTemplate;
use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Models\CompanySetting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\RecoveryCode;

class InstallCommand extends Command
{
    protected $signature = 'rms:install
        {--company= : Company name (English)}
        {--admin-name= : First Admin user\'s name}
        {--admin-email= : First Admin user\'s email}
        {--vendor-email= : Vendor Support account email}';

    protected $description = 'Set up a new company install (spec §13.2). Runs once, on an empty database.';

    public function handle(EnsureNumberSequences $sequences, TwoFactorAuthenticationProvider $twoFactor): int
    {
        if (CompanySetting::query()->exists()) {
            $this->error('This install is already installed. Drop the database first to reinstall (spec §13.2).');

            return self::FAILURE;
        }

        $input = Validator::make([
            'company' => $this->option('company'),
            'admin_name' => $this->option('admin-name'),
            'admin_email' => $this->option('admin-email'),
            'vendor_email' => $this->option('vendor-email'),
        ], [
            'company' => ['required', 'string', 'max:150'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'different:vendor_email'],
            'vendor_email' => ['required', 'email'],
        ]);

        if ($input->fails()) {
            foreach ($input->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $data = $input->validated();
        $vendorPassword = Str::password(32);
        $secret = $twoFactor->generateSecretKey();

        [$admin, $vendor] = DB::transaction(function () use ($data, $sequences, $vendorPassword, $secret) {
            (new RolesAndPermissionsSeeder)->run();

            CompanySetting::query()->forceCreate(['id' => 1, 'name_en' => $data['company']]);

            $year = now('Asia/Bahrain')->year;
            $sequences($year);
            $sequences($year + 1);
            app(EnsureDefaultContractTemplate::class)();

            $admin = User::create(['name' => $data['admin_name'], 'email' => Str::lower($data['admin_email']), 'password' => Str::password(40)]);
            $admin->assignRole(RoleName::Admin);

            $vendor = User::create(['name' => 'Vendor Support', 'email' => Str::lower($data['vendor_email']), 'password' => $vendorPassword]);
            $vendor->forceFill([
                'two_factor_secret' => encrypt($secret),
                'two_factor_recovery_codes' => encrypt(json_encode(Collection::times(8, fn () => RecoveryCode::generate())->all())),
                'two_factor_confirmed_at' => now(),
            ])->save();
            $vendor->assignRole(RoleName::VendorSupport);

            return [$admin, $vendor];
        });

        Password::broker()->sendResetLink(['email' => $admin->email]);

        $this->info("Installed: {$data['company']}. A password link was emailed to {$admin->email}.");
        $this->warn('Store these in the vendor vault now; they are not shown again.');
        $this->line("Vendor Support password: {$vendorPassword}");
        $this->line('Vendor Support TOTP: '.$vendor->twoFactorQrCodeUrl());

        return self::SUCCESS;
    }
}
