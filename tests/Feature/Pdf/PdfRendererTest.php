<?php

use App\Pdf\PdfRenderer;
use Illuminate\Support\Facades\Storage;
use Mpdf\MpdfException;

function spikeData(): array
{
    $clauses = [
        [['This Agreement is made on 28/09/2026 between Example Properties W.L.L. (the Landlord) and the Tenant named in Schedule 1.'],
            ['حُرِّر هذا العقد بتاريخ 28/09/2026 بين شركة مثال للعقارات ذ.م.م (المؤجر) والمستأجر المذكور في الجدول رقم 1.']],
        [['The lease term starts on 01/10/2026 and ends on 30/09/2027 (12 months).'],
            ['تبدأ مدة الإيجار في 01/10/2026 وتنتهي في 30/09/2027 (12 شهراً).']],
        [['The monthly rent is BHD 350.500, payable in advance on the 1st day of each month.'],
            ['الإيجار الشهري 350.500 د.ب (BHD 350.500)، يُدفع مقدماً في اليوم الأول من كل شهر.']],
        [['The Tenant shall pay a security deposit of BHD 701.000 on signing, refundable within 30 days after move-out.'],
            ['يدفع المستأجر تأميناً قدره 701.000 دينار بحريني عند التوقيع، ويُرد خلال 30 يوماً من تاريخ الإخلاء.']],
        [['Units: Flat 12A (Building 7, Road 2803, Block 428, Seef) and Parking P-15, as listed on invoice INV-2026-000042.'],
            ['الوحدات: الشقة 12A (المبنى 7، الطريق 2803، المجمع 428، السيف) وموقف السيارة P-15، كما في الفاتورة رقم INV-2026-000042.']],
        [['Either party may terminate by giving 60 days\' written notice, effective no earlier than 2027-03-31.'],
            ['يجوز لأي من الطرفين إنهاء العقد بإخطار كتابي مدته 60 يوماً، على ألا يسري الإنهاء قبل 2027-03-31.']],
        [['VAT at 10% is charged where applicable; the rent of 350.500 excludes VAT.'],
            ['تُفرض ضريبة القيمة المضافة بنسبة 10% حيثما ينطبق ذلك، والإيجار البالغ 350.500 لا يشمل الضريبة.']],
    ];

    // A multi-paragraph clause (one row per paragraph) that pushes the document onto page 2.
    $en = [];
    $ar = [];
    for ($i = 1; $i <= 9; $i++) {
        $en[] = "({$i}) The Tenant shall keep the unit clean and in good repair, report any defect within 3 days, and shall not make alterations without the Landlord's written consent; repairs above BHD 25.000 need approval.";
        $ar[] = "({$i}) يلتزم المستأجر بالمحافظة على نظافة الوحدة وصيانتها، والإبلاغ عن أي عطل خلال 3 أيام، ولا يجوز له إجراء أي تعديلات دون موافقة المؤجر الكتابية، وتحتاج الإصلاحات التي تزيد على 25.000 د.ب إلى موافقة.";
    }
    $clauses[] = [$en, $ar];
    $clauses[] = [['This Agreement is governed by the laws of the Kingdom of Bahrain.'], ['يخضع هذا العقد لقوانين مملكة البحرين.']];

    return [
        'agreementNo' => 'AGR-2026-000001',
        'verifyUrl' => 'https://rms.example.bh/v/'.str_repeat('Ab3x', 8),
        'units' => [
            ['unit' => 'Flat 12A', 'building' => 'Building 7, Seef', 'from' => '01/10/2026', 'to' => '30/09/2027', 'rent' => '350.500'],
            ['unit' => 'Parking P-15', 'building' => 'Building 7, Seef', 'from' => '01/10/2026', 'to' => '30/09/2027', 'rent' => '15.000'],
        ],
        'clauses' => array_map(fn ($pair) => ['en' => $pair[0], 'ar' => $pair[1]], $clauses),
    ];
}

function spikeChrome(): array
{
    return [
        'header' => '<table width="100%" style="font-size:8pt;border-bottom:0.2mm solid #999"><tr>'
            .'<td>Example Properties W.L.L. — AGR-2026-000001</td>'
            .'<td style="text-align:right;font-family: arabic" dir="rtl">شركة مثال للعقارات ذ.م.م</td></tr></table>',
        'footer' => '<table width="100%" style="font-size:8pt"><tr>'
            .'<td>Page {PAGENO} of {nbpg}</td>'
            .'<td style="text-align:right;font-family: arabic" dir="rtl">صفحة {PAGENO} من {nbpg}</td></tr></table>',
        'watermark' => 'DRAFT',
    ];
}

it('renders the two-page bilingual contract spike quickly', function () {
    $start = hrtime(true);

    $pdf = app(PdfRenderer::class)->render('pdf.spike', spikeData(), spikeChrome());

    $ms = (hrtime(true) - $start) / 1e6;
    $pages = preg_match_all('#/Type /Page\b(?!s)#', $pdf);

    Storage::disk('local')->put('pdf-spike/spike.pdf', $pdf); // for the manual check in Step 6

    expect($pdf)->toStartWith('%PDF-')
        ->and($pages)->toBe(2)
        ->and($ms / $pages)->toBeLessThan(3000); // spec §9.2: under 3 s per page
});

it('refuses remote images referenced from HTML', function () {
    $mpdf = app(PdfRenderer::class)->make();
    $mpdf->debug = true; // surface the refusal instead of silently drawing a broken image

    expect(fn () => $mpdf->WriteHTML('<img src="https://example.com/logo.png">'))
        ->toThrow(MpdfException::class, 'invalid stream');
});
