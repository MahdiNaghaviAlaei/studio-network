const {baseUrl, email, password, chromePath, phpBinary} = require('./config.cjs');
const {chromium}=require('../../storage/browser-tools/node_modules/playwright');
const assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs');
(async()=>{
  const browser=await chromium.launch({executablePath:chromePath,headless:true});
  const context=await browser.newContext({viewport:{width:1800,height:1100}});
  const page=await context.newPage(); const errors=[], assets=new Set(); let id;
  page.on('pageerror',e=>errors.push(e.message));
  await context.route('**/*',r=>/^https?:/.test(r.request().url())&&!r.request().url().startsWith(baseUrl + '/')?r.abort():r.continue());
  const state=()=>page.evaluate(()=>{const s=Alpine.$data(document.querySelector('.ne'));return {nodes:s.items,edges:s.edgeItems,dirty:s.dirty,error:s.error,revision:s.graph.revision};});
  const save=async()=>{await page.getByTestId('save-map').click();await page.waitForFunction(()=>{const s=Alpine.$data(document.querySelector('.ne'));return !s.saving&&(!s.dirty||s.error);});assert.equal((await state()).error,'');(await state()).nodes.forEach(n=>{if(n.equipment_id)assets.add(n.equipment_id);});};
  try{
    await page.goto(baseUrl + '/admin/login');await page.locator('input[type=email]').fill(email);await page.locator('input[type=password]').fill(password);await page.locator('button[type=submit]').click();await page.waitForURL('**/admin');
    await page.goto(baseUrl + '/admin/networks/create');await page.locator('input[id="data.name"]').fill('Native advanced verification');await page.getByRole('button',{name:'ایجاد',exact:true}).click();await page.waitForURL('**/map');id=page.url().split('/').at(-2);
    await page.waitForFunction(()=>document.querySelector('.ne-canvas')?._cyreg?.cy);
    await page.getByTestId('add-zone').click();await page.getByRole('textbox',{name:/^عنوان/}).fill('Managed zone');await page.getByRole('button',{name:'اعمال روی نقشه',exact:true}).click();await page.waitForFunction(()=>Alpine.$data(document.querySelector('.ne')).items.length===1);
    const zone=(await state()).nodes[0];
    await page.getByTestId('new-device').click();await page.getByRole('textbox',{name:/^نام تجهیز/}).fill('Grouped server');
    await page.getByRole('combobox',{name:/^ناحیه/}).selectOption(zone.id);
    await page.getByRole('button',{name:'اعمال روی نقشه',exact:true}).click();await page.waitForFunction(()=>Alpine.$data(document.querySelector('.ne')).items.length===2);
    await save(); assert.equal((await state()).nodes.find(n=>n.label==='Grouped server').parent,zone.id);
    console.log('Native grouping and save: PASS');
    await page.getByRole('button',{name:'رک',exact:true}).click();await page.getByRole('textbox',{name:/^عنوان/}).fill('Rack A');
    await page.getByRole('spinbutton',{name:'تعداد یونیت رک',exact:true}).fill('12');await page.getByRole('button',{name:'اعمال روی نقشه',exact:true}).click();await page.waitForFunction(()=>Alpine.$data(document.querySelector('.ne')).items.length===3);
    const rack=(await state()).nodes.find(n=>n.kind==='rack');
    await page.getByTestId('new-device').click();await page.getByRole('textbox',{name:/^نام تجهیز/}).fill('Mounted server');
    await page.getByRole('combobox',{name:/^ناحیه/}).selectOption(rack.id);
    await page.getByRole('spinbutton',{name:'یونیت شروع در رک',exact:true}).fill('3');
    await page.getByRole('spinbutton',{name:'ارتفاع در رک (U)',exact:true}).fill('2');
    await page.getByRole('button',{name:'اعمال روی نقشه',exact:true}).click();await page.waitForFunction(()=>Alpine.$data(document.querySelector('.ne')).items.length===4);await save();
    const mounted=(await state()).nodes.find(n=>n.label==='Mounted server');assert.equal(mounted.parent,rack.id);assert.equal(mounted.data.rack_u,3);
    console.log('Rack frame, unit placement and persistence: PASS');
    await page.getByTitle('تاریخچه نسخه‌ها',{exact:true}).click();
    const select=page.getByRole('combobox',{name:/^نسخه/});
    await select.waitFor();
    const value=await select.locator('option').evaluateAll(options=>options.find(o=>o.textContent.trim().startsWith('نسخه 1 ')).value);
    await select.selectOption(value);await page.getByRole('button',{name:'بازگردانی روی بوم',exact:true}).click();await page.waitForFunction(()=>Alpine.$data(document.querySelector('.ne')).items.length===2);await save();
    await page.reload();await page.waitForFunction(()=>document.querySelector('.ne-canvas')?._cyreg?.cy);assert.equal((await state()).nodes.length,2);
    console.log('Version restore and reload: PASS');
    await page.getByRole('button',{name:'حالت فقط مشاهده',exact:true}).click().catch(async()=>page.getByTitle('حالت فقط مشاهده',{exact:true}).click());
    assert.equal(await page.getByTestId('add-device').isDisabled(),true);
    await page.getByTitle('حالت فقط مشاهده',{exact:true}).click();
    await page.getByRole('combobox',{name:'تم نقشه',exact:true}).selectOption('light');await save();
    await page.reload();await page.waitForFunction(()=>document.querySelector('.ne-canvas')?._cyreg?.cy);assert.equal(await page.locator('.ne').getAttribute('data-theme'),'light');
    await page.goto(baseUrl + '/admin/equipment');
    await page.locator('input[type=search]').fill('Grouped server');
    await page.locator('tr').filter({hasText:'Grouped server'}).getByRole('link',{name:'ویرایش',exact:true}).click();
    await page.waitForURL('**/equipment/*/edit');
    await page.waitForFunction(()=>{
      const el=document.querySelector('input[id="data.label"]');
      const root=el?.closest('[wire\\:id]');
      return root && window.Livewire?.find(root.getAttribute('wire:id'))?.get('data.label')==='Grouped server';
    });
    await page.getByRole('textbox',{name:/^نام تجهیز/}).fill('Inventory updated');
    const [saved]=await Promise.all([
      page.waitForResponse(r=>r.url().includes('/livewire/update')&&r.request().method()==='POST'),
      page.getByRole('button',{name:'ذخیره',exact:true}).click(),
    ]);assert.equal(saved.status(),200);
    await page.goto(baseUrl + '/admin/networks/'+id+'/map');await page.waitForFunction(()=>document.querySelector('.ne-canvas')?._cyreg?.cy);assert.ok((await state()).nodes.some(n=>n.label==='Inventory updated'));
    console.log('Read-only mode, persisted theme and native inventory edit: PASS');
    assert.deepEqual(errors,[]);
  }catch(e){fs.mkdirSync('storage/app/review',{recursive:true});await page.screenshot({path:'storage/app/review/native-advanced-failure.png',fullPage:true});console.error(e);console.log(errors);process.exitCode=1;}
  finally{await browser.close();if(id&&/^[0-9a-f-]{36}$/.test(id))execFileSync(phpBinary,['artisan','tinker','--execute',"App\\Models\\Network::whereKey('"+id+"')->get()->each->delete();"],{stdio:'pipe'});if(assets.size)execFileSync(phpBinary,['artisan','tinker','--execute',"App\\Models\\Equipment::whereIn('id',"+JSON.stringify([...assets])+")->whereDoesntHave('nodes')->delete();"],{stdio:'pipe'});}
})();
