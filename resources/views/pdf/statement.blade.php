@php($money = fn (int $fils) => App\Support\Fils::toDecimal($fils))
<html>
<head>
<style>
    body { font-family: dejavusans; font-size: 9pt; }
    h1 { font-size: 14pt; margin: 0 0 2mm; }
    h2 { font-size: 11pt; margin: 5mm 0 1mm; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border-bottom: 0.2mm solid #bbb; padding: 1.2mm; text-align: left; }
    table td.num, table th.num, .num { text-align: right; }
</style>
</head>
<body>
<strong>{{ $company->name_en }}</strong><br>{{ $company->address_en }}
<h1>Statement of account</h1>
<p>{{ $customer->name_en }} · {{ \Carbon\CarbonImmutable::parse($from)->format('d/m/Y') }} – {{ \Carbon\CarbonImmutable::parse($to)->format('d/m/Y') }}</p>

<table>
    <tr><th>Date</th><th>Entry</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance (BHD)</th></tr>
    <tr><td></td><td>Opening balance</td><td></td><td></td><td class="num">{{ $money($receivables['opening']) }}</td></tr>
    @foreach ($receivables['rows'] as $row)
        <tr>
            <td>{{ \Carbon\CarbonImmutable::parse($row['date'])->format('d/m/Y') }}</td>
            <td>{{ $row['kind'] }} {{ $row['reference'] }}</td>
            <td class="num">{{ $row['debit'] ? $money($row['debit']) : '' }}</td>
            <td class="num">{{ $row['credit'] ? $money($row['credit']) : '' }}</td>
            <td class="num">{{ $money($row['balance']) }}</td>
        </tr>
    @endforeach
    <tr><td></td><td><strong>Closing balance</strong></td><td></td><td></td><td class="num"><strong>{{ $money($receivables['closing']) }}</strong></td></tr>
</table>

@foreach ($deposits as $deposit)
    <h2>Deposit — {{ $deposit['unit'] }}</h2>
    <table>
        <tr><th>Date</th><th>Movement</th><th class="num">Amount</th><th class="num">Held (BHD)</th></tr>
        @foreach ($deposit['rows'] as $row)
            <tr><td>{{ \Carbon\CarbonImmutable::parse($row['date'])->format('d/m/Y') }}</td><td>{{ $row['kind'] }}</td><td class="num">{{ $money($row['amount']) }}</td><td class="num">{{ $money($row['held']) }}</td></tr>
        @endforeach
    </table>
@endforeach
</body>
</html>
