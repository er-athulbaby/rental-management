<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\WithActor;
use App\Models\ReminderSetting;
use App\Reminders\Channel;
use Closure;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/** Spec 2026-10-17 §3: per type, on/off, the rule days and the intro lines in English and Arabic. Every type starts off. */
class TenantReminders extends Component
{
    use WithActor;

    /**
     * type => ['enabled' => bool, 'days' => '1, 7, 14', 'intro_en' => string, 'intro_ar' => string]. Untyped on purpose:
     * a tampered payload (text, arrays) must reach validation, not throw a TypeError (a 500).
     */
    public mixed $settings = [];

    public function mount(): void
    {
        abort_unless($this->actor()->can('settings.manage'), 403);
        $this->settings = ReminderSetting::query()->get()->mapWithKeys(fn (ReminderSetting $s) => [$s->type => [
            'enabled' => $s->enabled, 'days' => implode(', ', $s->days), 'intro_en' => $s->intro_en, 'intro_ar' => $s->intro_ar,
        ]])->all();
    }

    public function save(): void
    {
        abort_unless($this->actor()->can('settings.manage'), 403);
        $settings = $this->validate($this->settingsRules())['settings'];

        DB::transaction(function () use ($settings) {
            foreach (ReminderSetting::TYPES as $type) {
                ReminderSetting::query()->where('type', $type)->sole()->update([
                    'enabled' => (bool) $settings[$type]['enabled'],
                    'days' => ReminderSetting::parseDays($settings[$type]['days']),
                    'intro_en' => trim($settings[$type]['intro_en']),
                    'intro_ar' => trim($settings[$type]['intro_ar']),
                ]);
            }
        });

        Flux::toast(variant: 'success', text: __('Reminder settings saved.'));
    }

    /** @return array<string, list<mixed>> */
    private function settingsRules(): array
    {
        $rules = ['settings' => ['required', 'array']];
        foreach (ReminderSetting::TYPES as $type) {
            $rules["settings.{$type}"] = ['required', 'array'];
            $rules["settings.{$type}.enabled"] = ['required', 'boolean'];
            $rules["settings.{$type}.days"] = ['bail', 'required', 'string', 'max:60', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || ReminderSetting::parseDays($value) === null) {
                    $fail(__('Enter 1 to 5 different whole numbers from 1 to 365, separated by commas.'));
                }
            }];
            $rules["settings.{$type}.intro_en"] = ['required', 'string', 'max:300'];
            $rules["settings.{$type}.intro_ar"] = ['required', 'string', 'max:300'];
        }

        return $rules;
    }

    public function render(): View
    {
        return view('livewire.admin.tenant-reminders', [
            'mailReady' => app(Channel::class)->ready(),
            'types' => [
                'rent_due' => [__('Rent due'), __('Days before the due date')],
                'overdue' => [__('Overdue'), __('Days after the grace period ends')],
                'cheque' => [__('Post-dated cheque'), __('Days before the cheque date')],
                'renewal' => [__('Agreement ending'), __('Days before the agreement ends')],
            ],
        ])->title(__('Tenant reminders'));
    }
}
