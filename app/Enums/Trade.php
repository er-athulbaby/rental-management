<?php

namespace App\Enums;

/** Spec 2026-10-17 maintenance §3.1: a vendor's trades, also a request's category. */
enum Trade: string
{
    case Plumbing = 'plumbing';
    case Electrical = 'electrical';
    case Ac = 'ac';
    case Carpentry = 'carpentry';
    case Painting = 'painting';
    case Cleaning = 'cleaning';
    case PestControl = 'pest_control';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::Plumbing => __('Plumbing'),
            self::Electrical => __('Electrical'),
            self::Ac => __('Air conditioning'),
            self::Carpentry => __('Carpentry'),
            self::Painting => __('Painting'),
            self::Cleaning => __('Cleaning'),
            self::PestControl => __('Pest control'),
            self::General => __('General'),
        };
    }

    /** Spec §4 step 4: cleaning → cleaning, all others → maintenance. */
    public function expenseCategory(): ExpenseCategory
    {
        return $this === self::Cleaning ? ExpenseCategory::Cleaning : ExpenseCategory::Maintenance;
    }
}
