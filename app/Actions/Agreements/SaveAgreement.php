<?php

namespace App\Actions\Agreements;

use App\Enums\AgreementStatus;
use App\Enums\ChargeType;
use App\Enums\PaymentFrequency;
use App\Enums\TaxCategory;
use App\Models\Agreement;
use App\Models\AgreementUnit;
use App\Models\CompanySetting;
use App\Models\ContractTemplate;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;

/** Creates or edits a DRAFT agreement with its units and charges (spec §5.2, §5.3). */
final class SaveAgreement
{
    /** @param  array<string, mixed>  $data  shape: see the M2 plan, Task 3 */
    public function handle(User $actor, ?Agreement $agreement, array $data): Agreement
    {
        if (! ($agreement ? $actor->can('update', $agreement) : $actor->can('create', Agreement::class))) {
            throw new AuthorizationException;
        }

        if ($agreement && $agreement->status !== AgreementStatus::Draft) {
            throw ValidationException::withMessages(['status' => __('Only draft agreements can be edited.')]);
        }

        $data = self::blankToNull($data);

        $validated = Validator::make($data, [
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'frequency' => ['required', Rule::enum(PaymentFrequency::class)],
            'billing_day' => ['nullable', 'integer', 'between:1,28'],
            'grace_days' => ['nullable', 'integer', 'between:0,60'],
            'notice_period_days' => ['nullable', 'integer', 'between:0,365'],
            'contract_template_id' => ['nullable', 'integer', Rule::exists('contract_templates', 'id')->where('active', true)],
            'units' => ['required', 'array', 'min:1'],
            'units.*.unit_id' => ['required', 'integer', 'distinct', Rule::exists('units', 'id')->whereNull('deleted_at')],
            'units.*.deposit_amount' => ['nullable', Fils::rule()],
            'units.*.start_date' => ['nullable', 'date_format:Y-m-d'],
            'units.*.end_date' => ['nullable', 'date_format:Y-m-d'],
            'units.*.charges' => ['required', 'array', 'min:1'],
            'units.*.charges.*.type' => ['required', Rule::enum(ChargeType::class)],
            'units.*.charges.*.description' => ['nullable', 'string', 'max:150'],
            'units.*.charges.*.monthly_amount' => ['required', Fils::rule()],
            'units.*.charges.*.tax_category' => ['required', Rule::enum(TaxCategory::class)],
        ])->after(fn (ValidatorInstance $v) => $this->check($v, $data, $actor))->validate();

        // Every unit a write targets must be in the actor's buildings (spec §8.2).
        $unitIds = array_map(fn (array $u) => (int) $u['unit_id'], $validated['units']);
        if (Unit::query()->visibleTo($actor)->whereKey($unitIds)->count() !== count($unitIds)) {
            throw new AuthorizationException;
        }

        $id = $agreement?->id;

        // Retry-safe: every attempt re-reads and re-locks its rows instead of reusing the caller's model.
        return DB::transaction(function () use ($actor, $id, $validated) {
            $settings = CompanySetting::current();

            if ($id !== null) {
                $agreement = Agreement::query()->lockForUpdate()->findOrFail($id);
                if ($agreement->status !== AgreementStatus::Draft) {
                    throw ValidationException::withMessages(['status' => __('Only draft agreements can be edited.')]);
                }
            } else {
                $agreement = (new Agreement)->forceFill(['created_by' => $actor->id]);
            }

            $agreement->fill([
                'customer_id' => $validated['customer_id'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'frequency' => $validated['frequency'],
                'billing_day' => $validated['billing_day'] ?? null,
                'grace_days' => $validated['grace_days'] ?? $settings->default_grace_days,
                'notice_period_days' => $validated['notice_period_days'] ?? 30,
                'contract_template_id' => $validated['contract_template_id'] ?? $agreement->contract_template_id ?? ContractTemplate::defaultTemplate()?->id,
            ])->save();

            $existing = $agreement->agreementUnits()->with('charges')->get()->keyBy('unit_id');
            $keep = array_map(fn (array $u) => (int) $u['unit_id'], $validated['units']);

            foreach ($existing as $unitId => $au) {
                if (! in_array((int) $unitId, $keep, true)) {
                    $au->charges->each->delete();
                    $au->delete();
                }
            }

            foreach ($validated['units'] as $line) {
                $unitId = (int) $line['unit_id'];
                // list_rent is copied from the unit when it is first added (spec §5.3).
                $au = $existing->get($unitId) ?? new AgreementUnit(['unit_id' => $unitId, 'list_rent' => Unit::findOrFail($unitId)->list_rent]);
                $au->fill([
                    'deposit_amount' => self::money($line['deposit_amount'] ?? '0'),
                    'start_date' => $line['start_date'] ?? $validated['start_date'],
                    'end_date' => $line['end_date'] ?? $validated['end_date'],
                ]);
                $agreement->agreementUnits()->save($au);

                $au->charges()->get()->each->delete();
                foreach ($line['charges'] as $charge) {
                    $au->charges()->create([
                        'type' => $charge['type'],
                        'description' => $charge['description'] ?? null,
                        'monthly_amount' => self::money($charge['monthly_amount']),
                        'tax_category' => $charge['tax_category'],
                    ]);
                }
            }

            return $agreement->load('agreementUnits.charges');
        }, attempts: 3);
    }

    /** @param  array<string, mixed>  $data */
    private function check(ValidatorInstance $validator, array $data, User $actor): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        if (! Customer::query()->visibleTo($actor)->whereKey($data['customer_id'])->exists()) {
            $validator->errors()->add('customer_id', __('Choose a tenant you can see.'));
        }

        foreach (array_values((array) $data['units']) as $i => $line) {
            $from = $line['start_date'] ?? $data['start_date'];
            $to = $line['end_date'] ?? $data['end_date'];
            if ($from < $data['start_date'] || $to > $data['end_date'] || $from > $to) {
                $validator->errors()->add("units.$i.start_date", __('A unit\'s dates must fall within the agreement\'s dates.'));
            }

            $rents = array_values(array_filter((array) $line['charges'], fn (array $c) => $c['type'] === ChargeType::Rent->value));
            if (count($rents) !== 1) {
                $validator->errors()->add("units.$i.charges", __('Each unit needs exactly one rent charge.'));
            } elseif (Fils::fromDecimal((string) $rents[0]['monthly_amount']) === 0) {
                $validator->errors()->add("units.$i.charges", __('Rent must be more than zero.'));
            }
        }
    }

    private static function money(mixed $value): string
    {
        return Fils::toDecimal(Fils::fromDecimal((string) $value));
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function blankToNull(array $data): array
    {
        return array_map(fn (mixed $v) => is_array($v) ? self::blankToNull($v) : (is_string($v) && trim($v) === '' ? null : $v), $data);
    }
}
