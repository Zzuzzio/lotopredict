#!/usr/bin/env node
/**
 * Fetch Stoloto archive draws via headless browser.
 *
 * Modes:
 *   default  — API pagination up to --pages
 *   full     — scroll + network intercept + API retries until archive exhausted
 *
 * Usage:
 *   node fetch_archive.js --game=5x36plus --url=... --pages=10
 *   node fetch_archive.js --mode=full --game=5x36plus --url=... --jsonl=... --progress=...
 */

import { chromium } from 'playwright';
import { writeFileSync, appendFileSync, readFileSync, existsSync } from 'fs';

function parseArgs(argv) {
  const opts = {
    game: '6x45',
    url: 'https://www.stoloto.ru/6x45/archive',
    pages: 10,
    count: 50,
    delay: 2500,
    out: '',
    mode: 'default',
    jsonl: '',
    progress: '',
    resume: false,
    maxStale: 0,
    retries: 5,
    targetMin: 1,
  };

  for (const arg of argv) {
    if (arg.startsWith('--game=')) opts.game = arg.slice(7);
    else if (arg.startsWith('--url=')) opts.url = arg.slice(6);
    else if (arg.startsWith('--pages=')) opts.pages = parseInt(arg.slice(8), 10);
    else if (arg.startsWith('--count=')) opts.count = parseInt(arg.slice(8), 10);
    else if (arg.startsWith('--delay=')) opts.delay = parseInt(arg.slice(8), 10);
    else if (arg.startsWith('--out=')) opts.out = arg.slice(6);
    else if (arg.startsWith('--mode=')) opts.mode = arg.slice(7);
    else if (arg.startsWith('--jsonl=')) opts.jsonl = arg.slice(8);
    else if (arg.startsWith('--progress=')) opts.progress = arg.slice(11);
    else if (arg === '--resume') opts.resume = true;
    else if (arg.startsWith('--max-stale=')) opts.maxStale = parseInt(arg.slice(12), 10);
    else if (arg.startsWith('--retries=')) opts.retries = parseInt(arg.slice(10), 10);
    else if (arg.startsWith('--target-min=')) opts.targetMin = parseInt(arg.slice(13), 10);
  }

  if (opts.mode === 'full' || opts.pages === 0) {
    opts.mode = 'full';
    opts.pages = 0;
  }

  return opts;
}

function logProgress(msg) {
  const line = `[${new Date().toISOString()}] ${msg}`;
  process.stderr.write(line + '\n');
}

function loadSeenFromJsonl(jsonlPath) {
  const seen = new Set();
  if (!jsonlPath || !existsSync(jsonlPath)) return seen;

  const content = readFileSync(jsonlPath, 'utf8');
  for (const line of content.split('\n')) {
    if (!line.trim()) continue;
    try {
      const draw = JSON.parse(line);
      if (draw.number) seen.add(draw.number);
    } catch (e) {
      // skip bad lines
    }
  }
  return seen;
}

function writeProgressFile(path, stats) {
  if (!path) return;
  writeFileSync(
    path,
    JSON.stringify(
      {
        ...stats,
        updated_at: new Date().toISOString(),
      },
      null,
      2
    ),
    'utf8'
  );
}

class DrawCollector {
  constructor(seen, jsonlPath) {
    this.seen = seen;
    this.jsonlPath = jsonlPath;
    this.draws = [];
    this.networkPages = 0;
    this.apiPages = 0;
    this.scrollRounds = 0;
  }

  add(draw) {
    const num = draw && draw.number;
    if (!num || this.seen.has(num)) return false;
    this.seen.add(num);
    this.draws.push(draw);
    if (this.jsonlPath) {
      appendFileSync(this.jsonlPath, JSON.stringify(draw) + '\n', 'utf8');
    }
    return true;
  }

  addAll(list) {
    let added = 0;
    for (const draw of list || []) {
      if (this.add(draw)) added++;
    }
    return added;
  }

  count() {
    return this.seen.size;
  }

  minNumber() {
    if (this.seen.size === 0) return null;
    return Math.min(...this.seen);
  }

  maxNumber() {
    if (this.seen.size === 0) return null;
    return Math.max(...this.seen);
  }

  stats() {
    return {
      total_draws: this.count(),
      min_draw: this.minNumber(),
      max_draw: this.maxNumber(),
      network_pages: this.networkPages,
      api_pages: this.apiPages,
      scroll_rounds: this.scrollRounds,
    };
  }
}

async function fetchApiPage(page, game, pageNum, countPerPage) {
  return page.evaluate(
    async ({ game, pageNum, countPerPage }) => {
      const apiUrl = `/p/api/mobile/api/v35/service/draws/archive?game=${encodeURIComponent(game)}&count=${countPerPage}&page=${pageNum}`;
      const res = await fetch(apiUrl, {
        credentials: 'include',
        headers: { Accept: 'application/json' },
      });

      if (!res.ok) {
        return { ok: false, httpStatus: res.status, draws: [], hasMore: false, errors: [] };
      }

      const data = await res.json();
      return {
        ok: data.requestStatus === 'success',
        httpStatus: res.status,
        draws: data.draws || [],
        hasMore: Boolean(data.hasMore),
        errors: data.errors || [],
      };
    },
    { game, pageNum, countPerPage }
  );
}

async function scrollPage(page) {
  await page.evaluate(() => {
    const scrollTargets = [
      '[data-testid="archive"]',
      '[class*="Archive"]',
      '[class*="archive"]',
      'main',
      '#__next',
      'body',
    ];

    for (const sel of scrollTargets) {
      const nodes = document.querySelectorAll(sel);
      for (const el of nodes) {
        if (el.scrollHeight > el.clientHeight + 10) {
          el.scrollTop = el.scrollHeight;
        }
      }
    }

    window.scrollTo(0, document.body.scrollHeight);

    const items = document.querySelectorAll(
      '[class*="draw"], [class*="Draw"], [data-testid*="draw"], li, article'
    );
    if (items.length > 0) {
      items[items.length - 1].scrollIntoView({ behavior: 'instant', block: 'end' });
    }
  });

  await page.mouse.wheel(0, 4000);
  await page.keyboard.press('End').catch(() => {});

  const loadMoreSelectors = [
    'button:has-text("Показать")',
    'button:has-text("ещё")',
    'button:has-text("Ещё")',
    'a:has-text("Показать")',
    '[class*="loadMore"]',
    '[class*="LoadMore"]',
  ];

  for (const sel of loadMoreSelectors) {
    const btn = page.locator(sel).first();
    if (await btn.isVisible({ timeout: 200 }).catch(() => false)) {
      await btn.click({ timeout: 2000 }).catch(() => {});
      break;
    }
  }
}

async function fetchDrawByNumber(page, game, drawNumber) {
  return page.evaluate(
    async ({ game, drawNumber }) => {
      const apiUrl = `/p/api/mobile/api/v35/service/draws/${drawNumber}?game=${encodeURIComponent(game)}`;
      const res = await fetch(apiUrl, {
        credentials: 'include',
        headers: { Accept: 'application/json' },
      });

      if (!res.ok) {
        return { ok: false, draw: null };
      }

      const data = await res.json();
      if (data.requestStatus !== 'success' || !data.draw) {
        return { ok: false, draw: null };
      }

      return { ok: true, draw: data.draw };
    },
    { game, drawNumber }
  );
}

async function tryDownloadArchive(page, collector) {
  const downloadUrl = await page.evaluate(() => {
    const links = Array.from(document.querySelectorAll('a[href]'));
    for (const a of links) {
      const href = a.href || '';
      const text = (a.textContent || '').toLowerCase();
      if (
        href.includes('.csv') ||
        href.includes('download') ||
        href.includes('export') ||
        text.includes('скачать') ||
        text.includes('архив')
      ) {
        return href;
      }
    }
    return null;
  });

  if (!downloadUrl) return false;

  logProgress(`found download link: ${downloadUrl}`);
  return false;
}

async function fetchArchivePaginated(page, game, maxPages, countPerPage, delayMs, collector) {
  let pagesFetched = 0;
  let stoppedReason = 'complete';
  const limit = maxPages > 0 ? maxPages : 999999;

  for (let pageNum = 1; pageNum <= limit; pageNum++) {
    let result = null;

    for (let attempt = 1; attempt <= 3; attempt++) {
      if (pageNum > 1) {
        await scrollPage(page);
        await page.waitForTimeout(delayMs);
      }

      result = await fetchApiPage(page, game, pageNum, countPerPage);
      if (result.ok && result.draws.length > 0) break;
      await page.waitForTimeout(delayMs * attempt);
    }

    if (!result.ok || result.draws.length === 0) {
      stoppedReason = pageNum === 1 ? 'api_error' : 'page_blocked';
      if (result.errors && result.errors.length) {
        stoppedReason = result.errors.join(', ');
      }
      break;
    }

    pagesFetched++;
    collector.apiPages = pagesFetched;
    collector.addAll(result.draws);

    if (!result.hasMore) {
      stoppedReason = 'no_more';
      break;
    }

    if (result.draws.length < countPerPage) {
      stoppedReason = 'short_page';
      break;
    }

    if (pageNum < limit) {
      await page.waitForTimeout(delayMs);
    }
  }

  return { pagesFetched, stoppedReason };
}

async function fetchArchiveFull(page, game, countPerPage, delayMs, collector, opts) {
  let stoppedReason = 'stale_scroll';
  let nextApiPage = 1;
  let staleRounds = 0;
  let hasMoreFlag = true;

  const onResponse = async (response) => {
    const url = response.url();
    if (!url.includes('/draws/archive') || !url.includes(`game=${encodeURIComponent(game)}`)) {
      return;
    }

    try {
      const data = await response.json();
      if (!data || data.requestStatus !== 'success') return;

      collector.networkPages++;
      const added = collector.addAll(data.draws || []);
      if (added > 0) {
        staleRounds = 0;
        logProgress(
          `network +${added} total=${collector.count()} min=${collector.minNumber()} max=${collector.maxNumber()}`
        );
      }

      if (data.hasMore === false) {
        hasMoreFlag = false;
      }
    } catch (e) {
      // non-json response
    }
  };

  page.on('response', onResponse);

  // Seed first page via API (usually works after page load)
  for (let attempt = 1; attempt <= opts.retries; attempt++) {
    const seed = await fetchApiPage(page, game, nextApiPage, countPerPage);
    if (seed.ok && seed.draws.length > 0) {
      collector.addAll(seed.draws);
      collector.apiPages = 1;
      nextApiPage = 2;
      hasMoreFlag = seed.hasMore;
      logProgress(`seed page=1 draws=${collector.count()} hasMore=${seed.hasMore}`);
      break;
    }
    await scrollPage(page);
    await page.waitForTimeout(delayMs * attempt);
  }

  writeProgressFile(opts.progress, collector.stats());

  await tryDownloadArchive(page, collector);

  const maxScrollRounds = 250000;

  while (true) {
    collector.scrollRounds++;
    const before = collector.count();

    await scrollPage(page);
    await page.waitForTimeout(delayMs);

    // Try explicit API pages when scroll stalls
    if (collector.count() === before || collector.scrollRounds % 5 === 0) {
      for (let attempt = 1; attempt <= opts.retries; attempt++) {
        const api = await fetchApiPage(page, game, nextApiPage, countPerPage);
        if (api.ok && api.draws.length > 0) {
          const added = collector.addAll(api.draws);
          collector.apiPages = nextApiPage;
          nextApiPage++;
          hasMoreFlag = api.hasMore;
          staleRounds = 0;
          logProgress(
            `api page=${nextApiPage - 1} +${added} total=${collector.count()} min=${collector.minNumber()}`
          );
          break;
        }

        await scrollPage(page);
        await page.waitForTimeout(delayMs * (attempt + 1));
      }
    }

    const after = collector.count();
    if (after === before) {
      staleRounds++;

      const minDraw = collector.minNumber();
      if (minDraw !== null && minDraw > opts.targetMin && staleRounds % 3 === 0) {
        for (let n = minDraw - 1; n >= Math.max(opts.targetMin, minDraw - 20); n--) {
          const one = await fetchDrawByNumber(page, game, n);
          if (one.ok && one.draw) {
            collector.add(one.draw);
          }
          await page.waitForTimeout(400);
        }
        if (collector.count() > after) {
          staleRounds = 0;
        }
      }

      if (staleRounds > 0 && staleRounds % 10 === 0) {
        logProgress('reload archive page after stale rounds');
        await page.goto(opts.url, { waitUntil: 'domcontentloaded', timeout: 120000 });
        await page.waitForTimeout(5000);
        staleRounds = Math.max(0, staleRounds - 5);
      }
    } else {
      staleRounds = 0;
    }

    if (collector.scrollRounds % 10 === 0) {
      writeProgressFile(opts.progress, collector.stats());
      logProgress(
        `scroll round=${collector.scrollRounds} total=${after} min=${collector.minNumber()} stale=${staleRounds}/${opts.maxStale}`
      );
    }

    const minDraw = collector.minNumber();
    if (minDraw !== null && minDraw <= opts.targetMin) {
      stoppedReason = 'reached_first_draw';
      break;
    }

    if (!hasMoreFlag && staleRounds >= 5) {
      stoppedReason = 'no_more';
      break;
    }

    if (opts.maxStale > 0 && staleRounds >= opts.maxStale) {
      stoppedReason = 'stale_scroll';
      break;
    }

    if (collector.scrollRounds >= maxScrollRounds) {
      stoppedReason = 'max_rounds';
      break;
    }
  }

  page.off('response', onResponse);

  if (stoppedReason === 'stale_scroll' && collector.count() > 0 && !hasMoreFlag) {
    stoppedReason = 'no_more';
  }

  return {
    pagesFetched: collector.apiPages,
    scrollRounds: collector.scrollRounds,
    stoppedReason,
  };
}

async function main() {
  const opts = parseArgs(process.argv.slice(2));
  const seen = opts.resume ? loadSeenFromJsonl(opts.jsonl) : new Set();
  const collector = new DrawCollector(seen, opts.jsonl || '');

  const output = {
    game: opts.game,
    mode: opts.mode,
    draws: [],
    jsonl: opts.jsonl || null,
    total_draws: 0,
    min_draw: null,
    max_draw: null,
    pages_fetched: 0,
    scroll_rounds: 0,
    stopped_reason: 'launch_error',
    error: null,
  };

  let browser;

  try {
    browser = await chromium.launch({
      headless: true,
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--disable-gpu',
      ],
    });

    const context = await browser.newContext({
      userAgent:
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
      locale: 'ru-RU',
      viewport: { width: 1280, height: 900 },
    });

    const page = await context.newPage();
    page.setDefaultTimeout(90000);

    logProgress(`open ${opts.url} mode=${opts.mode}`);
    await page.goto(opts.url, { waitUntil: 'domcontentloaded', timeout: 120000 });
    await page.waitForTimeout(4000);

    let result;

    if (opts.mode === 'full') {
      result = await fetchArchiveFull(page, opts.game, opts.count, opts.delay, collector, opts);
    } else {
      result = await fetchArchivePaginated(
        page,
        opts.game,
        opts.pages,
        opts.count,
        opts.delay,
        collector
      );
    }

    output.pages_fetched = result.pagesFetched;
    output.scroll_rounds = result.scrollRounds || 0;
    output.stopped_reason = result.stoppedReason;
    output.total_draws = collector.count();
    output.min_draw = collector.minNumber();
    output.max_draw = collector.maxNumber();

    writeProgressFile(opts.progress, collector.stats());

    // Keep memory low for full mode: data lives in jsonl
    if (opts.mode !== 'full' || !opts.jsonl) {
      output.draws = collector.draws;
    }

    await browser.close();
    browser = null;

    logProgress(
      `done total=${output.total_draws} min=${output.min_draw} max=${output.max_draw} reason=${output.stopped_reason}`
    );
  } catch (err) {
    output.error = err.message;
    output.stopped_reason = 'exception';
    output.total_draws = collector.count();
    output.min_draw = collector.minNumber();
    output.max_draw = collector.maxNumber();
    logProgress(`error: ${err.message}`);
    if (browser) {
      await browser.close().catch(() => {});
    }
  }

  const json = JSON.stringify(output);

  if (opts.out) {
    writeFileSync(opts.out, json, 'utf8');
  } else {
    process.stdout.write(json);
  }

  const failed =
    output.error ||
    (output.total_draws === 0 && output.pages_fetched === 0 && collector.scrollRounds === 0);

  process.exit(failed ? 1 : 0);
}

main();
