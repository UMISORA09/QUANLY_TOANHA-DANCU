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

const htmlContent = `<!DOCTYPE html>
<html lang="vi">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
        <meta name="description" content="SMART CASSAVAS - Nền tảng quản lý tòa nhà thông minh, kết nối cư dân, ban quản lý, lễ tân và admin trong một hệ thống rõ ràng và an toàn.">
        <title>SMART CASSAVAS - Nền tảng quản lý tòa nhà</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap&subset=vietnamese" rel="stylesheet">
        <link rel="stylesheet" href="/build/${cssFile}">
    </head>
    <body class="antialiased bg-[#FAFAFA] text-[#171717] min-h-screen">
        <div id="app"></div>
        <script type="module" src="/build/${jsFile}"></script>
    </body>
</html>
`;

fs.writeFileSync(indexHtmlPath, htmlContent, 'utf8');
console.log(`Synced public/index.html with: CSS=/build/${cssFile}, JS=/build/${jsFile}`);
