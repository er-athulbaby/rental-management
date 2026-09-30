<html>
<head>
<style>
    body { font-family: dejavusans; font-size: 9.5pt; }
    .ar { font-family: arabic; font-size: 11pt; direction: rtl; text-align: justify; }
    h1 { font-size: 13pt; text-align: center; margin: 0 0 3mm; }
    table.clauses { width: 100%; border-collapse: collapse; }
    table.clauses td { width: 50%; vertical-align: top; padding: 1.2mm 2mm; }
    tr.heading td { font-weight: bold; padding-top: 3mm; border-bottom: 0.2mm solid #999; }
    div.hd { font-weight: bold; padding: 2mm 0mm 1mm 0mm; margin-bottom: 1mm; border-bottom: 0.2mm solid #999; }
    table.units { width: 100%; border-collapse: collapse; margin: 1mm 0; }
    table.units th, table.units td { border: 0.2mm solid #666; padding: 1mm 1.5mm; font-size: 8.5pt; }
    table.units th .ar { font-size: 9pt; }
    .num { text-align: right; }
</style>
</head>
<body>
<table style="width:100%; margin-bottom:3mm">
    <tr>
        <td style="width:40%">{{ $companyEn }}</td>
        <td style="width:20%; text-align:center">@if ($logo)<img src="{{ $logo }}" style="height:14mm">@endif</td>
        <td style="width:40%; text-align:right" class="ar" dir="rtl">{{ $companyAr }}</td>
    </tr>
</table>

<h1>Lease Agreement {{ $number }} &nbsp;|&nbsp; <span class="ar">عقد إيجار رقم {{ $number }}</span></h1>
{{-- One row per paragraph (spec §9.2): mPDF never splits a row, and a tall row shrinks the whole table.
     The heading sits inside the first paragraph's row so it can never be stranded at a page bottom. --}}
<table class="clauses">
    @foreach ($clauses as $clause)
        @php
            $headEn = $clause['position'].'. '.$clause['heading_en'];
            $headAr = $clause['position'].'. '.$clause['heading_ar'];
        @endphp
        @if ($clause['units'])
            <tr>
                <td colspan="2">
                    <table style="width:100%; border-collapse:collapse">
                        <tr class="heading">
                            <td>{{ $headEn }}</td>
                            <td class="ar" dir="rtl" lang="ar">{{ $headAr }}</td>
                        </tr>
                    </table>
                    <table class="units">
                        <tr>
                            <th>Building <span class="ar">المبنى</span></th><th>Unit <span class="ar">الوحدة</span></th>
                            <th>From <span class="ar">من</span></th><th>To <span class="ar">إلى</span></th>
                            <th>Rent/month <span class="ar">الإيجار الشهري</span></th><th>Other/month <span class="ar">رسوم أخرى</span></th>
                            <th>Deposit <span class="ar">التأمين</span></th>
                        </tr>
                        @foreach ($units as $u)
                            <tr>
                                <td>{{ $u['building'] }}</td><td>{{ $u['unit'] }}</td><td>{{ $u['from'] }}</td><td>{{ $u['to'] }}</td>
                                <td class="num">{{ $u['rent'] }}</td><td class="num">{{ $u['service'] }}</td><td class="num">{{ $u['deposit'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                </td>
            </tr>
        @elseif (count($clause['paragraphs']) === 0)
            <tr class="heading">
                <td>{{ $headEn }}</td>
                <td class="ar" dir="rtl" lang="ar">{{ $headAr }}</td>
            </tr>
        @else
            @foreach ($clause['paragraphs'] as [$en, $ar])
                <tr>
                    <td>@if ($loop->first)<div class="hd">{{ $headEn }}</div>@endif{{ $en }}</td>
                    <td class="ar" dir="rtl" lang="ar">@if ($loop->first)<div class="hd" dir="rtl">{{ $headAr }}</div><div dir="rtl">{{ $ar }}</div>@else{{ $ar }}@endif</td>
                </tr>
            @endforeach
        @endif
    @endforeach
</table>

<table style="width:100%; margin-top:10mm">
    <tr>
        <td style="width:50%">Landlord / <span class="ar">المؤجر</span><br><br>______________________</td>
        <td style="width:50%">Tenant / <span class="ar">المستأجر</span><br><br>______________________</td>
    </tr>
</table>

@if ($verifyUrl)
    <table style="width:100%; margin-top:6mm">
        <tr>
            <td style="width:70%; vertical-align:bottom; font-size:8pt">Scan to verify this agreement:<br>{{ $verifyUrl }}</td>
            <td style="width:30%; text-align:right">
                {{-- Vector QR drawn by mpdf/qrcode: no image fetch. --}}
                <barcode code="{{ $verifyUrl }}" type="QR" size="0.9" error="M" disableborder="1" />
            </td>
        </tr>
    </table>
@endif
</body>
</html>
