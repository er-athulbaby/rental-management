<html>
<head>
<style>
    body { font-family: dejavusans; font-size: 9.5pt; }
    h1 { font-size: 15pt; margin: 0 0 2mm; }
    table { width: 100%; border-collapse: collapse; }
    table.lines th, table.lines td { border-bottom: 0.2mm solid #bbb; padding: 1.5mm; text-align: left; }
    table.lines th { background: #f0f0f0; }
    .num { text-align: right; }
    .muted { color: #555; }
</style>
</head>
<body>
<table style="margin-bottom:5mm">
    <tr>
        <td style="width:60%">
            <strong>{{ $company->name_en }}</strong><br>
            <span class="muted">{{ $company->address_en }}</span>
            @if ($showTax)<br>TRN: {{ $company->trn }}@endif
            @if ($company->cr_number)<br>CR: {{ $company->cr_number }}@endif
        </td>
        <td style="width:40%; text-align:right">@if ($logo)<img src="{{ $logo }}" style="height:16mm">@endif</td>
    </tr>
</table>

<h1>{{ $title }}</h1>
<table style="margin-bottom:5mm">
    <tr>
        <td style="width:50%; vertical-align:top">
            <strong>{{ __('Bill to') }}</strong><br>{{ $invoice->customer->name_en }}
        </td>
        <td style="width:50%; vertical-align:top; text-align:right">
            {{ __('Number') }}: <strong>{{ $invoice->label() }}</strong><br>
            {{ __('Date') }}: {{ $invoice->issue_date->format('d/m/Y') }}<br>
            @if ($invoice->type->value !== 'credit_note'){{ __('Due') }}: {{ $invoice->due_date->format('d/m/Y') }}<br>@endif
            @if ($invoice->agreement){{ __('Agreement') }}: {{ $invoice->agreement->number }}<br>@endif
            @if ($invoice->relatedInvoice){{ __('Credits invoice') }}: {{ $invoice->relatedInvoice->number }}@endif
        </td>
    </tr>
</table>

<table class="lines">
    <tr>
        <th>{{ __('Description') }}</th>
        <th>{{ __('Unit') }}</th>
        <th class="num">{{ __('Net') }}</th>
        @if ($showTax)<th class="num">{{ __('VAT') }}</th>@endif
        <th class="num">{{ __('Total (BHD)') }}</th>
    </tr>
    @foreach ($invoice->lines as $line)
        <tr>
            <td>{{ $line->description }}@if ($line->period_start)<br><span class="muted">{{ $line->period_start->format('d/m/Y') }} – {{ $line->period_end?->format('d/m/Y') }}</span>@endif</td>
            <td>{{ $line->unit ? $line->unit->building->code.' / '.$line->unit->code : '' }}</td>
            <td class="num">{{ $line->net }}</td>
            @if ($showTax)<td class="num">{{ $line->tax_amount }} <span class="muted">({{ $line->tax_category->label() }}{{ (float) $line->tax_rate > 0 ? ' '.$line->tax_rate.'%' : '' }})</span></td>@endif
            <td class="num">{{ $line->total }}</td>
        </tr>
    @endforeach
</table>

<table style="margin-top:4mm">
    <tr>
        <td style="width:55%; vertical-align:top">
            @if ($showTax)
                <strong>{{ __('VAT summary') }}</strong>
                <table class="lines">
                    <tr><th>{{ __('Category') }}</th><th class="num">{{ __('Rate') }}</th><th class="num">{{ __('Net') }}</th><th class="num">{{ __('VAT') }}</th></tr>
                    @foreach ($breakdown as $row)
                        <tr><td>{{ $row['category'] }}</td><td class="num">{{ $row['rate'] }}%</td><td class="num">{{ $row['net'] }}</td><td class="num">{{ $row['tax'] }}</td></tr>
                    @endforeach
                </table>
            @endif
        </td>
        <td style="width:45%; vertical-align:top">
            <table>
                <tr><td>{{ __('Subtotal') }}</td><td class="num">{{ $invoice->subtotal }}</td></tr>
                @if ($showTax)<tr><td>{{ __('VAT') }}</td><td class="num">{{ $invoice->tax_total }}</td></tr>@endif
                <tr><td><strong>{{ __('Total (BHD)') }}</strong></td><td class="num"><strong>{{ $invoice->total }}</strong></td></tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
