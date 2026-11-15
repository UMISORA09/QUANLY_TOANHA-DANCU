<!DOCTYPE html>
<html lang="{{ isset($canonical) ? 'vi' : str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
        @if (isset($canonical))
            @include('public.head')
        @else
            <meta name="robots" content="noindex, nofollow">
            <title>SMART CASSAVAS - Nền tảng quản lý tòa nhà</title>
        @endif

        <link rel="dns-prefetch" href="//fonts.googleapis.com">
        <link rel="dns-prefetch" href="//fonts.gstatic.com">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
        <noscript>
            <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        </noscript>

        <!-- Vite Assets -->
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    </head>
    <body class="antialiased bg-[#FAFAFA] text-[#171717] min-h-screen">
        <div id="app">@isset($html)<main class="public-guide mx-auto max-w-4xl px-6 py-12">{!! $html !!}</main>@endisset</div>
    </body>
</html>
