<?php

namespace App\Enums;

/** Go-live import files, in the order they are imported (later files refer to earlier ones by code). */
enum ImportKind: string
{
    case Buildings = 'buildings';
    case Units = 'units';
    case Owners = 'owners';
    case OwnerContracts = 'owner_contracts';
    case Customers = 'customers';
    case Agreements = 'agreements';
    case CustomerBalances = 'customer_balances';
    case OwnerBalances = 'owner_balances';

    /** @return list<string> */
    public function headers(): array
    {
        return match ($this) {
            self::Buildings => ['code', 'name', 'type', 'location', 'address', 'floors_count', 'parking', 'facilities', 'notes'],
            self::Units => ['building_code', 'code', 'floor', 'use', 'type', 'bedrooms', 'bathrooms', 'area_sqm', 'furnishing',
                'list_rent', 'list_deposit', 'list_service_charge', 'default_tax_category', 'ewa_account_no', 'blocked', 'blocked_reason', 'notes'],
            self::Owners => ['type', 'name_en', 'name_ar', 'id_type', 'id_number', 'nationality', 'phone', 'email', 'address',
                'bank_name', 'iban', 'account_name', 'notes'],
            self::OwnerContracts => ['owner_id_type', 'owner_id_number', 'building_code', 'units', 'type', 'start_date', 'end_date',
                'rent_amount', 'payment_frequency', 'fee_type', 'fee_value', 'expense_approval_limit', 'deposits_held_by', 'notes'],
            self::Customers => ['type', 'name_en', 'name_ar', 'id_type', 'id_number', 'nationality', 'mobile', 'email', 'address', 'contact_person', 'emergency_contact_name', 'emergency_contact_phone', 'notes'],
            self::Agreements => ['import_ref', 'customer_id_type', 'customer_id_number', 'start_date', 'end_date', 'frequency', 'billing_day', 'grace_days',
                'notice_period_days', 'building_code', 'unit_code', 'unit_start_date', 'unit_end_date', 'deposit_amount', 'rent', 'service_charge', 'parking',
                'other', 'other_description', 'tax_category'],
            self::CustomerBalances => ['customer_id_type', 'customer_id_number', 'agreement_ref', 'building_code', 'unit_code', 'amount', 'description'],
            self::OwnerBalances => ['owner_id_type', 'owner_id_number', 'building_code', 'amount'],
        };
    }

    /** The column summed into the import's reconciliation totals (spec §11: checked against the old system). */
    public function moneyColumn(): ?string
    {
        return match ($this) {
            self::CustomerBalances, self::OwnerBalances => 'amount',
            default => null,
        };
    }

    /**
     * Spec §11: ID, phone, IBAN and money columns must be Text in Excel; a number there has
     * already lost leading zeros or decimals, so it is refused rather than guessed at.
     *
     * @return list<string>
     */
    public function textColumns(): array
    {
        return match ($this) {
            self::Buildings => [],
            self::Units => ['list_rent', 'list_deposit', 'list_service_charge', 'ewa_account_no'],
            self::Owners => ['id_number', 'phone', 'iban'],
            self::OwnerContracts => ['owner_id_number', 'rent_amount', 'fee_value', 'expense_approval_limit'],
            self::Customers => ['id_number', 'mobile', 'emergency_contact_phone'],
            self::Agreements => ['customer_id_number', 'deposit_amount', 'rent', 'service_charge', 'parking', 'other'],
            self::CustomerBalances => ['customer_id_number', 'amount'],
            self::OwnerBalances => ['owner_id_number', 'amount'],
        };
    }
}
