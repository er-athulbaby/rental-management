<?php

namespace App\Actions\Maintenance;

use App\Enums\Trade;
use App\Enums\VendorType;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Spec 2026-10-17 maintenance §3.1: name ≤150, type, ≥1 trade, mobile, optional email, CR, VAT number, notes; active. */
final class SaveVendor
{
    /** @param  array<array-key, mixed>  $data */
    public function handle(User $actor, ?Vendor $vendor, array $data): Vendor
    {
        if (! ($vendor ? $actor->can('update', $vendor) : $actor->can('create', Vendor::class))) {
            throw new AuthorizationException;
        }

        $data = array_map(fn (mixed $v) => $v === '' ? null : $v, $data); // Livewire sends '' for cleared inputs

        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::enum(VendorType::class)],
            'trades' => ['required', 'array', 'min:1'],
            'trades.*' => ['required', 'distinct', Rule::enum(Trade::class)],
            'mobile' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'cr_number' => ['nullable', 'string', 'max:30'],
            'vat_number' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'active' => ['required', 'boolean'],
        ])->validate();

        $trades = array_values(array_filter(array_map(fn (Trade $t) => $t->value, Trade::cases()), fn (string $t) => in_array($t, $validated['trades'], true)));

        $vendor ??= new Vendor;
        $vendor->fill([...$validated, 'trades' => $trades, 'active' => (bool) $validated['active']])->save();

        return $vendor;
    }
}
