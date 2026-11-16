import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const rootDir = path.resolve(__dirname, '..');

const manifestPath = path.join(rootDir, 'public', 'build', 'manifest.json');
const indexHtmlPath = path.join(rootDir, 'public', 'index.html');

if (!fs.existsSync(manifestPath)) {
  console.error('manifest.json not found at ' + manifestPath);
  process.exit(1);
}

const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));

const cssEntry = manifest['resources/css/app.css'];
const jsEntry = manifest['resources/js/app.tsx'];

if (!cssEntry || !jsEntry) {
  console.error('CSS or JS entry not found in manifest.json');
  process.exit(1);
}

const cssFile = cssEntry.file;
const jsFile = jsEntry.file;
const cssFiles = [...new Set([cssFile, ...(jsEntry.css || [])])];
const canonical = 'https://quanly-toanha-dancu.vercel.app/home';
const keywords = ["SMART CASSAVAS","SMART CASSAVAS API","SMART CASSAVAS MCP Server","SMART CASSAVAS CLI","quản lý tòa nhà","quản lý cư dân","đăng ký tiện ích","báo cáo sự cố"];
const siteUrl = 'https://quanly-toanha-dancu.vercel.app';
const organization = { '@type': 'Organization', '@id': siteUrl + '/#organization', name: 'SMART CASSAVAS', url: siteUrl, sameAs: ['https://github.com/UMISORA09/QUANLY_TOANHA-DANCU'], knowsAbout: ['Building management', 'Resident portal', 'Amenity booking', 'Model Context Protocol'] };
const structuredData = { '@context': 'https://schema.org', '@graph': [organization, { '@type': 'SoftwareApplication', '@id': siteUrl + '/#application', name: 'SMART CASSAVAS', url: canonical, applicationCategory: 'BusinessApplication', operatingSystem: 'Web', keywords: keywords.join(', '), publisher: { '@id': organization['@id'] } }, { '@type': 'Service', name: 'SMART CASSAVAS public documentation', serviceType: 'Building management documentation and developer tools', provider: { '@id': organization['@id'] } }] };
const escapeHtml = (value) => value.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
const homepage = fs.readFileSync(path.join(rootDir, 'resources/views/public/home.md'), 'utf8');
const fallback = homepage.trim().split(/\r?\n\s*\r?\n/).map((block) => {
  const heading = block.match(/^(#{1,2}) (.*)$/);
  return heading ? `<h${heading[1].length}>${escapeHtml(heading[2])}</h${heading[1].length}>` : `<p>${escapeHtml(block.replace(/\[([^\]]+)\]\([^)]+\)/g, '$1'))}</p>`;
}).join('\n');

const htmlContent = `<!DOCTYPE html>
<html lang="vi">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
        <meta name="description" content="SMART CASSAVAS - Nền tảng quản lý tòa nhà thông minh, kết nối cư dân, ban quản lý, lễ tân và admin trong một hệ thống rõ ràng và an toàn.">
        <meta name="keywords" content="${escapeHtml(keywords.join(', '))}">
        <title>SMART CASSAVAS - Nền tảng quản lý tòa nhà</title>
        <link rel="canonical" href="${canonical}">
        <link rel="alternate" type="text/markdown" href="${canonical}.md">
        <link rel="describedby" href="/llms.txt">
        <meta property="og:type" content="website">
        <meta property="og:url" content="${canonical}">
        <meta property="og:image" content="https://quanly-toanha-dancu.vercel.app/social-card.png">
        <script type="application/ld+json">${JSON.stringify(structuredData)}</script>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap&subset=vietnamese" rel="stylesheet">
        ${cssFiles.map((file) => `<link rel="stylesheet" href="/build/${file}">`).join('\n        ')}
    </head>
    <body class="antialiased bg-[#FAFAFA] text-[#171717] min-h-screen">
        <div id="app"><main class="public-guide mx-auto max-w-4xl px-6 py-12">${fallback}<nav><a href="/about">Giới thiệu</a> · <a href="/contact">Liên hệ</a> · <a href="/privacy">Quyền riêng tư</a> · <a href="/docs">SMART CASSAVAS API</a> · <a href="/mcp">SMART CASSAVAS MCP Server</a> · <a href="/cli">SMART CASSAVAS CLI</a> · <a href="https://github.com/UMISORA09/QUANLY_TOANHA-DANCU">GitHub dự án</a></nav></main></div>
        <script type="module" src="/build/${jsFile}"></script>
    </body>
</html>
`;

fs.writeFileSync(indexHtmlPath, htmlContent, 'utf8');
console.log(`Synced public/index.html with: CSS=/build/${cssFile}, JS=/build/${jsFile}`);
