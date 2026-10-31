import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { readFile, mkdir, writeFile } from 'node:fs/promises'
import { dirname, extname, resolve, join } from 'node:path'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
const require = createRequire(import.meta.url)
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright')
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const build = resolve(root, process.env.ADMIN_BUILD_OUT_DIR || 'dist-activities-review')
const artifacts = join(build, 'browser-evidence', 'activities')
await mkdir(artifacts, { recursive: true })
const mime = { '.html':'text/html', '.js':'text/javascript', '.css':'text/css', '.svg':'image/svg+xml', '.webp':'image/webp' }
const server = createServer(async (req,res) => {
 try {
  const path = decodeURIComponent(new URL(req.url,'http://localhost').pathname).replace(/^\/assets\/admin\/?/,'') || 'index.html'
  const file = resolve(build,path); assert.ok(file.startsWith(build + '/') || file.startsWith(build + '\\'))
  res.setHeader('Content-Type',mime[extname(file)] || 'application/octet-stream');res.end(await readFile(file))
 } catch {res.writeHead(404);res.end('Not found')}
})
await new Promise(r=>server.listen(0,'127.0.0.1',r))
const origin='http://127.0.0.1:'+server.address().port
const browser=await chromium.launch({headless:true,...(process.env.BROWSER_EXECUTABLE?{executablePath:process.env.BROWSER_EXECUTABLE}:{})})
const context=await browser.newContext({viewport:{width:1440,height:1080},timezoneId:'Asia/Shanghai'})
const page=await context.newPage();page.setDefaultTimeout(10000)
const errors=[],calls=[],saves=[],expectedHttpErrors=[]
page.on('pageerror',e=>errors.push(e.message))
page.on('console',m=>{if(m.type()==='error') { if(m.text().includes('409')) expectedHttpErrors.push(m.text()); else errors.push(m.text()) }})
const now=Math.floor(Date.now()/1000)
let records=Array.from({length:13},(_,i)=>({id:i+1,version:1,code:null,source:['invite','ai_credit','ai_topup','custom'][i%4],title:['邀请好友，共享好礼','AI 福利，每日灵感','充值享额外流量','周末专属福利','探索更多精彩'][i%5]+(i>4?' '+(i+1):''),badge:'专属福利',summary:'把值得分享的福利带给每一位用户。活动详情与权益在这里清晰呈现。',benefits:['邀请好友，收获更多权益','好友同行，让每一次连接更有价值'],image_url:'/activities/invite-rewards.webp',target_url:'/dashboard/finance/invite',button_text:'查看福利',theme:['navy','indigo','teal'][i%3],sort:i,published:i%5!==0,starts_at:i%5===1?now+3600:null,ends_at:i%5===3?now-1:null,status:['draft','scheduled','active','ended','unavailable'][i%5],created_at:now,updated_at:now}))
let failFetch=true,failSave=false,conflictSave=false,conflictAction=false,delaySave=0,delayFetch=0
await context.addInitScript(()=>{window.settings={secure_path:'mock-admin',title:'Xboard Admin'};sessionStorage.setItem('xboard_admin_auth','synthetic-test-token')})
await page.route('**/*',async route=>{
 const req=route.request(),url=new URL(req.url());if(url.origin!==origin)return route.abort()
 if(url.pathname.startsWith('/activities/')) {
  // A successful response with invalid image bytes exercises the graceful image fallback without fabricating artwork.
  return route.fulfill({status:200,contentType:'image/webp',body:'image unavailable in fixture'})
 }
 if(!url.pathname.startsWith('/api/'))return route.continue()
 const body=req.postDataJSON();calls.push({path:url.pathname,query:url.search,body})
 const send=data=>route.fulfill({json:{status:'success',data}})
 const fail=message=>route.fulfill({json:{status:'fail',message}})
 if(url.pathname.endsWith('/getSystemStatus'))return send({})
 assert.ok(url.pathname.startsWith('/api/v2/mock-admin/activities/'), 'Unexpected API: ' + url.pathname)
 if(url.pathname.endsWith('/fetch')) {
  if(delayFetch)await new Promise(r=>setTimeout(r,delayFetch))
  if(failFetch)return fail('列表暂时不可用')
  const current=Number(url.searchParams.get('current')),size=Number(url.searchParams.get('page_size'))
  return send({records:records.slice((current-1)*size,current*size),total:records.length,current,page_size:size})
 }
 if(url.pathname.endsWith('/save')) {
  saves.push(body);if(delaySave)await new Promise(r=>setTimeout(r,delaySave))
  if(failSave)return fail('保存暂时失败')
  if(conflictSave){conflictSave=false;const row=records.find(r=>r.id===body.id);row.version++;row.summary='其他管理员更新的说明';return route.fulfill({status:409,json:{status:'fail',message:'活动版本已更新'}})}
  const row=body.id?records.find(r=>r.id===body.id):null
  if(row){assert.equal(body.version,row.version);Object.assign(row,body,{version:row.version+1})}
  else records.unshift({...body,id:100,version:1,status:'draft',created_at:now,updated_at:now})
  return send(true)
 }
 const row=records.find(r=>r.id===body.id)
 if(conflictAction){conflictAction=false;row.version++;return route.fulfill({status:409,json:{status:'fail',message:'版本冲突'}})}
 assert.equal(body.version,row.version)
 if(url.pathname.endsWith('/publish')){Object.assign(row,{published:body.published,status:body.published?(row.starts_at>now?'scheduled':'active'):'draft',version:row.version+1});return send(row)}
 if(url.pathname.endsWith('/delete')){records=records.filter(r=>r.id!==body.id);return send(true)}
 throw new Error('Unexpected API '+url.pathname)
})
const button=name=>page.getByRole('button',{name,exact:true})
const input=name=>page.getByRole('textbox',{name,exact:true})
const shot=async name=>{await page.waitForFunction(()=>!document.querySelector('.el-message'));await page.screenshot({path:join(artifacts,name+'.png'),fullPage:true,animations:'disabled'})}
const card=()=>page.locator('.activity-record').first()
const closed=()=>page.getByRole('dialog').waitFor({state:'hidden'})
try {
 const url=origin+'/assets/admin/#/marketing/activities';await page.goto(url)
 await page.getByText('列表暂时不可用',{exact:true}).waitFor();failFetch=false;await button('刷新列表').click();await card().waitFor()
 assert.equal(await page.locator('.activity-record').count(),12)
 for(const status of ['草稿','定时','生效','结束','不可用'])assert.ok(await page.locator('.record-heading').getByText(status,{exact:true}).count())
 await shot('desktop-list')
 await page.getByRole('button',{name:'Go to next page'}).click();await page.waitForFunction(()=>document.querySelectorAll('.activity-record').length===1)
 await page.getByRole('button',{name:'Go to previous page'}).click();await page.waitForFunction(()=>document.querySelectorAll('.activity-record').length===12)
 await button('创建活动').click();await button('保存活动').click();await page.getByText('请填写活动标题和按钮文字',{exact:true}).waitFor()
 await input('活动标题').fill('测试邀友活动');await input('角标').fill('好友同行');await input('活动说明').fill('邀请好友，享 {invite_commission_percent}% 返佣奖励')
 await page.locator('.el-select').filter({has:page.getByRole('combobox',{name:'活动来源'})}).click();await page.getByRole('option',{name:'邀友',exact:true}).click()
 await button('添加权益').click();await input('权益 1').fill('首购赠 {invite_traffic_percent}% 流量');await button('添加权益').click();await input('权益 2').fill('随时邀请好友');await button('删除权益 2').click()
 await input('图片地址').fill('/activities/invite-rewards.webp');await page.getByText('图片暂未加载',{exact:true}).waitFor()
 await input('跳转地址').fill('javascript:alert(1)');await button('保存活动').click();await page.getByText('请填写有效图片地址和跳转地址',{exact:false}).waitFor();assert.equal(saves.length,0)
 await input('跳转地址').fill('/dashboard/finance/invite');await input('按钮文字').fill('邀请好友')
 await page.locator('.el-select').filter({has:page.getByRole('combobox',{name:'展示主题'})}).click();await page.getByRole('option',{name:'青绿',exact:true}).click();await page.getByRole('spinbutton',{name:'排序',exact:true}).fill('15')
 await page.getByLabel('开始时间',{exact:true}).fill('2030-01-02T12:00');await page.getByLabel('结束时间',{exact:true}).fill('2030-01-02T12:00');await button('保存活动').click();await page.getByText('结束时间必须晚于开始时间',{exact:true}).waitFor()
 await page.getByLabel('结束时间',{exact:true}).fill('2030-01-03T12:00');await page.locator('.el-overlay-dialog').evaluate(el=>el.scrollTo(0,0));await shot('desktop-editor')
 failSave=true;await button('保存活动').click();await page.getByText('保存暂时失败',{exact:true}).waitFor();assert.equal(await input('活动标题').inputValue(),'测试邀友活动')
 failSave=false;delaySave=180;const before=saves.length;await button('保存活动').dblclick();await closed();delaySave=0;assert.equal(saves.length,before+1)
 assert.equal(saves.at(-1).published,false);assert.equal(saves.at(-1).starts_at,Date.parse('2030-01-02T12:00:00+08:00')/1000);assert.equal(saves.at(-1).benefits.length,1);assert.equal(saves.at(-1).source,'invite');assert.equal(saves.at(-1).theme,'teal')
 await card().getByRole('button',{name:'发布',exact:true}).click();await button('确认发布').click();await card().getByText('定时',{exact:true}).waitFor()
 await card().getByRole('button',{name:'下架',exact:true}).click();await button('确认下架').click();await card().getByText('草稿',{exact:true}).waitFor()
 await card().getByRole('button',{name:'编辑与预览'}).click();await input('活动标题').fill('保留未保存编辑');conflictSave=true;await button('保存活动').click();await page.getByText('检测到版本冲突，未保存的编辑已保留。',{exact:true}).waitFor();assert.equal(await input('活动标题').inputValue(),'保留未保存编辑');assert.equal(await button('保存活动').isEnabled(),false)
 await button('查看最新版本').click();await button('保留当前编辑并采用最新版本').waitFor();assert.match(await page.locator('.latest-record').innerText(),/其他管理员更新的说明/);await shot('desktop-conflict')
 await button('保留当前编辑并采用最新版本').click();await page.getByLabel('开始时间',{exact:true}).fill('1970-01-01T08:00');await page.getByLabel('结束时间',{exact:true}).fill('');await button('保存活动').click();await closed();assert.equal(saves.at(-1).starts_at,0);assert.equal(saves.at(-1).ends_at,null)
 await card().getByRole('button',{name:'编辑与预览'}).click();assert.equal(await page.getByLabel('开始时间',{exact:true}).inputValue(),'1970-01-01T08:00');await button('关闭').click();await closed()
 conflictAction=true;await card().getByRole('button',{name:'发布',exact:true}).click();await button('确认发布').click();await page.getByText('活动已更新，本次操作未执行。请刷新列表，核对最新状态后重试。',{exact:true}).waitFor();await button('刷新列表').click();await card().getByRole('button',{name:'发布',exact:true}).click();await button('确认发布').click();await card().getByText('生效',{exact:true}).waitFor()
 await card().getByRole('button',{name:'删除',exact:true}).click();await button('取消').click();assert.equal(records.length,14)
 await card().getByRole('button',{name:'删除',exact:true}).click();await button('确认删除').click();await page.waitForFunction(()=>!document.querySelector('.activity-record')?.textContent.includes('保留未保存编辑'));assert.equal(records.length,13)
 await page.setViewportSize({width:390,height:844});await page.locator('.admin-main').evaluate(el=>el.scrollTo(0,0));await shot('mobile-list');assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true)
 await card().getByRole('button',{name:'编辑与预览'}).click();await shot('mobile-editor');assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true)
 await page.locator('.campaign-preview').scrollIntoViewIfNeeded();await shot('mobile-preview')
 await input('活动标题').fill('另存草稿内容');conflictSave=true;await button('保存活动').click();await button('另存为草稿').waitFor();await button('另存为草稿').click();await button('保存活动').click();await closed();assert.equal(saves.at(-1).published,false);assert.equal(saves.at(-1).code,null);assert.equal('id' in saves.at(-1),false)
 failFetch=true;await button('刷新列表').click();await page.getByText('列表暂时不可用',{exact:true}).waitFor();assert.equal(await page.locator('.activity-record').count(),0)
 failFetch=false;records=[];await button('刷新列表').click();await page.getByText('还没有活动，创建第一份精彩内容',{exact:true}).waitFor()
 // A late list response must not mutate an unmounted page.
 delayFetch=150;await button('刷新列表').click();await page.evaluate(()=>location.hash='#/fixture-unmounted');await page.waitForTimeout(200)
 // Other feature routes are outside this fixture; avoid starting their API calls.
 assert.deepEqual(errors,[])
 await writeFile(join(artifacts,'result.json'),JSON.stringify({url,viewports:['1440x1080','390x844'],calls:calls.length,saves:saves.length,expectedHttp409:expectedHttpErrors.length,errors},null,2))
 console.log(JSON.stringify({url,artifacts,calls:calls.length,saves:saves.length,errors}))
} finally {await browser.close();await new Promise(r=>server.close(r))}
