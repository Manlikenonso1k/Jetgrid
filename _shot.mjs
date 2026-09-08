import puppeteer from 'puppeteer-core';

const BASE = 'http://127.0.0.1:8123';
const OUT = process.argv[2] || 'grid.png';

const browser = await puppeteer.launch({
    executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    headless: 'new',
    args: [
        '--no-sandbox',
        '--use-gl=angle',
        '--use-angle=swiftshader',
        '--enable-unsafe-swiftshader',
        '--window-size=1500,1000',
    ],
});

const page = await browser.newPage();
await page.setViewport({ width: 1500, height: 1000, deviceScaleFactor: 1 });

const errors = [];
page.on('pageerror', (e) => errors.push('PAGEERROR: ' + e.message));
page.on('console', (m) => {
    if (m.type() === 'error') errors.push('CONSOLE: ' + m.text());
});
page.on('requestfailed', (r) => errors.push('REQFAIL: ' + r.url() + ' ' + r.failure()?.errorText));

// Log in.
await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle2' });
await page.type('#data\\.email', 'screenshot@jetgrid.local');
await page.type('#data\\.password', 'screenshot-check-123');
await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle2' }).catch(() => {}),
    page.click('button[type=submit]'),
]);

console.log('after login URL:', page.url());

// Go to the 3D grid.
await page.goto(`${BASE}/admin/grid`, { waitUntil: 'networkidle2' });

// Wait for the scene: either a WebGL canvas or the flat fallback.
await page
    .waitForFunction(
        () => {
            const root = document.querySelector('[data-jetgrid-scene]');
            if (!root) return false;
            return !!root.querySelector('canvas') || !!root.querySelector('.jg-flat');
        },
        { timeout: 25000 },
    )
    .catch(() => console.log('WARN: no canvas or flat grid appeared'));

// Let the scene settle: poll arrives, beacons start strobing, camera drifts.
await new Promise((r) => setTimeout(r, 5000));

const report = await page.evaluate(() => {
    const root = document.querySelector('[data-jetgrid-scene]');
    const canvas = root?.querySelector('canvas');
    let webgl = null;
    if (canvas) {
        const gl = canvas.getContext('webgl2') || canvas.getContext('webgl');
        webgl = gl ? gl.getParameter(gl.VERSION) : 'no context';
    }
    return {
        mounted: root?.dataset.jgMounted ?? 'no',
        hasCanvas: !!canvas,
        canvasSize: canvas ? `${canvas.width}x${canvas.height}` : null,
        webgl,
        chips: [...document.querySelectorAll('.jg-chip')].map((c) => c.textContent.trim()),
        sections: [...document.querySelectorAll('.fi-section-header-heading')].map((h) => h.textContent.trim()),
    };
});

console.log(JSON.stringify(report, null, 2));

await page.screenshot({ path: OUT, fullPage: false });
console.log('screenshot ->', OUT);

// Click the first house to prove the side panel works.
if (report.hasCanvas) {
    const box = await page.$eval('[data-jetgrid-scene] canvas', (c) => {
        const r = c.getBoundingClientRect();
        return { x: r.x, y: r.y, w: r.width, h: r.height };
    });
    await page.mouse.click(box.x + box.w / 2, box.y + box.h / 2);
    await new Promise((r) => setTimeout(r, 1200));
    const panel = await page.evaluate(() => {
        const p = document.querySelector('.jg-panel');
        return p ? { open: true, text: p.innerText.slice(0, 400) } : { open: false };
    });
    console.log('SIDE PANEL:', JSON.stringify(panel, null, 2));
}

console.log('ERRORS:', errors.length ? JSON.stringify(errors, null, 2) : 'none');

await browser.close();
