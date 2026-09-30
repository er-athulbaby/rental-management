<?php

namespace App\Agreements;

use App\Models\Agreement;
use App\Models\CompanySetting;
use App\Models\ContractTemplate;
use App\Models\ContractTemplateClause;
use App\Support\Fils;

/**
 * Spec §5.6: renders a template's clauses for one agreement. {agreement_number} and {units_table} are kept
 * for the PDF renderer (the number is only assigned on approval; the units table is not text).
 */
final class ContractMerge
{
    /** @return list<array{position: int, heading_en: string, heading_ar: string, body_en: string, body_ar: string}> */
    public function clauses(Agreement $agreement, ContractTemplate $template): array
    {
        $agreement->loadMissing('customer', 'agreementUnits.charges');
        $settings = CompanySetting::current();
        $customer = $agreement->customer;

        $common = [
            'customer_id_number' => $customer->id_number,
            'start_date' => $agreement->start_date->format('d/m/Y'),
            'end_date' => $agreement->end_date->format('d/m/Y'),
            'total_monthly_rent' => Fils::toDecimal($agreement->monthlyRentFils()),
            'total_deposit' => Fils::toDecimal($agreement->depositFils()),
            'notice_period_days' => (string) $agreement->notice_period_days,
            'grace_days' => (string) $agreement->grace_days,
        ];
        $en = self::tokens([...$common,
            'company_name' => $settings->name_en,
            'customer_name' => $customer->displayName('en'),
            'frequency' => $agreement->frequency->english(),
        ]);
        $ar = self::tokens([...$common,
            'company_name' => filled($settings->name_ar) ? (string) $settings->name_ar : $settings->name_en,
            'customer_name' => $customer->displayName('ar'),
            'frequency' => $agreement->frequency->arabic(),
        ]);

        return array_values($template->clauses->map(fn (ContractTemplateClause $c) => [
            'position' => $c->position,
            'heading_en' => $c->heading_en,
            'heading_ar' => $c->heading_ar,
            'body_en' => strtr($c->body_en, $en),
            'body_ar' => strtr($c->body_ar, $ar),
        ])->all());
    }

    /**
     * @param  array<string, string|null>  $values
     * @return array<string, string>
     */
    private static function tokens(array $values): array
    {
        $tokens = [];
        foreach ($values as $field => $value) {
            // Braces in data (a customer called "{units_table}") must never become merge fields.
            $tokens['{'.$field.'}'] = str_replace(['{', '}'], ['(', ')'], (string) $value);
        }

        return $tokens;
    }
}
