const {baseUrl, email, password, chromePath, phpBinary} = require('./config.cjs');
const { chromium } = require('../../storage/browser-tools/node_modules/playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { execFileSync } = require('node:child_process');

(async () => {
  const browser = await chromium.launch({ executablePath: chromePath, headless:true });
  const context = await browser.newContext({ viewport:{width:1800,height:1100}, acceptDownloads:true });
  const page = await context.newPage();
  const external = [], errors = [], created = [], assets = new Set();
  page.on('pageerror', e=>errors.push(e.message));
  await context.route('**/*', route=>{
    const url=route.request().url();
    if (/^https?:/.test(url) && !url.startsWith(baseUrl + '/')) { external.push(url); return route.abort(); }
    return route.continue();
  });
  const state = async () => page.evaluate(()=>{
    const s=Alpine.$data(document.querySelector('.ne')); return { nodes:s.items, edges:s.edgeItems, revision:s.graph.revision, dirty:s.dirty, error:s.error };
  });
  const boot = async () => page.waitForFunction(()=>document.querySelector('.ne-canvas')?._cyreg?.cy);
  const save = async () => {
    await page.getByTestId('save-map').click();
    await page.waitForFunction(()=>{
      const s=Alpine.$data(document.querySelector('.ne'));return !s.saving && (!s.dirty || !!s.error);
    });
    assert.equal((await state()).error,''); assert.equal((await state()).dirty,false);
    (await state()).nodes.forEach(n => { if (n.equipment_id) assets.add(n.equipment_id); });
  };
  const addDevice = async (label, ip) => {
    await page.getByTestId('new-device').click();
    await page.getByRole('textbox',{name:/^نام تجهیز/}).fill(label);
    await page.getByLabel('IP',{exact:true}).fill(ip);
    await page.getByRole('button',{name:'اعمال روی نقشه',exact:true}).click();
    await page.getByText(label,{exact:true}).first().waitFor();
  };
  try {
    await page.goto(baseUrl + '/admin/login');
    await page.locator('input[type=email]').fill(email);
    await page.locator('input[type=password]').fill(password);
    await page.locator('button[type=submit]').click(); await page.waitForURL('**/admin');
    await page.goto(baseUrl + '/admin/networks/create');
    await page.locator('input[id="data.name"]').fill('Native verification A');
    await page.getByRole('button',{name:'ایجاد',exact:true}).click(); await page.waitForURL('**/map');
    const aUrl=page.url(), aId=aUrl.split('/').at(-2); created.push(aId);
    await boot(); assert.equal((await state()).nodes.length,0); assert.equal(await page.locator('iframe').count(),0);
    await addDevice('Native Router','192.0.2.1');
    await addDevice('Native Server','192.0.2.10');
    console.log('Native Filament forms create devices: PASS');
    // Drag the server with a real pointer.
    const positions=await page.evaluate(()=>{
      const cy=document.querySelector('.ne-canvas')._cyreg.cy;
      const server=cy.nodes().filter(n=>n.data('record')?.label==='Native Server');
      const r=document.querySelector('.ne-canvas').getBoundingClientRect(), p=server.renderedPosition();
      return {x:r.x+p.x,y:r.y+p.y};
    });
    await page.mouse.move(positions.x,positions.y); await page.mouse.down();
    await page.mouse.move(positions.x+230,positions.y+150,{steps:20}); await page.mouse.up();
    await page.getByTestId('connect-mode').click();
    const nodes=(await state()).nodes;
    const endpoints=await page.evaluate(()=>{
      const cy=document.querySelector('.ne-canvas')._cyreg.cy, r=document.querySelector('.ne-canvas').getBoundingClientRect();
      return ['Native Router','Native Server'].map(label=>{const p=cy.nodes().filter(n=>n.data('record')?.label===label).renderedPosition();return {x:r.x+p.x,y:r.y+p.y};});
    });
    await page.mouse.move(endpoints[0].x,endpoints[0].y);await page.mouse.down();
    await page.mouse.move(endpoints[1].x,endpoints[1].y,{steps:30});await page.mouse.up();
    await page.getByLabel('عنوان ارتباط',{exact:true}).fill('Test uplink');
    await page.getByLabel('پورت مبدا',{exact:true}).fill('eth0');
    await page.getByRole('button',{name:'اعمال ارتباط',exact:true}).click();
    await page.waitForFunction(()=>Alpine.$data(document.querySelector('.ne')).edgeItems.length===1);
    await page.getByTestId('connect-mode').click();
    await page.getByTestId('add-zone').click();
    await page.getByRole('textbox',{name:/^عنوان/}).fill('Native DMZ');
    await page.getByRole('button',{name:'اعمال روی نقشه',exact:true}).click();
    await page.waitForFunction(()=>Alpine.$data(document.querySelector('.ne')).items.length===3);
    await save();
    const before=await state();
    assert.equal(before.nodes.filter(n=>n.kind==='device').length,2);assert.equal(before.edges.length,1);
    await page.reload(); await boot();
    const after=await state();
    assert.equal(after.nodes.length,3);assert.equal(after.edges.length,1);
    assert.equal(after.edges[0].data.source_port,'eth0');
    assert.equal(after.nodes.find(n=>n.label==='Native Server').x,before.nodes.find(n=>n.label==='Native Server').x);
    console.log('Positions, zone, link and reload: PASS');

    // Create a second network and prove no diagram is reused.
    await page.goto(baseUrl + '/admin/networks/create');
    await page.locator('input[id="data.name"]').fill('Native verification B');
    await page.getByRole('button',{name:'ایجاد',exact:true}).click();await page.waitForURL('**/map');
    created.push(page.url().split('/').at(-2));await boot();assert.equal((await state()).nodes.length,0);
    await addDevice('B only','203.0.113.7');await save();
    await page.goto(aUrl); await boot();
    assert.equal((await state()).nodes.length,3);assert.equal((await state()).nodes.some(n=>n.label==='B only'),false);
    console.log('Two independent networks: PASS');

    // Edit a node, then undo and redo through the actual keyboard shortcuts.
    await page.locator('[data-node-id="'+nodes[0].id+'"]').dblclick();
    await page.getByRole('textbox',{name:/^نام تجهیز/}).fill('Router renamed');
    await page.getByRole('button',{name:'اعمال روی نقشه',exact:true}).click();
    await page.waitForFunction(()=>Alpine.$data(document.querySelector('.ne')).items.some(n=>n.label==='Router renamed'));
    await page.getByTestId('graph-canvas').click({position:{x:20,y:20}});
    await page.keyboard.press('Control+z');
    assert.ok((await state()).nodes.some(n=>n.label==='Native Router'));
    await page.keyboard.press('Control+y');
    assert.ok((await state()).nodes.some(n=>n.label==='Router renamed'));
    await save(); console.log('Edit, undo and redo: PASS');

    for (const format of ['json','yaml','png','svg','markdown']) {
      const wait=page.waitForEvent('download');
      await page.getByTestId('export-format').selectOption(format);
      const file=await wait; assert.equal(await file.failure(),null);
      fs.mkdirSync('storage/app/review',{recursive:true});
      await file.saveAs('storage/app/review/native-export.'+({markdown:'md'}[format]||format));
    }
    console.log('JSON, YAML, PNG, SVG and Markdown exports: PASS');
    // Unsaved delete followed by YAML import restores the exported graph with fresh ids.
    await page.locator('[data-node-id="'+nodes[1].id+'"]').click();
    page.once('dialog',d=>d.accept()); await page.getByTestId('delete-selected').click();
    assert.equal((await state()).nodes.length,2);
    page.once('dialog',d=>d.accept());
    await page.getByTestId('import-input').setInputFiles('storage/app/review/native-export.yaml');
    await page.waitForFunction(()=>Alpine.$data(document.querySelector('.ne')).items.length===3);
    await save(); await page.reload();await boot();assert.equal((await state()).nodes.length,3);
    console.log('Delete, YAML import and persistence: PASS');
    assert.deepEqual(external,[]);assert.deepEqual(errors,[]);
    console.log('No iframe, no external HTTP requests, Python stopped: PASS');
  } catch(e) {
    fs.mkdirSync('storage/app/review',{recursive:true});
    await page.screenshot({path:'storage/app/review/native-roundtrip-failure.png',fullPage:true});
    console.error(e);console.log('Page errors:',errors);process.exitCode=1;
  } finally {
    await browser.close();
    const ids=created.filter(id=>/^[0-9a-f-]{36}$/.test(id));
    if(ids.length) execFileSync(phpBinary,['artisan','tinker','--execute',"App\\Models\\Network::whereIn('id',"+JSON.stringify(ids)+")->get()->each->delete();"],{stdio:'pipe'});
    if(assets.size) execFileSync(phpBinary,['artisan','tinker','--execute',"App\\Models\\Equipment::whereIn('id',"+JSON.stringify([...assets])+")->whereDoesntHave('nodes')->delete();"],{stdio:'pipe'});
  }
})();
