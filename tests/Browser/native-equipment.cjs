const {baseUrl, email, password, chromePath, phpBinary} = require('./config.cjs');
const { chromium } = require('../../storage/browser-tools/node_modules/playwright');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');

(async () => {
    const browser = await chromium.launch({ executablePath: chromePath, headless: true });
    const context = await browser.newContext({ viewport: { width: 1800, height: 1100 } });
    const page = await context.newPage();
    const networks = [], errors = [], external = [];
    const label = 'mehdi verification ' + Date.now();
    let equipmentId;
    page.on('pageerror', error => errors.push(error.message));
    await context.route('**/*', route => {
        const url = route.request().url();
        if (/^https?:/.test(url) && !url.startsWith(baseUrl + '/')) { external.push(url); return route.abort(); }
        return route.continue();
    });
    const boot = () => page.waitForFunction(() => document.querySelector('.ne-canvas')?._cyreg?.cy);
    const state = () => page.evaluate(() => {
        const s = Alpine.$data(document.querySelector('.ne'));
        return { nodes: s.items, dirty: s.dirty, error: s.error };
    });
    const save = async () => {
        await page.getByTestId('save-map').click();
        await page.waitForFunction(() => { const s = Alpine.$data(document.querySelector('.ne')); return !s.saving && (!s.dirty || s.error); });
        assert.equal((await state()).error, '');
    };
    const createMap = async suffix => {
        await page.goto(baseUrl + '/admin/networks/create');
        await page.locator('input[id="data.name"]').fill(label + ' ' + suffix);
        await page.getByRole('button', { name: 'ایجاد', exact: true }).click();
        await page.waitForURL('**/map');
        networks.push(page.url().split('/').at(-2));
        await boot();
    };
    const selectExisting = async () => {
        await page.getByTestId('add-device').click();
        await page.getByText('افزودن از تجهیزات موجود', { exact: true }).waitFor();
        await page.locator('.choices__inner').last().click();
        await page.locator('.choices__input--cloned').last().fill(label);
        await page.getByRole('option', { name: new RegExp(label) }).click();
        await page.getByRole('button', { name: 'افزودن به بوم', exact: true }).click();
    };
    try {
        await page.goto(baseUrl + '/admin/login');
        await page.locator('input[type=email]').fill(email);
        await page.locator('input[type=password]').fill(password);
        await page.locator('button[type=submit]').click(); await page.waitForURL('**/admin');

        await page.goto(baseUrl + '/admin/equipment');
        await page.getByRole('link', { name: 'ایجاد تجهیز', exact: true }).click();
        await page.waitForURL('**/equipment/create');
        await page.waitForFunction(() => {
            const root = document.querySelector('input[id="data.label"]')?.closest('[wire\\:id]');
            return root && window.Livewire?.find(root.getAttribute('wire:id'));
        });
        await page.getByRole('textbox', { name: /^نام تجهیز/ }).fill(label);
        await page.getByRole('textbox', { name: 'IP', exact: true }).fill('192.0.2.45');
        await page.getByRole('button', { name: 'ایجاد', exact: true }).click();
        await page.waitForURL('**/admin/equipment');
        await page.locator('input[type=search]').fill(label);
        const edit = page.locator('tr').filter({ hasText: label }).getByRole('link', { name: 'ویرایش', exact: true });
        await edit.waitFor(); equipmentId = (await edit.getAttribute('href')).split('/').at(-2);
        console.log('Create equipment in inventory before drawing a map: PASS');

        await createMap('A');
        await selectExisting();
        await page.waitForFunction(() => Alpine.$data(document.querySelector('.ne')).items.length === 1);
        assert.equal((await state()).nodes[0].equipment_id, equipmentId);
        assert.equal((await state()).nodes[0].data.ip, '192.0.2.45');
        await save(); await page.reload(); await boot();
        assert.equal((await state()).nodes[0].equipment_id, equipmentId);
        const firstId = (await state()).nodes[0].id;
        await selectExisting();
        await page.getByTestId('editor-message').filter({ hasText: 'قبلاً' }).waitFor();
        assert.equal((await state()).nodes.length, 1);
        assert.equal((await state()).dirty, false);
        console.log('Search, attach, save, reload and prevent duplicate placement: PASS');

        await createMap('B'); await selectExisting();
        await page.waitForFunction(() => Alpine.$data(document.querySelector('.ne')).items.length === 1);
        assert.equal((await state()).nodes[0].equipment_id, equipmentId);
        assert.notEqual((await state()).nodes[0].id, firstId);
        await save();
        await page.locator('[data-node-id]').first().click();
        page.once('dialog', dialog => dialog.accept());
        await page.getByTestId('delete-selected').click();
        await save(); await page.reload(); await boot();
        assert.equal((await state()).nodes.length, 0);
        await page.goto(baseUrl + '/admin/equipment/' + equipmentId + '/edit');
        await page.getByRole('textbox', { name: /^نام تجهیز/ }).waitFor();
        assert.equal(await page.getByRole('textbox', { name: /^نام تجهیز/ }).inputValue(), label);
        await page.goto(baseUrl + '/admin/networks/' + networks[0] + '/map'); await boot();
        assert.equal((await state()).nodes.length, 1);
        console.log('Same asset in another map; removing it preserves inventory and the first map: PASS');
        assert.deepEqual(errors, []); assert.deepEqual(external, []);
        fs.mkdirSync('storage/app/review', { recursive: true });
        await page.screenshot({ path: 'storage/app/review/native-equipment.png', fullPage: true });
    } catch (error) {
        fs.mkdirSync('storage/app/review', { recursive: true });
        await page.screenshot({ path: 'storage/app/review/native-equipment-failure.png', fullPage: true });
        console.error(error); console.log({ errors, external }); process.exitCode = 1;
    } finally {
        await browser.close();
        if (networks.length) execFileSync(phpBinary, ['artisan', 'tinker', '--execute', "App\\Models\\Network::whereIn('id'," + JSON.stringify(networks) + ")->get()->each->delete();"], { stdio: 'pipe' });
        if (equipmentId && /^[0-9a-f-]{36}$/.test(equipmentId)) execFileSync(phpBinary, ['artisan', 'tinker', '--execute', "App\\Models\\Equipment::whereKey('" + equipmentId + "')->whereDoesntHave('nodes')->delete();"], { stdio: 'pipe' });
    }
})();
