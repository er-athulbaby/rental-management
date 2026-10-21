<?php

namespace App\Actions\Settings;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Models\MailSetting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class SaveMailSettings
{
    /**
     * @param  bool  $enabled  switching mail on needs a host and a from address
     * @return array<string, mixed>
     */
    public static function rules(bool $enabled): array
    {
        $needed = $enabled ? 'required' : 'nullable';

        return [
            'enabled' => ['boolean'],
            'host' => [$needed, 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(MailSetting::ENCRYPTIONS)],
            'username' => ['nullable', 'string', 'max:255'],
            'from_address' => [$needed, 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  a blank or missing password keeps the saved one
     */
    public function handle(User $actor, array $data): MailSetting
    {
        if (! $actor->can(PermissionName::SettingsManage)) {
            throw new AuthorizationException;
        }

        $data = Arr::only($data, [...MailSetting::EDITABLE, 'password']);
        $validated = Validator::make($data, self::rules(filter_var($data['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)))->validate();

        return DB::transaction(function () use ($actor, $validated) {
            $row = MailSetting::query()->lockForUpdate()->first() ?? new MailSetting;
            $old = $row->exists ? Arr::only($row->attributesToArray(), MailSetting::EDITABLE) : [];

            $changePassword = filled($validated['password'] ?? null);
            $row->fill(Arr::except($validated, 'password') + ['updated_by' => $actor->id]);
            if ($changePassword) {
                $row->password = $validated['password'];
            }
            $row->save();

            // The password never goes in: only that it changed.
            Audit::log(
                'settings.mail.updated',
                $row,
                old: $old,
                new: Arr::only($row->attributesToArray(), MailSetting::EDITABLE),
                properties: ['password_changed' => $changePassword],
                causer: $actor,
            );

            return $row;
        });
    }
}
