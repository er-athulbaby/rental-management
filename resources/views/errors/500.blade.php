<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name') }}</title>
        <style>
            body { font-family: system-ui, sans-serif; display: grid; place-items: center; min-height: 100vh; margin: 0; background: #fafafa; color: #27272a; }
            main { text-align: center; padding: 16px; }
        </style>
    </head>
    <body>
        <main>
            <h1>Something went wrong. Please try again.</h1>
            <p><a href="{{ url('/') }}">Back to the start page</a></p>
        </main>
    </body>
</html>
