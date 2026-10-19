const {baseUrl, email, password, chromePath, phpBinary} = require('./config.cjs');
const { chromium } = require('../../storage/browser-tools/node_modules/playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
(async () => {
  const browser = await chromium.launch({ executablePath: chromePath, headless: true });
  const page = await browser.newPage({ viewport: { width: 1800, height: 1100 } });
  const errors = [], external = [];
  page.on('pageerror', e => errors.push(e.message));
  page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  await page.route('**/*', route => {
    const url = route.request().url();
    if (/^https?:/.test(url) && !url.startsWith(baseUrl + '/')) { external.push(url); return route.abort(); }
    return route.continue();
  });
  try {
    await page.goto(baseUrl + '/admin/login');
    await page.locator('input[type=email]').fill(email);
    await page.locator('input[type=password]').fill(password);
    await page.locator('button[type=submit]').click();
    await page.waitForURL('**/admin');
    await page.goto(baseUrl + '/admin/networks');
    await page.goto(baseUrl + '/admin/networks/6a68fca0-9a42-4f0f-a158-32cc71f53216/map');
    await page.waitForFunction(() => {
      const el = document.querySelector('[data-testid=network-editor]');
      return el && window.Alpine && Alpine.$data(el).items?.length > 0;
    }, {timeout: 20000});
    assert.equal(await page.locator('iframe').count(), 0);
    await page.waitForTimeout(500);
    fs.mkdirSync('storage/app/review', {recursive:true});
    await page.screenshot({ path:'storage/app/review/native-network-map.png', fullPage:true });
    console.log(JSON.stringify({ nodes: await page.evaluate(()=>Alpine.$data(document.querySelector('.ne')).items.length), iframe:0, external, errors }, null, 2));
    assert.deepEqual(external, []); assert.deepEqual(errors, []);
  } catch (e) {
    fs.mkdirSync('storage/app/review', {recursive:true});
    await page.screenshot({path:'storage/app/review/native-failure.png',fullPage:true});
    console.error(e); console.log('Errors:',errors); process.exitCode=1;
  } finally { await browser.close(); }
})();
