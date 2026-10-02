<?php

use Illuminate\Support\Facades\Schedule;

// Every job: no overlap (lock expires after 120 min if a run crashes) and a Forge heartbeat on success (spec §12).
// Heartbeat URLs are read with config(), never env(): env() is null after config:cache.

Schedule::command('rms:number-sequences')->yearlyOn(12, 1, '04:30')->withoutOverlapping(120)
    ->pingOnSuccessIf(filled($url = config('services.forge.heartbeats.number_sequences')), (string) $url);

Schedule::command('backup:clean')->dailyAt('03:15')->withoutOverlapping(120)
    ->pingOnSuccessIf(filled($url = config('services.forge.heartbeats.backup_clean')), (string) $url);

Schedule::command('backup:run')->dailyAt('03:30')->withoutOverlapping(120)
    ->pingOnSuccessIf(filled($url = config('services.forge.heartbeats.backup_run')), (string) $url);

Schedule::command('backup:monitor')->dailyAt('07:00')->withoutOverlapping(120)
    ->pingOnSuccessIf(filled($url = config('services.forge.heartbeats.backup_monitor')), (string) $url);

Schedule::command('rms:owner-contracts:close')->dailyAt('02:15')->withoutOverlapping(120)
    ->pingOnSuccessIf(filled($url = config('services.forge.heartbeats.owner_contracts')), (string) $url);

Schedule::command('rms:invoices:issue')->dailyAt('01:00')->withoutOverlapping(120)
    ->pingOnSuccessIf(filled($url = config('services.forge.heartbeats.invoices_issue')), (string) $url);

Schedule::command('rms:agreements:expire')->dailyAt('02:00')->withoutOverlapping(120)
    ->pingOnSuccessIf(filled($url = config('services.forge.heartbeats.agreements_expire')), (string) $url);

Schedule::command('rms:integrity-check')->dailyAt('02:30')->withoutOverlapping(120)
    ->pingOnSuccessIf(filled($url = config('services.forge.heartbeats.integrity_check')), (string) $url);

Schedule::command('rms:owner-statements:draft')->monthlyOn(1, '04:00')->withoutOverlapping(120)
    ->pingOnSuccessIf(filled($url = config('services.forge.heartbeats.owner_statements')), (string) $url);

Schedule::command('rms:digests finance')->dailyAt('07:00')->withoutOverlapping(120)
    ->pingOnSuccessIf(filled($url = config('services.forge.heartbeats.digests_finance')), (string) $url);

Schedule::command('rms:digests management')->dailyAt('07:00')->withoutOverlapping(120)
    ->pingOnSuccessIf(filled($url = config('services.forge.heartbeats.digests_management')), (string) $url);

Schedule::command('rms:digests documents')->weeklyOn(1, '07:00')->withoutOverlapping(120)
    ->pingOnSuccessIf(filled($url = config('services.forge.heartbeats.digests_documents')), (string) $url);
