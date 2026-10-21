<?php

namespace App\Actions\Customers;

use App\Actions\Portal\SyncPortalEmail;
use App\Enums\CustomerType;
use App\Enums\IdType;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;

/** Spec §5.1: one master record per customer. */
final class SaveCustomer
{
    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, ?Customer $customer, array $data): Customer
    {
        if (! ($customer ? $actor->can('update', $customer) : $actor->can('create', Customer::class))) {
            throw new AuthorizationException;
        }

        $data = array_map(fn (mixed $v) => is_string($v) && trim($v) === '' ? null : (is_string($v) ? trim($v) : $v), $data);

        $validated = Validator::make($data, [
            'type' => ['required', Rule::enum(CustomerType::class)],
            'name_en' => ['required', 'string', 'max:150'],
            'name_ar' => ['nullable', 'string', 'max:150'],
            'id_type' => ['required', Rule::enum(IdType::class)],
            'id_number' => ['required', 'string', 'max:30'],
            'nationality' => ['nullable', 'string', 'max:60', Rule::in(array_filter([...config('nationalities'), $customer?->nationality]))],
            'mobile' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'no_reminders' => ['sometimes', 'boolean'], // spec 2026-10-17 §6: "Don't send reminders"
        ])->after(function (ValidatorInstance $validator) use ($data, $customer) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $companyIdOk = $data['type'] === CustomerType::Company->value
                ? $data['id_type'] === IdType::Cr->value
                : in_array($data['id_type'], [IdType::Cpr->value, IdType::Passport->value], true);
            if (! $companyIdOk) {
                $validator->errors()->add('id_type', __('Companies are identified by CR; individuals by CPR or passport.'));
            }

            // Deliberately unscoped: a scoped user must learn the customer exists, but only its name and masked ID (spec §8.2).
            $existing = Customer::query()->where('id_type', $data['id_type'])->where('id_number', $data['id_number'])
                ->when($customer?->exists, fn ($q) => $q->whereKeyNot($customer?->getKey()))->first();
            if ($existing) {
                $validator->errors()->add('id_number', __('Already exists: :name, ID :id', ['name' => $existing->name_en, 'id' => $existing->maskedId()]));
            }

            // Spec 2026-10-18 tenant portal §3 and 2026-10-21 owner portal §3: the portal account follows the email (owner portal plan ruling 7).
            $problem = SyncPortalEmail::problem($customer, is_string($data['email'] ?? null) ? $data['email'] : null);
            if ($problem !== null) {
                $validator->errors()->add('email', $problem);
            }
        })->validate();

        $id = $customer?->id;

        // Retry-safe: each attempt re-reads the row; the caller's model loses its dirty state on the first save.
        try {
            return DB::transaction(function () use ($id, $validated) {
                $customer = $id !== null ? Customer::query()->lockForUpdate()->findOrFail($id) : new Customer;
                $customer->fill($validated)->save();
                SyncPortalEmail::apply($customer);

                return $customer;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) { // a racing invite or save took this email
            throw ValidationException::withMessages(['email' => __('Another tenant\'s portal account uses this email.')]);
        }
    }
}
