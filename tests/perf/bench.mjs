// Front-end performance benchmark against the local test site.
// Measures server-side time (inside PHP, from MU-plugin load to the end of
// output) and peak memory for primary and alias requests, with the plugin
// active and inactive. Run with the test server up:
//   node tests/perf/bench.mjs [label]
// Results are printed as a Markdown table and written to tests/perf/results-<label>.json.
import fs from 'node:fs';
import path from 'node:path';
import { ROOT, DIR, wp, request, setSettings, ALIASES } from '../e2e/lib.mjs';

const label = process.argv[2] || 'current';
const RUNS = Number(process.env.BENCH_RUNS || 30);
const WARMUP = 3;
const content = path.join(DIR, 'wordpress/wp-content');
const log = path.join(content, 'wpda-perf.log');
const probe = path.join(content, 'mu-plugins/wpda-perf-probe.php');

function median(a) { const s = [...a].sort((x, y) => x - y); const m = s.length >> 1; return s.length % 2 ? s[m] : (s[m - 1] + s[m]) / 2; }
function p95(a) { const s = [...a].sort((x, y) => x - y); return s[Math.min(s.length - 1, Math.ceil(s.length * 0.95) - 1)]; }

async function measure(host, url) {
  for (let i = 0; i < WARMUP; i++) await request(host, url, { headers: { Accept: 'text/html' } });
  fs.writeFileSync(log, '');
  for (let i = 0; i < RUNS; i++) {
    const r = await request(host, url, { headers: { Accept: 'text/html' } });
    if (r.status !== 200) throw new Error(`${host}${url} -> ${r.status}`);
  }
  const rows = fs.readFileSync(log, 'utf8').trim().split('\n').map(l => l.split('\t'));
  const ms = rows.map(r => Number(r[3]));
  const mem = rows.map(r => Number(r[2]));
  return { median_ms: +median(ms).toFixed(2), p95_ms: +p95(ms).toFixed(2), peak_mem_kb: Math.round(median(mem) / 1024) };
}

fs.copyFileSync(path.join(ROOT, 'tests/perf/perf-probe.php'), probe);
const perfId = wp('eval', `
  $c = ''; for ( $i = 0; $i < 2000; $i++ ) { $c .= '<a href="' . home_url( "/p-$i/?q=$i" ) . '">L' . $i . '</a> '; }
  $id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Perf', 'post_name' => 'perf-bench', 'post_content' => $c ) );
  echo $id;`);
const results = [];
try {
  setSettings({});
  const cases = [['/', 'Homepage'], ['/about/', 'Internal page'], ['/perf-bench/', 'Large page (2,000 links)']];
  wp('plugin', 'deactivate', 'wp-domain-alias');
  for (const [url, name] of cases) results.push({ page: name, host: 'primary (plugin inactive)', ...(await measure('primary.test', url)) });
  wp('plugin', 'activate', 'wp-domain-alias');
  for (const [url, name] of cases) {
    results.push({ page: name, host: 'primary', ...(await measure('primary.test', url)) });
    results.push({ page: name, host: 'alias', ...(await measure(ALIASES[0], url)) });
  }
  // 25 aliases configured, large page on an alias.
  const many = [...ALIASES]; for (let i = 0; many.length < 25; i++) many.push(`extra-${i}.test`);
  setSettings({ aliases: many });
  results.push({ page: 'Large page, 25 aliases configured', host: 'alias', ...(await measure(ALIASES[0], '/perf-bench/')) });
} finally {
  setSettings({});
  wp('post', 'delete', perfId, '--force');
  fs.rmSync(probe, { force: true });
  fs.rmSync(log, { force: true });
}

console.log(`\n${label} (${RUNS} requests each, server-side time)\n`);
console.log('| Page | Host | Median ms | p95 ms | Peak memory KB |\n|---|---|---|---|---|');
for (const r of results) console.log(`| ${r.page} | ${r.host} | ${r.median_ms} | ${r.p95_ms} | ${r.peak_mem_kb} |`);
fs.writeFileSync(path.join(ROOT, `tests/perf/results-${label}.json`), JSON.stringify({ label, runs: RUNS, date: new Date().toISOString(), results }, null, 2));
