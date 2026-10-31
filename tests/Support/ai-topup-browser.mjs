import assert from 'node:assert/strict'
import {readFile,mkdir,writeFile} from 'node:fs/promises'
import {resolve} from 'node:path'
import {pathToFileURL} from 'node:url'
const {chromium}=await import(pathToFileURL('E:/code/node/dsh-upgrade-0.2.1-rc.2/node_modules/playwright/index.mjs').href)
const root=resolve('.'),output=resolve(root,'output/ai-topup-e2e/browser-evidence');await mkdir(output,{recursive:true})
const session=JSON.parse(await readFile(resolve(root,'output/ai-topup-e2e/sessions.json'),'utf8'))
const browser=await chromium.launch({headless:true,channel:'msedge'})
const errors=[],failures=[],checks=[]
const inspect=p=>{p.on('pageerror',e=>errors.push(e.message));p.on('console',m=>{if(m.type()==='error')errors.push(m.text())});p.on('response',r=>{if(r.status()>=400)failures.push({url:r.url(),status:r.status()})})}
async function dismiss(p){const notice=p.locator('.el-dialog__headerbtn:visible').first();if(await notice.count()){await notice.click();await p.locator('.el-dialog:visible').waitFor({state:'hidden'})}const help=p.locator('.help-popup .close-btn:visible');if(await help.count())await help.click()}
try{
 const context=await browser.newContext({viewport:{width:1440,height:1000},permissions:['clipboard-read','clipboard-write']})
 await context.addInitScript(auth=>localStorage.setItem('user-store',JSON.stringify({token:'verification',authData:auth,userInfo:null})),session.user_auth)
 const p=await context.newPage();inspect(p)
 await p.goto('http://127.0.0.1:18685/dashboard/finance/ai-topup',{waitUntil:'networkidle'})
 await p.locator('[data-testid="quote-price"]').filter({hasText:'30.00'}).waitFor();await dismiss(p)
 await p.getByText('30 元 × 1.5% × 10 GB =').waitFor()
 assert.equal(await p.locator('.tier-grid>div').count(),9)
 await p.screenshot({path:resolve(output,'user-desktop.png'),fullPage:false});await p.locator('.tiers-panel').scrollIntoViewIfNeeded();await p.screenshot({path:resolve(output,'user-tiers-and-orders.png'),fullPage:false});await p.locator('.topup-hero').scrollIntoViewIfNeeded();checks.push('real-overview-quote-and-nine-tiers')
 await p.getByRole('button',{name:'确认面额，创建订单',exact:true}).click()
 const dialog=p.getByRole('dialog',{name:'AI 充值订单'});await dialog.waitFor()
 const trade=(await dialog.locator('.trade-number').innerText()).trim()
 await dialog.getByRole('button',{name:'前往支付',exact:true}).click();await p.waitForURL('http://127.0.0.1:18683/submit.php?**')
 await p.getByRole('button',{name:'确认支付测试订单'}).click();await p.waitForURL('**/dashboard/finance/ai-topup?trade_no='+trade)
 await p.locator('#topup-code').waitFor()
 const code=await p.locator('#topup-code').inputValue();assert.ok(code.startsWith('ISOLATED-NOT-REDEEMABLE-'))
 await p.getByRole('button',{name:'复制兑换码',exact:true}).click();assert.equal(await p.evaluate(()=>navigator.clipboard.readText()),code)
 await p.screenshot({path:resolve(output,'user-paid-code.png'),fullPage:false});checks.push('real-payment-redirect-signed-callback-and-code-copy')
 await p.getByRole('button',{name:'激活本单赠流量',exact:true}).click();await p.getByRole('button',{name:'确认激活',exact:true}).click();await p.getByRole('dialog',{name:'AI 充值订单'}).getByText('已激活，随当前周期在下次重置清零。',{exact:true}).waitFor()
 assert.equal(await p.locator('#topup-code').inputValue(),code);await p.getByRole('button',{name:'复制兑换码',exact:true}).click();assert.equal(await p.evaluate(()=>navigator.clipboard.readText()),code);await p.screenshot({path:resolve(output,'user-traffic-activated.png'),fullPage:false});checks.push('real-single-traffic-activation-preserves-code')
 await p.getByRole('dialog',{name:'AI 充值订单'}).locator('.el-dialog__headerbtn').click()
 const mobile=await browser.newContext({viewport:{width:390,height:844},isMobile:true})
 await mobile.addInitScript(auth=>localStorage.setItem('user-store',JSON.stringify({token:'verification',authData:auth,userInfo:null})),session.user_auth)
 const m=await mobile.newPage();inspect(m);await m.goto('http://127.0.0.1:18685/dashboard/finance/ai-topup',{waitUntil:'networkidle'});await m.locator('[data-testid="quote-price"]').filter({hasText:'30.00'}).waitFor();await dismiss(m)
 assert.ok(await m.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await m.screenshot({path:resolve(output,'user-mobile.png'),fullPage:false});checks.push('fresh-mobile-real-layout')
 const admin=await browser.newContext({viewport:{width:1440,height:1080}})
 await admin.addInitScript(auth=>{window.settings={secure_path:'fixture-admin',title:'Xboard Admin'};sessionStorage.setItem('xboard_admin_auth',auth)},session.admin_auth)
 const a=await admin.newPage();inspect(a);await a.goto('http://127.0.0.1:18686/assets/admin/#/marketing/ai-topup',{waitUntil:'networkidle'})
 await a.getByText('提供方已就绪',{exact:true}).waitFor();assert.equal(await a.locator('.tier-row').count(),9)
 await a.getByRole('button',{name:'保存充值配置',exact:true}).click();await a.getByText('配置已保存',{exact:false}).waitFor()
 await a.screenshot({path:resolve(output,'admin-settings.png'),fullPage:false});checks.push('real-admin-rule-save')
 await a.getByRole('tab',{name:'充值订单'}).click();await a.getByRole('textbox',{name:'订单号',exact:true}).fill(trade);await a.getByRole('button',{name:'查询',exact:true}).click()
 await a.locator('.el-table').getByText(trade,{exact:false}).waitFor();await a.screenshot({path:resolve(output,'admin-real-order.png'),fullPage:false});checks.push('real-admin-ledger-filter')
 await a.setViewportSize({width:390,height:844});await a.getByRole('tab',{name:'充值配置'}).click();await a.locator('.admin-main').evaluate(e=>e.scrollTo(0,0));await a.screenshot({path:resolve(output,'admin-mobile.png'),fullPage:false});assert.ok(await a.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));checks.push('admin-mobile-layout')
 assert.deepEqual(errors,[]);assert.deepEqual(failures,[])
 const report={passed:true,checks,errors,failures,transport:'real Laravel, existing EPay plugin, Fastify bridge; isolated gateway and upstream; no browser API mocks',urls:{user:p.url(),mobile:m.url(),admin:a.url()},trade_no:trade}
 await writeFile(resolve(output,'report.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report))
}catch(e){console.log(JSON.stringify({errors,failures}));throw e}finally{await browser.close()}
