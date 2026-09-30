<?php

namespace App\Pdf;

use App\Agreements\ContractMerge;
use App\Enums\AgreementStatus;
use App\Enums\ChargeType;
use App\Models\Agreement;
use App\Models\AgreementClause;
use App\Models\CompanySetting;
use App\Models\ContractTemplate;
use App\Support\Fils;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Spec §5.6, §9.2–§9.4. */
final class ContractPdf
{
    public function __construct(private PdfRenderer $renderer, private ContractMerge $merge) {}

    /** @return array{view: view-string, data: array<string, mixed>, chrome: array{footer: string, watermark: ?string}} */
    public function build(Agreement $agreement): array
    {
        $agreement->loadMissing(['customer', 'clauses', 'contractTemplate.clauses', 'agreementUnits.unit.building', 'agreementUnits.charges']);
        $approved = ! in_array($agreement->status, [AgreementStatus::Draft, AgreementStatus::PendingApproval], true);
        $number = $agreement->number ?? 'DRAFT';

        // Drafts render live from the template; from submit on, only the frozen clauses are used.
        $clauses = $agreement->status === AgreementStatus::Draft
            ? $this->merge->clauses($agreement, ($agreement->contractTemplate?->active ? $agreement->contractTemplate : ContractTemplate::defaultTemplate()?->load('clauses')) ?? throw new RuntimeException('No contract template.'))
            : $agreement->clauses->map(fn (AgreementClause $c) => $c->only(['position', 'heading_en', 'heading_ar', 'body_en', 'body_ar']))->all();

        $rows = array_map(function (array $c) use ($number) {
            if (trim($c['body_en']) === '{units_table}') {
                return [...self::headings($c), 'units' => true, 'paragraphs' => []];
            }

            $en = ContractTemplate::paragraphs(str_replace('{agreement_number}', $number, $c['body_en']));
            $ar = ContractTemplate::paragraphs(str_replace('{agreement_number}', $number, $c['body_ar']));
            $pairs = [];
            foreach (array_keys($en + $ar) as $i) {
                $pairs[] = [$en[$i] ?? '', $ar[$i] ?? ''];
            }

            return [...self::headings($c), 'units' => false, 'paragraphs' => $pairs];
        }, $clauses);

        $settings = CompanySetting::current();
        $logo = $settings->logo_path && Storage::disk('local')->exists($settings->logo_path) ? Storage::disk('local')->path($settings->logo_path) : null;

        return [
            'view' => 'pdf.contract',
            'data' => [
                'number' => $number,
                'companyEn' => $settings->name_en,
                'companyAr' => filled($settings->name_ar) ? $settings->name_ar : $settings->name_en,
                'logo' => $logo,
                'clauses' => array_values($rows),
                'units' => $agreement->agreementUnits->map(fn ($au) => [
                    'building' => $au->unit->building->name,
                    'unit' => $au->unit->code,
                    'from' => $au->start_date->format('d/m/Y'),
                    'to' => $au->end_date->format('d/m/Y'),
                    'rent' => Fils::toDecimal($au->charges->where('type', ChargeType::Rent)->sum(fn ($c) => Fils::fromDecimal($c->monthly_amount))),
                    'service' => Fils::toDecimal($au->charges->where('type', '!=', ChargeType::Rent)->sum(fn ($c) => Fils::fromDecimal($c->monthly_amount))),
                    'deposit' => $au->deposit_amount,
                ])->values()->all(),
                'verifyUrl' => $approved ? rtrim((string) config('app.url'), '/').'/v/'.$agreement->verify_token : null,
            ],
            'chrome' => [
                'footer' => '<div style="text-align:center; font-size:8pt; color:#555">'.e($number).' &nbsp;·&nbsp; {PAGENO} / {nbpg}</div>',
                'watermark' => $approved ? null : 'DRAFT',
            ],
        ];
    }

    public function render(Agreement $agreement): string
    {
        $built = $this->build($agreement);

        return $this->renderer->render($built['view'], $built['data'], $built['chrome']);
    }

    /**
     * @param  array<string, mixed>  $clause
     * @return array{position: int, heading_en: string, heading_ar: string}
     */
    private static function headings(array $clause): array
    {
        return ['position' => (int) $clause['position'], 'heading_en' => (string) $clause['heading_en'], 'heading_ar' => (string) $clause['heading_ar']];
    }
}
