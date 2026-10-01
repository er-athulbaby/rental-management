<html>
<head>
<style>
    body { font-family: dejavusans; font-size: 10pt; }
    h1 { font-size: 15pt; margin: 0 0 3mm; }
    table { width: 100%; border-collapse: collapse; }
    table.lines td, table.lines th { border-bottom: 0.2mm solid #bbb; padding: 1.5mm; text-align: left; }
    .num { text-align: right; }
    .muted { color: #555; }
</style>
</head>
<body>
<table style="margin-bottom:5mm">
    <tr>
        <td style="width:60%"><strong>{{ $company->name_en }}</strong><br><span class="muted">{{ $company->address_en }}</span></td>
        <td style="width:40%; text-align:right">@if ($logo)<img src="{{ $logo }}" style="height:16mm">@endif</td>
    </tr>
</table>

<h1>{{ __('Receipt') }} {{ $payment->number }}</h1>
<p>
    {{ __('Received from') }} <strong>{{ $payment->customer->name_en }}</strong>
    {{ __('the sum of') }} <strong>BHD {{ $payment->amount }}</strong>
    {{ __('on :d by :m', ['d' => $payment->received_on->format('d/m/Y'), 'm' => $payment->method->label()]) }}@if ($payment->reference) ({{ $payment->reference }})@endif.
</p>

<table class="lines">
    <tr><th>{{ __('Applied to') }}</th><th class="num">{{ __('Amount (BHD)') }}</th></tr>
    @foreach ($byInvoice as $number => $amount)
        <tr><td>{{ __('Invoice') }} {{ $number }}</td><td class="num">{{ $amount }}</td></tr>
    @endforeach
    @if ((float) $credit > 0)
        <tr><td>{{ __('Held as credit on account') }}</td><td class="num">{{ $credit }}</td></tr>
    @endif
</table>
</body>
</html>
