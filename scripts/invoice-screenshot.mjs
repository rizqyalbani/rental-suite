import { chromium } from 'playwright';

const args = process.argv.slice(2);
const url = args[0];
const outPath = args[1];
const selector = args[2] || '#invoice-print-target';
const viewport = args[3] || '520,4000';
const waitMs = Number(args[4] || 12000);
const MAX_DIM = 12000;

function parseViewport(v) {
  const m = String(v || '').trim().match(/^(\d+)\s*,\s*(\d+)$/);
  if (!m) return { width: 520, height: 4000 };
  return { width: Math.max(200, Number(m[1])), height: Math.max(200, Number(m[2])) };
}

if (!url || !outPath) {
  process.stderr.write('Usage: node scripts/invoice-screenshot.mjs <url> <outPath> [selector] [viewportW,viewportH] [waitMs]\n');
  process.exit(2);
}

const vp = parseViewport(viewport);

let browser;
try {
  browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    viewport: vp,
    deviceScaleFactor: 2,
  });
  const page = await context.newPage();
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: Math.max(15000, waitMs + 15000) });
  await page.waitForLoadState('networkidle', { timeout: Math.max(15000, waitMs + 15000) }).catch(() => {});
  await page.waitForTimeout(250);
  await page.waitForSelector('#invoice-print-target', { timeout: Math.max(15000, waitMs) });

  const el = await page.waitForSelector(selector, { timeout: Math.max(15000, waitMs), state: 'attached' });
  await page.evaluate(async () => {
    try {
      if (document?.fonts?.ready) await document.fonts.ready;
    } catch {}
    try {
      const imgs = Array.from(document.images || []);
      await Promise.race([
        Promise.all(
          imgs.map((img) => {
            if (!img) return Promise.resolve();
            if (img.complete && img.naturalWidth > 0) return Promise.resolve();
            return new Promise((resolve) => {
              const done = () => resolve();
              img.addEventListener('load', done, { once: true });
              img.addEventListener('error', done, { once: true });
              setTimeout(done, 8000);
            });
          }),
        ),
        new Promise((resolve) => setTimeout(resolve, 8000)),
      ]);
    } catch {}
  });
  await page.evaluate((node) => {
    if (!node) return;
    node.scrollIntoView({ block: 'start', inline: 'nearest' });
  }, el);
  await page.waitForTimeout(150);

  const dims = await el.evaluate((node) => {
    const r = node.getBoundingClientRect();
    return {
      w: Math.ceil(r.width),
      h: Math.ceil(r.height),
      scrollW: Math.ceil(node.scrollWidth || r.width),
      scrollH: Math.ceil(node.scrollHeight || r.height),
    };
  });

  const contentW = Math.max(dims?.w || 0, dims?.scrollW || 0, vp.width);
  const contentH = Math.max(dims?.h || 0, dims?.scrollH || 0, vp.height);
  const desiredW = Math.min(MAX_DIM, Math.max(vp.width, contentW + 40));
  const desiredH = Math.min(MAX_DIM, Math.max(vp.height, contentH + 60));
  if (desiredW !== vp.width || desiredH !== vp.height) {
    await page.setViewportSize({ width: desiredW, height: desiredH });
    await page.waitForTimeout(150);
    await page.evaluate((node) => {
      if (!node) return;
      node.scrollIntoView({ block: 'start', inline: 'nearest' });
    }, el);
    await page.waitForTimeout(150);
  }

  await page.evaluate(() => window.scrollTo(0, 0)).catch(() => {});
  await page.waitForTimeout(80);
  await el.screenshot({ path: outPath, type: 'png' });
  await context.close();
  await browser.close();
  process.exit(0);
} catch (e) {
  try {
    if (browser) await browser.close();
  } catch {}
  process.stderr.write(String(e && e.stack ? e.stack : e) + '\n');
  process.exit(1);
}
