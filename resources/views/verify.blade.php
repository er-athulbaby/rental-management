<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('Agreement verification') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; padding: 24px 16px; background: #fafafa; color: #18181b; }
        main { max-width: 420px; margin: 0 auto; background: #fff; border: 1px solid #e4e4e7; border-radius: 12px; padding: 24px; }
        dt { color: #71717a; font-size: 14px; margin-top: 12px; }
        dd { margin: 2px 0 0; font-size: 18px; }
    </style>
</head>
<body>
    <main>
        <h1 style="font-size:20px; margin:0 0 8px">{{ __('Agreement verification') }}</h1>
        <p style="margin:0; color:#52525b">{{ __('This lease agreement is on record.') }}</p>
        <dl>
            <dt>{{ __('Agreement number') }}</dt><dd>{{ $number }}</dd>
            <dt>{{ __('Status') }}</dt><dd>{{ $status }}</dd>
            <dt>{{ __('Start date') }}</dt><dd>{{ $start }}</dd>
            <dt>{{ __('End date') }}</dt><dd>{{ $end }}</dd>
        </dl>
    </main>
</body>
</html>
