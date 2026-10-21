<?php

namespace App\Actions\Owners;

use App\Actions\Portal\InviteToPortal;
use App\Actions\Portal\SyncPortalEmail;
use App\Audit\Audit;
use App\Enums\IdType;
use App\Enums\PartyType;
use App\Enums\PermissionName;
use App\Models\Bank;
use App\Models\Owner;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;

final class SaveOwner
{
    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, ?Owner $owner, array $data, bool $viaImport = false): Owner
    {
        if (! ($owner ? $actor->can('update', $owner) : $actor->can('create', Owner::class))) {
            throw new AuthorizationException;
        }

        $data = array_map(fn (mixed $v) => $v === '' ? null : $v, $data); // Livewire sends '' for cleared inputs

        if (isset($data['iban']) && is_string($data['iban'])) {
            $data['iban'] = strtoupper(str_replace(' ', '', $data['iban'])) ?: null;
        }

        $validated = Validator::make($data, [
            'type' => ['required', Rule::enum(PartyType::class)],
            'name_en' => ['required', 'string', 'max:150'],
            'name_ar' => ['nullable', 'string', 'max:150'],
            'id_type' => ['required', Rule::enum(IdType::class)],
            'id_number' => ['required', 'string', 'max:30',
                Rule::unique('owners', 'id_number')->where('id_type', $data['id_type'] ?? null)->ignore($owner?->id)],
            'nationality' => ['nullable', 'string', 'max:60', Rule::in(array_filter([...config('nationalities'), $owner?->nationality]))],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'bank_name' => ['nullable', 'string', 'max:100', Bank::rule($owner?->bank_name)],
            'iban' => ['nullable', 'regex:/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/'],
            'account_name' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->after(function (ValidatorInstance $validator) use ($data, $owner) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            // Spec 2026-10-21 owner portal §3: the portal account follows the owner's email (plan ruling 7).
            $problem = SyncPortalEmail::problem($owner, is_string($data['email'] ?? null) ? trim($data['email']) : null);
            if ($problem !== null) {
                $validator->errors()->add('email', $problem);
            }
        })->validate();

        $bankNew = Arr::only($validated, Owner::BANK_FIELDS) + array_fill_keys(Owner::BANK_FIELDS, null);
        $bankOld = $owner ? $owner->only(Owner::BANK_FIELDS) : array_fill_keys(Owner::BANK_FIELDS, null);
        $pick = fn (array $src) => array_map(fn (string $f) => $src[$f] ?? null, Owner::BANK_FIELDS);
        $bankChanged = $pick($bankNew) !== $pick($bankOld);

        // The go-live import (import.run, new owners only) brings existing bank details across (spec §11).
        $importing = $viaImport && $owner === null && $actor->can(PermissionName::ImportRun);

        if ($bankChanged && ! $importing && ! $actor->can(PermissionName::OwnersBankManage)) {
            throw new AuthorizationException(__('Only holders of owners.bank.manage can change bank details.'));
        }

        try {
            return DB::transaction(function () use ($actor, $owner, $validated, $bankChanged, $bankOld, $bankNew, $importing) {
                $owner ??= new Owner;
                $owner->fill($validated);

                if ($bankChanged && ! $importing) {
                    $owner->forceFill(['bank_changed_at' => now(), 'bank_changed_by' => $actor->id]);
                }

                $owner->save();
                SyncPortalEmail::apply($owner);

                if ($bankChanged) {
                    $mask = fn (?string $iban) => $iban ? '••••'.substr($iban, -4) : null;
                    Audit::log('owner.bank.changed', $owner,
                        ['iban' => $mask($bankOld['iban']), 'bank_name' => $bankOld['bank_name'], 'account_name' => $bankOld['account_name']],
                        ['iban' => $mask($bankNew['iban']), 'bank_name' => $bankNew['bank_name'], 'account_name' => $bankNew['account_name']],
                        causer: $actor,
                    );
                }

                return $owner;
            });
        } catch (UniqueConstraintViolationException) { // a racing invite or save took this email
            throw ValidationException::withMessages(['email' => InviteToPortal::takenMessage($owner ?? new Owner)]);
        }
    }
}
