// Shared helpers for the local integration and browser tests.
// Everything targets the disposable site built by tests/e2e/setup-wp.sh.
import http from 'node:http';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
export const DIR = process.env.WPDA_DIR || path.join(ROOT, '.wp-test');
export const PORT = Number(process.env.WPDA_PORT || 8890);
export const PRIMARY = 'primary.test';
export const ALIASES = ['alias-one.test', 'alias-two.test', 'www.alias-two.test'];
export const OWN = [PRIMARY, 'www.primary.test', ...ALIASES];

/** Runs WP-CLI against the test site and returns trimmed stdout. */
export function wp(...args) {
  return execFileSync('php', ['-d', 'memory_limit=512M', path.join(DIR, 'wp-cli.phar'), `--path=${path.join(DIR, 'wordpress')}`, `--url=http://${PRIMARY}`, ...args], {
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  }).trim();
}

export function setSettings(settings) {
  const value = { enabled: true, aliases: ALIASES, diagnostics: false, ...settings };
  wp('option', 'update', 'wp_domain_alias_settings', JSON.stringify(value), '--format=json');
}

/**
 * HTTP request to the local server with an explicit Host header.
 * Returns { status, headers, body, cookies }.
 */
export function request(host, pathname, { method = 'GET', headers = {}, body, port = PORT } = {}) {
  return new Promise((resolve, reject) => {
    const req = http.request({ host: '127.0.0.1', port, method, path: pathname, headers: { Host: host, ...headers }, timeout: 30000 }, res => {
      const chunks = [];
      res.on('data', c => chunks.push(c));
      res.on('end', () => resolve({
        status: res.statusCode,
        headers: res.headers,
        body: Buffer.concat(chunks).toString('utf8'),
        cookies: res.headers['set-cookie'] || [],
      }));
    });
    req.on('timeout', () => req.destroy(new Error(`timeout ${host}${pathname}`)));
    req.on('error', reject);
    if (body) req.write(body);
    req.end();
  });
}

const decode = s => String(s)
  .replace(/&#0*38;|&amp;|&#x0*26;/gi, '&')
  .replace(/&quot;/g, '"')
  .replace(/&#0*39;|&apos;/g, "'");

/** Minimal tag/attribute parser (ported from the original regression suite). */
export function tags(html) {
  const clean = html.replace(/<!--[\s\S]*?-->/g, '').replace(/<(script|style)\b[^>]*>[\s\S]*?<\/\1>/gi, m => m.replace(/>[\s\S]*<\//, '></'));
  return [...clean.matchAll(/<([a-z][\w:-]*)\b((?:"[^"]*"|'[^']*'|[^'">])*)>/gi)].map(m => {
    const attrs = Object.create(null);
    for (const a of m[2].matchAll(/([^\s=/>]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/g)) {
      attrs[a[1].toLowerCase()] = decode(a[2] ?? a[3] ?? a[4] ?? '');
    }
    return { name: m[1].toLowerCase(), attrs };
  });
}

/** Absolute own-site navigation URLs (a/area href, form action, formaction, clickable columns). */
export function navigationUrls(html, base) {
  const out = [];
  for (const t of tags(html)) {
    const attrs = ['a', 'area'].includes(t.name) ? ['href'] : t.name === 'form' ? ['action'] : ['button', 'input'].includes(t.name) ? ['formaction'] : [];
    attrs.push('data-column-clickable');
    for (const a of attrs) {
      // Relative URLs always stay on the current host, so only absolute and
      // protocol-relative URLs say anything about hostname handling.
      if (!t.attrs[a] || !/^(https?:)?\/\//i.test(t.attrs[a])) continue;
      let u;
      try { u = new URL(t.attrs[a], base); } catch { continue; }
      if (OWN.includes(u.hostname)) out.push({ tag: t.name, attr: a, url: u });
    }
  }
  return out;
}

export function canonicals(html) {
  return tags(html).filter(t => t.name === 'link' && /(^|\s)canonical(\s|$)/i.test(t.attrs.rel || '')).map(t => t.attrs.href);
}

export function hreflangs(html) {
  return tags(html).filter(t => t.name === 'link' && t.attrs.hreflang).map(t => [t.attrs.hreflang, t.attrs.href]);
}

export function meta(html, key) {
  return tags(html).filter(t => t.name === 'meta' && (t.attrs.property === key || t.attrs.name === key)).map(t => t.attrs.content);
}

export function jsonLd(html) {
  return [...html.matchAll(/<script\b[^>]*type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/gi)].map(m => JSON.parse(m[1]));
}

export function isProtected(u) {
  return /^\/(wp-admin|wp-login\.php)/i.test(u.pathname) && u.pathname !== '/wp-admin/admin-ajax.php';
}

/** Logs in as admin on the primary host; returns a Cookie header value. */
export async function login(user = 'admin', pass = 'password') {
  await request(PRIMARY, '/wp-login.php', { headers: { Cookie: 'wordpress_test_cookie=WP%20Cookie%20check' } });
  const r = await request(PRIMARY, '/wp-login.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: 'wordpress_test_cookie=WP%20Cookie%20check' },
    body: `log=${encodeURIComponent(user)}&pwd=${encodeURIComponent(pass)}&wp-submit=Log+In&testcookie=1&redirect_to=%2Fwp-admin%2F`,
  });
  const jar = r.cookies.map(c => c.split(';')[0]);
  if (!jar.some(c => c.startsWith('wordpress_logged_in_'))) throw new Error(`login failed: HTTP ${r.status}`);
  return jar.join('; ');
}
