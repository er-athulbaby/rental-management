<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Work order :number', ['number' => $workOrder->number]) }}</title>
    <style>
        body { margin: 24px; color: #18181b; background: #ffffff; font-family: Arial, Helvetica, sans-serif; font-size: 15px; line-height: 1.5; }
        table { width: 100%; max-width: 720px; border-collapse: collapse; }
        th, td { padding: 8px; border-bottom: 1px solid #e4e4e7; text-align: start; vertical-align: top; }
        th { width: 30%; color: #52525b; font-weight: normal; }
        td { white-space: pre-line; }
        .no-print { margin-bottom: 16px; }
        @media print { .no-print { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <div class="no-print"><button type="button" onclick="window.print()">{{ __('Print') }}</button></div>
    <h1 style="margin: 0 0 4px; font-size: 20px;">{{ $company->name_en }}</h1>
    <h2 style="margin: 0 0 16px; font-size: 16px;">{{ __('Work order :number', ['number' => $workOrder->number]) }}</h2>
    <table>
        @foreach ($lines as [$label, $value])
            <tr><th scope="row">{{ $label }}</th><td>{{ $value }}</td></tr>
        @endforeach
    </table>
</body>
</html>
