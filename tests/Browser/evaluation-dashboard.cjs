const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const assert = require('node:assert/strict');
(async()=>{
 const fixtures=Object.fromEntries(['','desempeno','supervisores','residentes'].map(type=>[type,execFileSync('C:/xampp/php/php.exe',['tests/Browser/render-evaluation-dashboard.php',type],{encoding:'utf8'}).replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi,'').replace(/<link\b[^>]*>/gi,'')]));
 const browser=await chromium.launch({channel:'chrome',headless:true});
 try {
  const page=await browser.newPage(); let requests=0; const errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/*',r=>{ if(r.request().headers()['x-evaluation-dashboard']) requests++; return r.fulfill({contentType:'text/html',body:fixtures[new URL(r.request().url()).searchParams.get('evaluacion_tipo') || '']}); });
  await page.goto('http://127.0.0.1:8000/evaluaciones?tipo=dashboard');
  const manifest=JSON.parse(fs.readFileSync('public/build/manifest.json','utf8'));
  await page.addStyleTag({path:'public/css/proserge-app.css'});
  await page.addStyleTag({path:'public/build/'+manifest['resources/css/app.css'].file});
  await page.addStyleTag({path:'public/css/evaluations-dashboard.css'});
  await page.addStyleTag({content:'*{transition:none!important;animation:none!important}'});
  await page.addScriptTag({path:'public/js/evaluations-dashboard.js'});
  let navigation=0;page.on('framenavigated',()=>navigation++);
  await page.locator('#ed-job').selectOption('SUPERVISOR');
  await page.waitForFunction(()=>location.search.includes('cargo=SUPERVISOR'));
  assert.equal(requests,1);assert.equal(navigation,1); // History replacement emits a same-document navigation.
  await page.locator('#ed-evaluation-type').selectOption('supervisores');
  await page.waitForFunction(()=>location.search.includes('evaluacion_tipo=supervisores'));
  assert.equal(requests,2);assert.equal(navigation,2);
  for(const width of [1440,390]){
   await page.setViewportSize({width,height:1000});
   assert.equal(await page.locator('#evaluation-dashboard').evaluate(e=>e.scrollWidth>e.clientWidth+1),false);
   fs.mkdirSync('storage/app/testing',{recursive:true});
   await page.screenshot({path:`storage/app/testing/evaluation-dashboard-${width}.png`,fullPage:true});
  }
  assert.deepEqual(errors,[]);console.log('PASS dashboard AJAX and desktop/mobile rendering');
  for(const type of ['desempeno','supervisores','residentes']){
   await page.locator('#ed-evaluation-type').selectOption(type);
   await page.locator(`[data-detail-type="${type}"]`).waitFor();
   if(type==='supervisores'){
    const summaries=page.locator('.ed-specific summary');
    for(let index=0;index<await summaries.count();index++) await summaries.nth(index).click();
   }
   for(const width of [1440,390]){
    await page.setViewportSize({width,height:1000});
    assert.equal(await page.locator('#evaluation-dashboard').evaluate(e=>e.scrollWidth>e.clientWidth+1),false);
    await page.locator('.ed-specific').screenshot({path:`storage/app/testing/evaluation-detail-${type}-${width}.png`});
   }
  }
  await page.locator('#ed-evaluation-type').selectOption('');
  await page.waitForFunction(()=>!document.querySelector('.ed-specific'));
  assert.deepEqual(errors,[]);console.log('PASS all three detail views and clear selection');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
