import assert from 'node:assert/strict'
import {readFile,mkdir,writeFile} from 'node:fs/promises'
import {resolve} from 'node:path'
import {pathToFileURL} from 'node:url'
const {chromium}=await import(pathToFileURL('E:/code/node/dsh-upgrade-0.2.1-rc.2/node_modules/playwright/index.mjs').href)
const out=resolve('output/ai-topup-production-20261025/browser-evidence');await mkdir(out,{recursive:true})
const session=JSON.parse(await readFile('output/ai-topup-production-20261025/private/verification.json','utf8'))
const browser=await chromium.launch({channel:'msedge',headless:true});const errors=[],failures=[],checks=[]
const inspect=p=>{p.on('pageerror',e=>errors.push(e.message));p.on('console',m=>{if(m.type()==='error')errors.push(m.text())});p.on('response',r=>{if(r.status()>=400&&r.url().includes('/api/'))failures.push({url:r.url().split('?')[0],status:r.status()})})}
async function dismiss(p){const x=p.locator('.el-dialog__headerbtn:visible').first();if(await x.count()){await x.click();await p.locator('.el-dialog:visible').waitFor({state:'hidden'})}}
const userURL='https://spkun.cc/dashboard/finance/ai-topup',adminURL='http://152.53.48.209:7002/assets/admin/#/marketing/ai-topup',originalAdminURL='http://152.53.48.209:7001/'+session.secure_path+'#/marketing/ai-topup'
let trade
try{
 const ctx=await browser.newContext({viewport:{width:1440,height:1000}})
 await ctx.addInitScript(auth=>localStorage.setItem('user-store',JSON.stringify({token:'release-verification',authData:auth,userInfo:null})),session.user_auth)
 const p=await ctx.newPage();inspect(p);await p.goto(userURL,{waitUntil:'networkidle'});await dismiss(p)
 await p.locator('[data-testid="quote-price"]').filter({hasText:'30.00'}).waitFor();assert.equal(await p.locator('.tier-grid>div').count(),9)
 await p.screenshot({path:resolve(out,'user-desktop.png')});await p.locator('.tiers-panel').scrollIntoViewIfNeeded();await p.screenshot({path:resolve(out,'user-tiers.png')});checks.push('public-domain-live-quote-nine-tiers-1.5-percent')
 await p.getByRole('button',{name:'确认面额，创建订单',exact:true}).click();const dialog=p.getByRole('dialog',{name:'AI 充值订单'});await dialog.locator('.trade-number').waitFor();trade=(await dialog.locator('.trade-number').innerText()).trim();assert.ok(trade.startsWith('AIT'))
 const choices=dialog.locator('select option');const elChoices=dialog.locator('.el-radio');assert.ok(await choices.count()>0||await elChoices.count()>0||await dialog.locator('.el-select').count()>0)
 await p.screenshot({path:resolve(out,'user-unpaid-existing-channel.png')});checks.push('live-create-unpaid-order-existing-payment-channel')
 await dialog.getByRole('button',{name:'取消订单',exact:true}).click();await p.getByRole('button',{name:'确认取消订单',exact:true}).click();await dialog.getByText('已取消',{exact:true}).first().waitFor();checks.push('live-unpaid-order-cancel')
 const mobile=await browser.newContext({viewport:{width:390,height:844},isMobile:true,deviceScaleFactor:1,hasTouch:true});await mobile.addInitScript(auth=>localStorage.setItem('user-store',JSON.stringify({token:'release-verification',authData:auth,userInfo:null})),session.user_auth)
 const m=await mobile.newPage();inspect(m);await m.goto(userURL,{waitUntil:'networkidle'});await dismiss(m);await m.locator('[data-testid="quote-price"]').filter({hasText:'30.00'}).waitFor();assert.ok(await m.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));assert.equal(await m.locator('.help-widget').count(),0);await m.screenshot({path:resolve(out,'user-mobile.png')});await m.locator('.gift-panel').scrollIntoViewIfNeeded().catch(()=>{});await m.screenshot({path:resolve(out,'user-mobile-gift.png')});checks.push('public-mobile-no-overflow-no-payment-overlay')
 const admin=await browser.newContext({viewport:{width:1440,height:1080}});await admin.addInitScript(s=>{window.settings={secure_path:s.secure_path,title:'Xboard Admin'};sessionStorage.setItem('xboard_admin_auth',s.admin_auth)},session)
 const a=await admin.newPage();inspect(a);await a.goto(adminURL,{waitUntil:'networkidle'});await a.getByRole('button',{name:'保存充值配置',exact:true}).waitFor();assert.equal(await a.getByRole('spinbutton',{name:'赠送比例基点'}).inputValue().catch(()=>a.locator('input').filter({hasText:'150'}).count()),'150');await a.screenshot({path:resolve(out,'admin-settings.png')});checks.push('direct-admin-current-settings')
 const original=await admin.newPage();inspect(original);await original.goto(originalAdminURL,{waitUntil:'networkidle'});await original.getByRole('button',{name:'保存充值配置',exact:true}).waitFor();await original.screenshot({path:resolve(out,'admin-original-entry.png')});checks.push('original-backend-admin-vite-manifest-entry')
 await a.getByRole('tab',{name:'充值订单',exact:true}).click();await a.getByRole('textbox',{name:'订单号',exact:true}).fill(trade);await a.getByRole('button',{name:'查询',exact:true}).click();await a.locator('.el-table').getByText(trade,{exact:false}).waitFor();await a.screenshot({path:resolve(out,'admin-cancelled-ledger.png')});checks.push('live-admin-filter-cancelled-order')
 assert.deepEqual(errors,[]);assert.deepEqual(failures,[])
 const report={passed:true,checks,errors,failures,userURL,adminURL,originalAdminURL,trade_no:trade,viewports:['1440x1000','390x844','1440x1080'],real_payment_executed:false};await writeFile(resolve(out,'report.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report))
}finally{await browser.close();if(errors.length||failures.length)console.log(JSON.stringify({errors,failures}))}
