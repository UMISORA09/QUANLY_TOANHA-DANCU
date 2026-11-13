<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('public.head')
    @vite('resources/css/app.css')
</head>
<body class="bg-slate-50 text-slate-900">
    <header class="mx-auto max-w-4xl px-6 py-6">
        <a href="{{ route('public.home') }}" class="font-bold">SMART CASSAVAS</a>
    </header>
    <main class="mx-auto max-w-4xl rounded-3xl border border-slate-200 bg-white p-6 sm:p-10">
        <article class="public-guide">{!! $html !!}</article>
    </main>
    <footer class="mx-auto max-w-4xl px-6 py-8 text-sm">
        <nav aria-label="Thông tin công khai" class="flex flex-wrap gap-4">
            @foreach (['about' => 'Giới thiệu', 'contact' => 'Liên hệ', 'privacy' => 'Quyền riêng tư', 'docs' => 'Tài liệu'] as $slug => $label)
                <a href="{{ route('public.page', ['page' => $slug]) }}">{{ $label }}</a>
            @endforeach
        </nav>
    </footer>
</body>
</html>
