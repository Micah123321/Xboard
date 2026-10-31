import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { readFile, mkdir } from 'node:fs/promises'
import { dirname, extname, resolve, join } from 'node:path'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'

const require = createRequire(import.meta.url)
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright')
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const build = resolve(root, process.env.ADMIN_BUILD_OUT_DIR || 'dist-ai-credit-review')
const artifacts = join(root, 'dist-ai-credit-review', 'browser-evidence', 'rules-20261025-1902')
await mkdir(artifacts, { recursive: true })
const mime = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml' }
const server = createServer(async (request, response) => {
  try {
    const path = decodeURIComponent(new URL(request.url, 'http://localhost').pathname).replace(/^\/assets\/admin\/?/, '') || 'index.html'
    const file = resolve(build, path)
    assert.ok(file.startsWith(build + '/') || file.startsWith(build + '\\'))
    response.setHeader('Content-Type', mime[extname(file)] || 'application/octet-stream')
    response.end(await readFile(file))
  } catch { console.error('Missing fixture asset:', request.url); response.writeHead(404); response.end('Not found') }
})
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve))
const origin = 'http://127.0.0.1:' + server.address().port
const browser = await chromium.launch({ headless: true, ...(process.env.BROWSER_EXECUTABLE ? { executablePath: process.env.BROWSER_EXECUTABLE } : {}) })
const context = await browser.newContext({ viewport: { width: 1440, height: 1080 } })
const page = await context.newPage()
const errors = [], saves = [], calls = []
page.on('pageerror', e => errors.push(e.message))
page.on('console', m => { if (m.type() === 'error') errors.push(m.text()) })
const settings = { enabled:true,title:'订阅即享 AI 创作额度',description:'每一份订阅，让灵感走得更远。符合条件的订单可领取 AI 中转额度。',site_url:'https://ai.example.com',pricing_url:'https://ai.example.com/pricing',transparency_url:'https://ai.example.com/transparency',rate_usd_per_gb:'0.05',min_paid_cents:1500,include_balance:false,legacy_enabled:true,starts_at:null,ends_at:null,plan_ids:[1],bridge_url:'https://bridge.example.com',bridge_token_set:true,provider_ready:true,version:1 }
const grant = {id:1,user_id:42,email:'creator@example.com',order_id:123,trade_no:'202610250001',plan_name:'创作进阶计划',traffic_gb:200,paid_amount_cents:2900,amount_usd:'10.00',source:'order',status:'issued',created_at:1792800000,issued_at:1792800000,redeemed_at:null,last_error:null}
let failRevoke = true, failRecords = false, batches = 0
await context.addInitScript(() => { window.settings={secure_path:'mock-admin',title:'Xboard Admin'};sessionStorage.setItem('xboard_admin_auth','synthetic-test-token') })
await page.route('**/*', async route => {
 const req=route.request(), url=new URL(req.url())
 if(url.origin !== origin) return route.abort()
 if(!url.pathname.startsWith('/api/')) return route.continue()
 calls.push({path:url.pathname,query:url.search,body:req.postDataJSON()})
 const send=data=>route.fulfill({json:{status:'success',data}})
 if(url.pathname.endsWith('/getSystemStatus')) return send({})
 if(url.pathname.endsWith('/plan/fetch')) return send([{id:1,name:'创作进阶计划'},{id:2,name:'探索计划'}])
 if(url.pathname.endsWith('/ai-credit/settings')) {if(req.method()==='POST'){saves.push(req.postDataJSON());Object.assign(settings,req.postDataJSON());delete settings.bridge_token;settings.version++}return send(settings)}
 if(url.pathname.endsWith('/ai-credit/records')) {if(failRecords)return route.fulfill({json:{status:'fail',message:'台账暂时不可用'}});return send({records:[grant],total:21,summary:{eligible_count:126,issued_count:98,redeemed_count:72,reconciling_count:2,eligible_amount_usd:'1260.00',issued_amount_usd:'980.00'}})}
 if(url.pathname.endsWith('/ai-credit/reconcile')){grant.status='issued';return send(grant)}
 if(url.pathname.endsWith('/ai-credit/revoke')){if(failRevoke)return route.fulfill({json:{status:'fail',message:'此福利已兑换，不能停用'}});grant.status='revoked';return send(grant)}
 if(url.pathname.endsWith('/legacy/preview'))return send({eligible_count:1,total_amount_usd:'10.00',records:[grant],truncated:true})
 if(url.pathname.endsWith('/legacy/backfill'))return send({created:batches++===0?1:0,skipped:batches===1?0:1,has_more:false})
 throw new Error('Unexpected API '+url.pathname)
})
const button=name=>page.getByRole('button',{name,exact:true})
const shot = async name => {
 await page.waitForFunction(() => !document.querySelector('.el-message'))
 await page.screenshot({path:join(artifacts,name),fullPage:true,animations:'disabled'})
}
const field=label=>page.locator('.el-form-item').filter({has:page.locator('.el-form-item__label').getByText(label,{exact:true})}).locator('input').first()
try {
 const url=origin+'/assets/admin/#/marketing/ai-credit'
 await page.goto(url)
 await button('保存活动配置').waitFor()
 await page.getByText('$1260.00',{exact:true}).waitFor()
 await page.waitForFunction(() => document.querySelector('.el-switch input')?.getAttribute('aria-checked') === 'true' || document.querySelector('.el-switch.is-checked'))
 await page.locator('.rule-note').filter({hasText:'仅纳入北京时间 2026-10-20 00:00:00 起（含当天）'}).waitFor()
 await shot('desktop-settings.png')
 await button('保存活动配置').scrollIntoViewIfNeeded();await shot('desktop-settings-bottom.png')
 assert.equal(await field('桥接 Token').inputValue(),'')
 await button('保存活动配置').click()
 await page.getByText('配置版本 2',{exact:false}).waitFor()
 assert.equal('bridge_token' in saves[0],false)
 assert.equal(saves[0].rate_usd_per_gb,'0.05');assert.equal(saves[0].min_paid_cents,1500);assert.equal(saves[0].include_balance,false);assert.deepEqual(saves[0].plan_ids,[1])
 await field('桥接 Token').fill('new-fixture-token-with-at-least-32-characters')
 await button('保存活动配置').click()
 await page.getByText('配置版本 3',{exact:false}).waitFor()
 assert.equal(saves[1].bridge_token,'new-fixture-token-with-at-least-32-characters');assert.equal(await field('桥接 Token').inputValue(),'')
 await field('面额比例（USD / GB）').fill('0')
 await button('保存活动配置').click()
 await page.getByText('面额比例必须大于 0',{exact:true}).waitFor();assert.equal(saves.length,2)
 await field('面额比例（USD / GB）').fill('0.05')
 await page.getByRole('tab',{name:'领取台账'}).click()
 await page.getByRole('textbox',{name:'用户 ID',exact:true}).fill('42')
 await Promise.all([page.waitForResponse(r=>r.url().includes('user_id=42')),button('查询').click()])
 await Promise.all([page.waitForResponse(r=>r.url().includes('current=2')),page.getByRole('button',{name:'Go to next page'}).click()])
 await shot('desktop-ledger.png')
 await button('核对原请求').click();await button('开始核对').click();await page.getByRole('dialog',{name:'福利记录详情'}).waitFor();await page.getByRole('dialog',{name:'福利记录详情'}).getByRole('button',{name:'Close this dialog'}).click()
 await button('停用').click();await page.locator('.el-message-box input').fill('用户申请');await button('确认停用').click();await page.getByText('此福利已兑换，不能停用',{exact:true}).waitFor()
 failRevoke=false
 await button('停用').click();await page.locator('.el-message-box input').fill('测试停用');await button('确认停用').click();await page.getByRole('dialog',{name:'福利记录详情'}).waitFor();await page.getByRole('dialog',{name:'福利记录详情'}).getByRole('button',{name:'Close this dialog'}).click()
 await page.getByRole('tab',{name:'历史补领'}).click();assert.equal(await button('执行本批补领').isEnabled(),false)
 await button('预览历史补领').click();await page.getByText('预览已截断，仍有更多记录').waitFor()
 await shot('desktop-preview.png')
 await button('执行本批补领').click();await button('取消').click();assert.equal(batches,0)
 await button('执行本批补领').click();await button('确认执行补领').click();await page.getByText('已创建 1 笔，跳过 0 笔。',{exact:false}).waitFor();assert.equal(await button('执行本批补领').isEnabled(),false)
 await button('预览历史补领').click();await button('执行本批补领').click();await button('确认执行补领').click();await page.getByText('已创建 0 笔，跳过 1 笔。',{exact:false}).waitFor()
 await page.setViewportSize({width:390,height:844})
 await page.getByRole('tab',{name:'活动配置'}).click();await page.locator('.admin-main').evaluate(el=>el.scrollTo(0,0));await shot('mobile-settings.png')
 await page.locator('.rule-note').scrollIntoViewIfNeeded();await shot('mobile-rules.png')
 await button('保存活动配置').scrollIntoViewIfNeeded();await shot('mobile-settings-bottom.png')
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),true)
 await page.getByRole('tab',{name:'领取台账'}).click();await shot('mobile-ledger.png')
 await page.locator('.pager').scrollIntoViewIfNeeded();await shot('mobile-ledger-bottom.png')
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),true)
 failRecords=true;await button('刷新台账').click();await page.getByText('台账暂时不可用').waitFor();failRecords=false;await button('重试台账统计').click();await page.getByText('$1260.00',{exact:true}).waitFor()
 assert.deepEqual(errors,[])
 console.log(JSON.stringify({url,screenshots:artifacts,checks:'settings/token/defaults/validation/filter/pagination/reconcile/revoke business error/revoke success/preview/cancel/backfill/repeat/mobile overflow/error retry',consoleErrors:errors,apiCalls:calls.length},null,2))
} finally {await browser.close();server.close()}
