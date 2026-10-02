<html>
<head>
<style>
    body { font-family: dejavusans; font-size: 10pt; }
    h1 { font-size: 15pt; margin: 0 0 3mm; }
    table { width: 100%; border-collapse: collapse; }
    table.lines td, table.lines th { border-bottom: 0.2mm solid #bbb; padding: 1.5mm; text-align: left; }
    table.lines td.num, table.lines th.num, .num { text-align: right; }
    .muted { color: #555; }
    tr.total td { font-weight: bold; }
</style>
</head>
<body>
<table style="margin-bottom:5mm">
    <tr>
        <td style="width:60%"><strong>{{ $company->name_en }}</strong><br><span class="muted">{{ $company->address_en }}</span></td>
        <td style="width:40%; text-align:right">@if ($logo)<img src="{{ $logo }}" style="height:16mm">@endif</td>
    </tr>
</table>

<h1>{{ __('Owner statement') }} {{ $statement->number }}</h1>
<p>
    <strong>{{ $statement->contract->owner->name_en }}</strong><br>
    {{ __('Contract') }} {{ $statement->contract->number }} — {{ $statement->contract->building->name }}<br>
    {{ __('Period') }} {{ $statement->period_start->format('d/m/Y') }} – {{ $statement->period_end->format('d/m/Y') }}
</p>

<table class="lines">
    <tr><th>{{ __('Date') }}</th><th>{{ __('Entry') }}</th><th>{{ __('Reference') }}</th><th class="num">{{ __('Amount (BHD)') }}</th><th class="num">{{ __('Balance') }}</th></tr>
    <tr><td></td><td>{{ __('Opening balance') }}</td><td></td><td class="num"></td><td class="num">{{ \App\Support\Fils::toDecimal($figures['opening']) }}</td></tr>
    @foreach ($rows as $r)
        <tr>
            <td>{{ \Carbon\CarbonImmutable::parse($r['date'])->format('d/m/Y') }}</td>
            <td>{{ \App\Billing\OwnerLedger::kindLabel($r['kind']) }}</td>
            <td>{{ $r['reference'] }}</td>
            <td class="num">{{ \App\Support\Fils::toDecimal($r['amount']) }}</td>
            <td class="num">{{ \App\Support\Fils::toDecimal($r['balance']) }}</td>
        </tr>
    @endforeach
    <tr><td></td><td>{{ __('Management fee') }}</td><td class="muted">{{ __('on :b BHD', ['b' => $statement->fee_base]) }}</td><td class="num">-{{ $statement->fee_amount }}</td><td class="num"></td></tr>
    @if ((float) $statement->fee_tax > 0)
        <tr><td></td><td>{{ __('VAT on the fee') }}</td><td></td><td class="num">-{{ $statement->fee_tax }}</td><td class="num"></td></tr>
    @endif
    <tr class="total"><td></td><td>{{ __('Closing balance') }}</td><td></td><td class="num"></td><td class="num">{{ \App\Support\Fils::toDecimal($figures['closing']) }}</td></tr>
</table>

@if ($heldByCompany !== null)
    <p class="muted">{{ __('Deposits held by the company on your behalf: :a BHD', ['a' => \App\Support\Fils::toDecimal($heldByCompany)]) }}</p>
@endif
</body>
</html>
