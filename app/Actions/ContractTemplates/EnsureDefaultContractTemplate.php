<?php

namespace App\Actions\ContractTemplates;

use App\Models\ContractTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Creates the starter bilingual lease on a fresh install (rms:install). The client's lawyer reviews and
 * Admin edits it before go-live (M2 exit criterion: the client signs off the EN/AR contract PDF).
 */
final class EnsureDefaultContractTemplate
{
    public function __invoke(): ContractTemplate
    {
        if ($existing = ContractTemplate::query()->orderBy('id')->first()) {
            return $existing;
        }

        return DB::transaction(function () {
            $template = ContractTemplate::create(['name' => 'Standard lease', 'is_default' => true, 'active' => true]);

            foreach (self::clauses() as $i => [$headingEn, $headingAr, $bodyEn, $bodyAr]) {
                $template->clauses()->create([
                    'position' => $i + 1, 'heading_en' => $headingEn, 'heading_ar' => $headingAr, 'body_en' => $bodyEn, 'body_ar' => $bodyAr,
                ]);
            }

            return $template->load('clauses');
        });
    }

    /** @return list<array{string, string, string, string}> */
    private static function clauses(): array
    {
        return [
            ['Parties', 'الأطراف',
                'This lease agreement No. {agreement_number} is made between {company_name} (the Landlord) and {customer_name}, ID {customer_id_number} (the Tenant).',
                'أُبرم عقد الإيجار هذا رقم {agreement_number} بين {company_name} (المؤجر) و{customer_name}، رقم الهوية {customer_id_number} (المستأجر).'],
            ['Premises', 'العين المؤجرة', '{units_table}', '{units_table}'],
            ['Term', 'مدة العقد',
                'The lease starts on {start_date} and ends on {end_date}.',
                'تبدأ مدة الإيجار في {start_date} وتنتهي في {end_date}.'],
            ['Rent', 'الإيجار',
                "The total monthly rent is BHD {total_monthly_rent}, payable {frequency} in advance.\n\nEach payment is due on the first day of its period. A grace period of {grace_days} days applies.",
                "إجمالي الإيجار الشهري {total_monthly_rent} دينار بحريني، يُدفع {frequency} مقدماً.\n\nيستحق كل دفعة في أول يوم من فترتها، مع مهلة سماح مدتها {grace_days} يوماً."],
            ['Security deposit', 'مبلغ التأمين',
                'The Tenant pays a security deposit of BHD {total_deposit}. It is refunded at the end of the lease after deducting any amounts due to the Landlord.',
                'يدفع المستأجر مبلغ تأمين قدره {total_deposit} دينار بحريني، ويُسترد عند انتهاء العقد بعد خصم أي مبالغ مستحقة للمؤجر.'],
            ['Use and care', 'الاستعمال والمحافظة',
                "The Tenant shall use the premises only for the agreed purpose and keep them in good condition.\n\nThe Tenant shall not sublet or assign the premises without the Landlord's written consent.",
                "يلتزم المستأجر باستعمال العين المؤجرة للغرض المتفق عليه فقط والمحافظة عليها بحالة جيدة.\n\nلا يجوز للمستأجر تأجير العين المؤجرة من الباطن أو التنازل عنها دون موافقة كتابية من المؤجر."],
            ['Utilities', 'الخدمات',
                'Electricity and water (EWA) charges are paid by the Tenant unless agreed otherwise in writing.',
                'يتحمل المستأجر رسوم الكهرباء والماء ما لم يُتفق على خلاف ذلك كتابياً.'],
            ['Notice', 'الإخطار',
                'A party that does not wish to renew must give the other party {notice_period_days} days\' written notice before the end date.',
                'على الطرف الذي لا يرغب في التجديد إخطار الطرف الآخر كتابياً قبل {notice_period_days} يوماً من تاريخ انتهاء العقد.'],
            ['Governing law', 'القانون الواجب التطبيق',
                'This agreement is governed by the laws of the Kingdom of Bahrain.',
                'يخضع هذا العقد لقوانين مملكة البحرين.'],
        ];
    }
}
