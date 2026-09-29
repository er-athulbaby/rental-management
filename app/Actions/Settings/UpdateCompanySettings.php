<?php

namespace App\Actions\Settings;

use App\Enums\PermissionName;
use App\Enums\ProrationBasis;
use App\Enums\TaxCategory;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class UpdateCompanySettings
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'name_en' => ['required', 'string', 'max:150'],
            'name_ar' => ['nullable', 'string', 'max:150'],
            'cr_number' => ['nullable', 'string', 'max:30'],
            'address_en' => ['nullable', 'string', 'max:500'],
            'address_ar' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'vat_registered' => ['boolean'],
            'trn' => ['nullable', 'required_if:vat_registered,true', 'string', 'max:20'],
            'vat_rate' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'residential_tax_category' => ['required', Rule::enum(TaxCategory::class)],
            'commercial_tax_category' => ['required', Rule::enum(TaxCategory::class)],
            'currency_code' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'date_format' => ['required', Rule::in(['d/m/Y', 'Y-m-d', 'd-m-Y'])],
            'default_grace_days' => ['required', 'integer', 'between:0,60'],
            'invoice_lead_days' => ['required', 'integer', 'between:0,60'],
            'proration_basis' => ['required', Rule::enum(ProrationBasis::class)],
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, array $data, ?UploadedFile $logo = null): CompanySetting
    {
        if (! $actor->can(PermissionName::SettingsManage)) {
            throw new AuthorizationException;
        }

        // Only screen-editable keys ever reach the model (install-level keys are dropped here).
        $validated = Validator::make(Arr::only($data, CompanySetting::EDITABLE), self::rules())->validate();

        if ($logo) {
            Validator::make(['logo' => $logo], ['logo' => ['image', 'mimes:png,jpg,jpeg', 'max:2048']])->validate();
        }

        return DB::transaction(function () use ($validated, $logo) {
            $settings = CompanySetting::query()->lockForUpdate()->findOrFail(1);

            if ($logo) {
                $validated['logo_path'] = $logo->storeAs('settings', 'logo.'.$logo->extension(), 'local');
            }

            $settings->fill($validated)->save();

            return $settings;
        });
    }
}
