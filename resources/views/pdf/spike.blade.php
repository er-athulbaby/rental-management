<html>
<head>
<style>
    body { font-family: dejavusans; font-size: 9.5pt; }
    .ar { font-family: arabic; font-size: 11pt; direction: rtl; text-align: justify; }
    h1 { font-size: 13pt; text-align: center; margin: 0 0 2mm; }
    table.clauses { width: 100%; border-collapse: collapse; }
    table.clauses td { width: 50%; vertical-align: top; padding: 1.5mm 2mm; border-bottom: 0.2mm solid #999; }
    table.units { width: 100%; border-collapse: collapse; margin: 3mm 0; }
    table.units th, table.units td { border: 0.2mm solid #666; padding: 1mm 2mm; }
    .num { font-weight: bold; }
</style>
</head>
<body>
<h1>Lease Agreement {{ $agreementNo }} &nbsp;|&nbsp; <span class="ar">عقد إيجار رقم {{ $agreementNo }}</span></h1>

<table class="units">
    <tr><th>Unit</th><th>Building</th><th>From</th><th>To</th><th>Monthly rent (BHD)</th></tr>
    @foreach ($units as $unit)
        <tr><td>{{ $unit['unit'] }}</td><td>{{ $unit['building'] }}</td><td>{{ $unit['from'] }}</td><td>{{ $unit['to'] }}</td><td style="text-align:right">{{ $unit['rent'] }}</td></tr>
    @endforeach
</table>

{{-- One row per clause paragraph (spec §9.2): mPDF never splits a row, and a tall row shrinks the whole table. --}}
<table class="clauses">
    @foreach ($clauses as $n => $clause)
        @foreach ($clause['en'] as $i => $paragraph)
            <tr>
                <td>@if ($i === 0)<span class="num">{{ $n + 1 }}.</span> @endif{{ $paragraph }}</td>
                <td class="ar" dir="rtl" lang="ar">@if ($i === 0)<span class="num">{{ $n + 1 }}.</span> @endif{{ $clause['ar'][$i] ?? '' }}</td>
            </tr>
        @endforeach
    @endforeach
</table>

<table style="width:100%; margin-top:6mm">
    <tr>
        <td style="width:70%; vertical-align:bottom">Scan to verify this agreement:<br>{{ $verifyUrl }}</td>
        <td style="width:30%; text-align:right">
            {{-- Vector QR drawn by mpdf/qrcode: no image fetch. --}}
            <barcode code="{{ $verifyUrl }}" type="QR" size="0.9" error="M" disableborder="1" />
        </td>
    </tr>
</table>
</body>
</html>
