#!/usr/bin/env node
'use strict';

const VERSION = '1.0.0';
const PAGES = ['home', 'about', 'contact', 'privacy', 'docs', 'mcp', 'cli'];
const HELP = 'SMART CASSAVAS CLI\n\nCommands: docs [page], status, mcp-info\nOptions: --json, --base-url URL, --help, --version\nRead-only public documentation; no authentication or business actions.\n';

async function main() {
  const args = process.argv.slice(2);
  const positional = [];
  let baseUrl = 'https://quanly-toanha-dancu.vercel.app';
  let json = false;
  for (let index = 0; index < args.length; index++) {
    const arg = args[index];
    if (arg === '--json') json = true;
    else if (arg === '--base-url') {
      baseUrl = args[++index];
      if (!baseUrl || baseUrl.startsWith('-')) throw new Error('--base-url requires an HTTP(S) origin.');
    } else if (arg === '--help' || arg === '-h') positional.push('help');
    else if (arg === '--version' || arg === '-v') positional.push('version');
    else if (arg.startsWith('-')) throw new Error('Unknown option: ' + arg);
    else positional.push(arg);
  }
  const command = positional[0] || 'help';
  if (command === 'help') return process.stdout.write(HELP);
  if (command === 'version') return process.stdout.write(VERSION + '\n');
  if (!['docs', 'status', 'mcp-info'].includes(command)) throw new Error('Unknown command. Use --help.');
  if (positional.length > (command === 'docs' ? 2 : 1)) throw new Error('Too many command arguments.');
  const base = new URL(baseUrl);
  if (!['http:', 'https:'].includes(base.protocol) || base.username || base.password || base.search || base.hash || base.pathname !== '/') throw new Error('--base-url requires an HTTP(S) origin without credentials, path or query.');
  const page = positional[1] || 'home';
  if (command === 'docs' && !PAGES.includes(page)) throw new Error('Unknown public guide.');
  const endpoint = command === 'docs' ? '/api/v1/public/guides/' + page : command === 'status' ? '/api/v1/public/cli' : '/.well-known/mcp';
  const response = await fetch(new URL(endpoint, base), {headers: {Accept: 'application/json'}, signal: AbortSignal.timeout(8000)});
  if (!response.ok) throw new Error('HTTP ' + response.status + (response.headers.get('retry-after') ? '; Retry-After: ' + response.headers.get('retry-after') : ''));
  let data;
  try { data = await response.json(); } catch { throw new Error('The server did not return valid JSON.'); }
  if (!data || typeof data !== 'object' || Array.isArray(data)) throw new Error('Invalid JSON response object.');
  if (command === 'docs' && typeof data.markdown !== 'string') throw new Error('Invalid JSON guide response.');
  if (command === 'status') {
    if (!Array.isArray(data.pages)) throw new Error('Invalid JSON CLI discovery response.');
    data = {status: 'ok', url: base.origin, scope: 'public-documentation', version: data.version, pages: data.pages};
  }
  if (command === 'mcp-info' && (typeof data.url !== 'string' || typeof data.transport !== 'string')) throw new Error('Invalid JSON MCP discovery response.');
  process.stdout.write(command === 'docs' && !json ? data.markdown + '\n' : JSON.stringify(data, null, 2) + '\n');
}

main().catch((error) => {
  process.stderr.write((process.argv.includes('--json') ? JSON.stringify({error: error.message}) : error.message) + '\n');
  process.exitCode = 1;
});
