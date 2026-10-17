<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subjectLine }}</title>
</head>
<body style="margin: 0; padding: 24px; background: #ffffff; color: #18181b; font-family: Arial, Helvetica, sans-serif; font-size: 15px; line-height: 1.6;">
    <div dir="ltr" lang="en" style="text-align: left;">
        <p>{{ __('Dear :name,', ['name' => $nameEn]) }}</p>
        @foreach ($sections as $section)
            <p>{{ $section['intro_en'] }}</p>
            <ul>
                @foreach ($section['lines'] as $line)
                    <li>{{ $line['en'] }}</li>
                @endforeach
            </ul>
        @endforeach
        <p>{{ __('Thank you.') }}</p>
    </div>

    <hr style="border: 0; border-top: 1px solid #e4e4e7; margin: 24px 0;">

    <div dir="rtl" lang="ar" style="text-align: right;">
        <p>عزيزي المستأجر {{ $nameAr }}،</p>
        @foreach ($sections as $section)
            <p>{{ $section['intro_ar'] }}</p>
            <ul>
                @foreach ($section['lines'] as $line)
                    <li>{{ $line['ar'] }}</li>
                @endforeach
            </ul>
        @endforeach
        <p>شكراً لكم.</p>
    </div>

    <hr style="border: 0; border-top: 1px solid #e4e4e7; margin: 24px 0;">

    <div style="font-size: 13px; color: #52525b;">
        <p style="margin: 0;">{{ $company['name_en'] }}</p>
        @if (filled($company['phone']))<p style="margin: 0;">{{ $company['phone'] }}</p>@endif
        @if (filled($company['email']))<p style="margin: 0;">{{ $company['email'] }}</p>@endif
        @if (filled($company['address_en']))<p style="margin: 0;">{{ $company['address_en'] }}</p>@endif
        @if (filled($company['name_ar']) || filled($company['address_ar']))
            <p dir="rtl" lang="ar" style="margin: 8px 0 0; text-align: right;">{{ $company['name_ar'] }}@if (filled($company['name_ar']) && filled($company['address_ar'])) · @endif{{ $company['address_ar'] }}</p>
        @endif
    </div>
</body>
</html>
