<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="SMART CASSAVAS - Nền tảng quản lý tòa nhà thông minh, kết nối cư dân, ban quản lý, lễ tân và admin trong một hệ thống rõ ràng và an toàn.">

        <title>{{ config('app.name', 'SMART CASSAVAS') }} - Nền tảng quản lý tòa nhà</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

        <!-- Vite Assets -->
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    </head>
    <body class="antialiased bg-[#FAFAFA] text-[#171717] min-h-screen">
        <div id="app"></div>
    </body>
</html>
