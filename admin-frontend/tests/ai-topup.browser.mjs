import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { readFile, mkdir } from 'node:fs/promises'
import { dirname, extname, resolve, join } from 'node:path'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'

const require = createRequire(import.meta.url)
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright')
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const build = resolve(root, process.env.ADMIN_BUILD_OUT_DIR || 'dist-ai-topup-review')
const artifacts = join(root, 'dist-ai-topup-review', 'browser-evidence', 'ai-topup')
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
const settings = {enabled:true,provider_ready:true,tiers:[[0,'0.150'],[1000,'0.145'],[3000,'0.135'],[5000,'0.130'],[6000,'0.127'],[8000,'0.123'],[9000,'0.119'],[10000,'0.115'],[15000,'0.105']].map(([min_usd,unit_price_cny])=>({min_usd,unit_price_cny})),min_usd:1,max_usd:1000000,bonus_rate_bps:150,traffic_gb_per_cny:10,order_ttl_minutes:30,site_url:'',pricing_url:'',transparency_url:'',version:1}
const order={id:1,user_id:42,email:'creator@example.com',trade_no:'AIT202610250001',status:'paid',issue_status:'reconciling',amount_usd:'200',unit_price_cny:'0.150',total_amount:3000,handling_amount:60,payment_id:1,created_at:1792800000,expires_at:1792801800,paid_at:1792800100,issued_at:null,redeemed_at:null,traffic_activated_at:null,gift_traffic_bytes:4831838208,last_error:null}
let failSettings=true,failSave=true,failRecords=false,failReconcile=true
await context.addInitScript(()=>{window.settings={secure_path:'mock-admin',title:'Xboard Admin'};sessionStorage.setItem('xboard_admin_auth','synthetic-test-token')})
await page.route('**/*',async route=>{
 const req=route.request(),url=new URL(req.url())
 if(url.origin!==origin) return route.abort()
 if(!url.pathname.startsWith('/api/'))return route.continue()
 calls.push({path:url.pathname,query:url.search,body:req.postDataJSON()})
 const send=data=>route.fulfill({json:{status:'success',data}})
 const fail=message=>route.fulfill({json:{status:'fail',message}})
 if(url.pathname.endsWith('/getSystemStatus'))return send({})
 if(url.pathname.endsWith('/ai-topup/settings')){
   if(req.method()==='POST'){if(failSave)return fail('保存暂时失败，请重试');saves.push(req.postDataJSON());Object.assign(settings,req.postDataJSON());settings.version++}
   if(failSettings)return fail('配置暂时不可用')
   return send(settings)
 }
 if(url.pathname.endsWith('/ai-topup/records')){if(failRecords)return fail('订单暂时不可用');return send({records:[{...order,id:Number(url.searchParams.get('current'))===2?21:1}],total:21,current:Number(url.searchParams.get('current')),page_size:20})}
 if(url.pathname.endsWith('/ai-topup/reconcile')){if(failReconcile)return fail('提供方暂时不可用，可重试核对');order.issue_status='issued';order.issued_at=1792800200;return send(order)}
 throw new Error('Unexpected API '+url.pathname)
})
const button=name=>page.getByRole('button',{name,exact:true})
const shot=async name=>{await page.waitForFunction(()=>!document.querySelector('.el-message'));await page.screenshot({path:join(artifacts,name),fullPage:true,animations:'disabled'})}
try {
 const url=origin+'/assets/admin/#/marketing/ai-topup'
 await page.goto(url)
 await page.getByText('配置暂时不可用',{exact:true}).waitFor()
 assert.equal(await button('保存充值配置').isEnabled(),false)
 failSettings=false;await button('重新加载配置').click()
 await page.getByText('提供方已就绪',{exact:true}).waitFor()
 await page.getByText('赠送比例（基点）· 1.5%',{exact:true}).waitFor()
 assert.equal(await page.locator('.tier-row').count(),9)
 assert.equal(await page.locator('input[type=password]').count(),0)
 await page.getByRole('spinbutton',{name:'最小面额',exact:true}).fill('2')
 await button('保存充值配置').click();await page.getByText('保存暂时失败，请重试',{exact:true}).waitFor()
 assert.equal(await page.getByRole('spinbutton',{name:'最小面额',exact:true}).inputValue(),'2')
 failSave=false;await button('保存充值配置').click();await page.getByText('配置版本 2',{exact:false}).waitFor()
 assert.equal(saves[0].min_usd,2);assert.equal(saves[0].tiers.length,9);assert.equal(saves[0].bonus_rate_bps,150)
 assert.equal(saves[0].order_ttl_minutes,30);assert.equal(saves[0].traffic_gb_per_cny,10)
 assert.equal('provider_ready' in saves[0],false);assert.equal('bridge_token' in saves[0],false)
 await button('添加阶梯').click();assert.equal(await page.locator('.tier-row').count(),10)
 await page.getByRole('button',{name:'删除阶梯 10',exact:true}).click()
 await page.getByRole('textbox',{name:'阶梯单价 1',exact:true}).fill('0')
 await button('保存充值配置').click();await page.getByText('阶梯须为 1 至 100 行',{exact:false}).waitFor();assert.equal(saves.length,1)
 await page.getByRole('textbox',{name:'阶梯单价 1',exact:true}).fill('0.150');await button('保存充值配置').click();await page.getByText('配置版本 3',{exact:false}).waitFor()
 await page.locator('.admin-main').evaluate(el=>el.scrollTo(0,0));await shot('desktop-settings.png')
 await page.getByRole('tab',{name:'充值订单'}).click()
 await page.getByRole('textbox',{name:'用户 ID',exact:true}).fill('42');await page.getByRole('textbox',{name:'订单号',exact:true}).fill('AIT202610250001')
 await page.locator('.el-select').filter({has:page.getByRole('combobox',{name:'支付状态'})}).click();await page.getByRole('option',{name:'已支付',exact:true}).click()
 await page.locator('.el-select').filter({has:page.getByRole('combobox',{name:'发放状态'})}).click();await page.getByRole('option',{name:'核对中',exact:true}).click()
 await Promise.all([page.waitForResponse(r=>r.url().includes('issue_status=reconciling')),button('查询').click()])
 assert.ok(calls.some(c=>c.query.includes('user_id=42')&&c.query.includes('trade_no=AIT202610250001')&&c.query.includes('status=paid')))
 await Promise.all([page.waitForResponse(r=>r.url().includes('current=2')),page.getByRole('button',{name:'Go to next page'}).click()])
 await page.getByText('本金 ¥30.00',{exact:true}).waitFor();await page.getByText('手续费 ¥0.60',{exact:true}).waitFor();await page.locator('.el-table .cell').filter({hasText:'4.5 GB'}).waitFor()
 await shot('desktop-orders.png')
 await button('核对原请求').click();await button('开始核对').click();await page.getByText('提供方暂时不可用，可重试核对',{exact:true}).waitFor()
 failReconcile=false;await button('核对原请求').click();await button('开始核对').click();await page.getByRole('dialog',{name:'充值订单详情'}).waitFor()
 assert.ok(calls.some(c=>c.path.endsWith('/reconcile')&&c.body.id===21))
 await page.getByRole('dialog',{name:'充值订单详情'}).getByRole('button',{name:'Close this dialog'}).click()
 failRecords=true;await button('刷新订单').click();await page.getByText('订单暂时不可用',{exact:true}).waitFor()
 failRecords=false;await button('重试订单').click();await page.locator('.el-table').getByText('已发放',{exact:true}).waitFor()
 await page.setViewportSize({width:390,height:844});await page.getByRole('tab',{name:'充值配置'}).click();await page.locator('.admin-main').evaluate(el=>el.scrollTo(0,0));await shot('mobile-settings.png')
 await page.locator('.tier-head').scrollIntoViewIfNeeded();await shot('mobile-tiers.png')
 await button('保存充值配置').scrollIntoViewIfNeeded();await shot('mobile-save.png')
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true)
 await page.getByRole('tab',{name:'充值订单'}).click();await page.locator('.admin-main').evaluate(el=>el.scrollTo(0,0));await shot('mobile-orders.png')
 await button('重置').click();await page.locator('.el-table').getByText('已发放',{exact:true}).waitFor()
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true)
 await page.locator('.el-table__body-wrapper .el-scrollbar__wrap').evaluate(el=>el.scrollLeft=el.scrollWidth)
 await button('详情').click();await page.getByRole('dialog',{name:'充值订单详情'}).waitFor();await shot('mobile-order-detail.png')
 await page.getByRole('dialog',{name:'充值订单详情'}).getByRole('button',{name:'Close this dialog'}).click()
 assert.deepEqual(errors,[])
 console.log(JSON.stringify({url,artifacts,saves:saves.length,requests:calls.length,consoleErrors:errors,result:'PASS'},null,2))
} finally {await browser.close();await new Promise(resolve=>server.close(resolve))}
