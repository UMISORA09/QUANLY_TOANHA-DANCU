<meta name="description" content="{{ $description }}">
<title>{{ $title }}</title>
<link rel="canonical" href="{{ $canonical }}">
<link rel="alternate" type="text/markdown" href="{{ $canonical }}.md">
<link rel="describedby" href="{{ config('public_content.url') }}/llms.txt">
<meta property="og:title" content="{{ $title }}">
<meta property="og:description" content="{{ $description }}">
<meta property="og:type" content="website">
<meta property="og:url" content="{{ $canonical }}">
<meta property="og:image" content="{{ config('public_content.url') }}/social-card.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="SMART CASSAVAS — Nền tảng quản lý tòa nhà">
<meta name="twitter:card" content="summary_large_image">
<script type="application/ld+json">{!! json_encode(['@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => 'SMART CASSAVAS', 'description' => $description, 'url' => config('public_content.url').'/home', 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web', 'keywords' => ['quản lý tòa nhà', 'quản lý cư dân', 'đăng ký tiện ích', 'báo cáo sự cố'], 'publisher' => ['@type' => 'Organization', 'name' => 'SMART CASSAVAS', 'url' => config('public_content.url').'/about', 'sameAs' => ['https://github.com/UMISORA09/QUANLY_TOANHA-DANCU']]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
