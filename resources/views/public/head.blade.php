<meta name="keywords" content="SMART CASSAVAS, SMART CASSAVAS API, SMART CASSAVAS MCP Server, SMART CASSAVAS CLI, quản lý tòa nhà, quản lý cư dân, đăng ký tiện ích, báo cáo sự cố">
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
@php
    $siteUrl = config('public_content.url');
    $organization = ['@type' => 'Organization', '@id' => $siteUrl.'/#organization', 'name' => 'SMART CASSAVAS', 'url' => $siteUrl, 'sameAs' => ['https://github.com/UMISORA09/QUANLY_TOANHA-DANCU'], 'knowsAbout' => ['Building management', 'Resident portal', 'Amenity booking', 'Model Context Protocol']];
    $structuredData = ['@context' => 'https://schema.org', '@graph' => [$organization,
        ['@type' => 'SoftwareApplication', '@id' => $siteUrl.'/#application', 'name' => 'SMART CASSAVAS', 'description' => $description, 'url' => $siteUrl.'/home', 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web', 'keywords' => 'SMART CASSAVAS API, SMART CASSAVAS MCP Server, SMART CASSAVAS CLI, quản lý tòa nhà, quản lý cư dân', 'publisher' => ['@id' => $organization['@id']]],
        ['@type' => 'Service', 'name' => 'SMART CASSAVAS public documentation', 'serviceType' => 'Building management documentation and developer tools', 'provider' => ['@id' => $organization['@id']]],
    ]];
@endphp
<script type="application/ld+json">{!! json_encode($structuredData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
