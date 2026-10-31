import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { spawn } from 'node:child_process'
import { createHash, randomUUID } from 'node:crypto'
import { openSync, closeSync } from 'node:fs'
import { readFile, mkdir, writeFile } from 'node:fs/promises'
import { resolve, extname } from 'node:path'
import { pathToFileURL } from 'node:url'

const root=resolve(process.argv[2]||'.'), output=resolve(root,'output/ai-topup-e2e')
const pluginRoot=resolve(root,'../../go/sub2api/account-batch-tester')
await mkdir(output,{recursive:true})
const upstreamRecords=new Map();let loseNext=false
const json=(res,status,data)=>{res.writeHead(status,{'Content-Type':'application/json'});res.end(JSON.stringify(data))}
const upstream=createServer(async(req,res)=>{
 const url=new URL(req.url,'http://127.0.0.1:18684');let raw='';for await(const chunk of req)raw+=chunk
 const body=raw?JSON.parse(raw):{}
 if(url.pathname==='/fixture/stats')return json(res,200,{created:upstreamRecords.size})
 if(url.pathname==='/fixture/lose-next'){loseNext=true;return json(res,200,{ok:true})}
 if(url.pathname==='/fixture/redeem'){const r=upstreamRecords.get(body.external_ref);if(!r)return json(res,404,{});r.status='used';r.used_at=new Date().toISOString();return json(res,200,{ok:true})}
 const prefix='/api/v1/admin/redeem-codes/external-rewards'
 if(req.headers['x-api-key']!=='synthetic-admin-api-key')return json(res,401,{})
 if(url.pathname===prefix&&req.method==='POST'){
  let r=upstreamRecords.get(body.external_ref)
  if(r&&r.value!==Number(body.amount_usd))return json(res,409,{})
  if(!r){r={external_ref:body.external_ref,id:upstreamRecords.size+1,code:'ISOLATED-NOT-REDEEMABLE-'+(upstreamRecords.size+1),type:'balance',value:Number(body.amount_usd),status:'unused',expires_at:null,used_at:null};upstreamRecords.set(body.external_ref,r)}
  if(loseNext){loseNext=false;return json(res,503,{message:'synthetic response lost after durable commit'})}
  return json(res,200,{code:0,data:r})
 }
 if(url.pathname.startsWith(prefix+'/')){const r=upstreamRecords.get(decodeURIComponent(url.pathname.slice(prefix.length+1)));return json(res,r?200:404,r?{code:0,data:r}:{})}
 json(res,404,{})
})
const sleep=ms=>new Promise(r=>setTimeout(r,ms))
const base='http://127.0.0.1:18682', signingKey='fixture-signing-key'
const sign=params=>createHash('md5').update(Object.keys(params).sort().map(k=>encodeURIComponent(k)+'='+encodeURIComponent(params[k])).join('&').replaceAll('%20','+')+signingKey).digest('hex')
async function receipt(trade,money='30.00'){
 const data={out_trade_no:trade,trade_no:'isolated-receipt-'+trade,money,trade_status:'TRADE_SUCCESS'};data.sign=sign(data);data.sign_type='MD5'
 return fetch(base+'/api/v1/guest/payment/notify/EPay/fixture-epay',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)})
}
const webServers=[]
const serveAssets=(directory,admin=false)=>createServer(async(req,res)=>{
 try{
  const url=new URL(req.url,'http://127.0.0.1')
  if(url.pathname.startsWith('/api/')){
   let body='';for await(const chunk of req)body+=chunk
   const headers={...req.headers};delete headers.host;delete headers.connection;delete headers['content-length']
   const upstream=await fetch(base+url.pathname+url.search,{method:req.method,headers,body:['GET','HEAD'].includes(req.method)?undefined:body})
   res.writeHead(upstream.status,{'Content-Type':upstream.headers.get('content-type')||'application/json','Cache-Control':'no-store'});return res.end(Buffer.from(await upstream.arrayBuffer()))
  }
  const relative=decodeURIComponent(url.pathname).replace(admin?/^\/assets\/admin\/?/:/^\//,'')
  const file=resolve(directory,relative||'index.html');if(!file.startsWith(resolve(directory)))return json(res,400,{})
  let buffer;try{buffer=await readFile(file)}catch{if(extname(file)){res.writeHead(404);return res.end()}buffer=await readFile(resolve(directory,'index.html'))}
  const types={'.js':'text/javascript','.css':'text/css','.html':'text/html','.svg':'image/svg+xml','.png':'image/png','.ico':'image/x-icon'}
  res.setHeader('Content-Type',types[extname(file)]||'text/html');res.end(buffer)
 }catch(e){res.writeHead(500);res.end('Local verification server: '+e.message)}
})
let app,db,php
try{
 await new Promise(r=>upstream.listen(18684,'127.0.0.1',r))
 Object.assign(process.env,{NODE_ENV:'test',SUB2API_BASE_URL:'http://127.0.0.1:18684',XBOARD_REWARDS_TOKEN:'synthetic-xboard-rewards-token-32-characters',XBOARD_REWARDS_ADMIN_API_KEY:'synthetic-admin-api-key',SQLITE_PATH:resolve(output,'plugin.sqlite'),PLUGIN_SECRET:'synthetic-plugin-secret-32-characters-only',FREE_AUTO_ACTIVE_ENABLED:'false',PLUS_USAGE_ALERTS_ENABLED:'false',PLUS_EXPIRY_ENABLED:'false',PLUS_RATE_LIMIT_REFRESH_ENABLED:'false'})
 const {register}=await import(pathToFileURL(resolve(pluginRoot,'node_modules/tsx/dist/esm/api/index.mjs')).href);register()
 const modules=await import(pathToFileURL(resolve(pluginRoot,'src/server/app.ts')).href)
 db=(await import(pathToFileURL(resolve(pluginRoot,'src/server/database.ts')).href)).db
 app=await modules.buildApp()
 app.addContentTypeParser('application/x-www-form-urlencoded',{parseAs:'string'},(req,body,done)=>done(null,body))
 app.get('/submit.php',async(req,res)=>res.header('Content-Security-Policy',"default-src 'self'; form-action 'self' http://127.0.0.1:18685").type('text/html').send('<!doctype html><meta charset="UTF-8"><h1>本地隔离支付网关</h1><form method="post" action="/fixture/pay?'+new URLSearchParams(req.query).toString().replaceAll('&','&amp;')+'"><button id="pay">确认支付测试订单</button></form>'))
 app.post('/fixture/pay',async(req,res)=>{const query=req.query;const r=await receipt(query.out_trade_no,query.money);if(r.ok&&query.return_url?.startsWith('http://127.0.0.1:18685/'))return res.redirect(query.return_url);return res.code(r.status).send(await r.text())})
 await app.listen({host:'127.0.0.1',port:18683})
 const fd=openSync(resolve(output,'php-http.log'),'a')
 php=spawn('php',['-S','127.0.0.1:18682',resolve(root,'tests/Support/ai-topup-http.php')],{cwd:root,stdio:['ignore',fd,fd]});closeSync(fd)
 for(let i=0;i<40;i++){try{await fetch(base+'/api/v1/user/ai-topup/overview');break}catch{await sleep(100)}}
 const session=JSON.parse(await readFile(resolve(output,'sessions.json'),'utf8'))
 async function api(path,body,admin=false){const r=await fetch(base+(admin?'/api/v2/fixture-admin':'/api/v1/user')+path,{method:body?'POST':'GET',headers:{Authorization:admin?session.admin_auth:session.user_auth,'Content-Type':'application/json'},body:body?JSON.stringify(body):undefined});const raw=await r.text();let d;try{d=JSON.parse(raw)}catch{throw new Error('Non JSON HTTP '+r.status+' '+path+': '+raw.slice(0,180))}return {...d,status:r.status}}
 if(process.argv.includes('--serve')){
  for(const[dir,port,admin]of [[resolve(root,'../../vue/vue-xboard-theme-micah/output/ai-topup-review'),18685,false],[resolve(root,'admin-frontend/dist-ai-topup-review'),18686,true]]){const web=serveAssets(dir,admin);await new Promise(r=>web.listen(port,'127.0.0.1',r));webServers.push(web)}
  console.log(JSON.stringify({ready:true,backend:base,bridge:'http://127.0.0.1:18683',transport:'real Laravel routes + signed Epay callback + real Fastify bridge + isolated HTTP upstream'}))
  await new Promise(r=>{process.once('SIGTERM',r);process.once('SIGINT',r)})
 }else{
  const overview=await api('/ai-topup/overview');assert.equal(overview.data.enabled,true);assert.equal(overview.data.provider_ready,true)
  const quote=await api('/ai-topup/quote',{amount_usd:200});assert.equal(quote.data.total_amount,3000);assert.equal(quote.data.gift_traffic_bytes,4831838208)
  for(const [usd,cents]of [[999,14985],[1000,14500],[3000,40500],[6000,76200],[15000,157500]])assert.equal((await api('/ai-topup/quote',{amount_usd:usd})).data.total_amount,cents)
  const key=randomUUID();const create=await api('/ai-topup/create',{amount_usd:200,idempotency_key:key});assert.equal(create.status,200);const trade=create.data.trade_no
  assert.equal((await api('/ai-topup/create',{amount_usd:200,idempotency_key:key})).data.id,create.data.id)
  assert.equal((await api('/ai-topup/create',{amount_usd:201,idempotency_key:key})).status,409)
  const methods=await api('/order/getPaymentMethod');assert.ok(methods.data.length)
  const payment=await api('/ai-topup/checkout',{trade_no:trade,method:methods.data[0].id});assert.equal(payment.type,1);assert.ok(payment.data.includes('ai-topup'))
  assert.equal((await receipt(trade,'29.99')).status,400)
  assert.equal((await receipt(trade)).status,200);assert.equal((await receipt(trade)).status,200)
  let detail=await api('/ai-topup/detail?trade_no='+trade);assert.equal(detail.data.status,'paid');assert.equal(detail.data.issue_status,'issued');assert.ok(detail.data.code)
  const before=await api('/getSubscribe');const activation=await api('/ai-topup/traffic/activate',{trade_no:trade});assert.ok(activation.data.traffic_activated_at)
  await api('/ai-topup/traffic/activate',{trade_no:trade});const after=await api('/getSubscribe')
  assert.equal(after.data.transfer_enable-before.data.transfer_enable,4831838208);assert.equal(after.data.plan_id,before.data.plan_id);assert.equal(after.data.expired_at,before.data.expired_at)
  const records=await api('/ai-topup/records');assert.equal(records.data.records.find(r=>r.id===create.data.id).code,undefined)
  const admin=await api('/ai-topup/records?trade_no='+trade,undefined,true);assert.equal(admin.data.records[0].code,undefined)
  const can=await api('/ai-topup/create',{amount_usd:200,idempotency_key:randomUUID()});await api('/ai-topup/checkout',{trade_no:can.data.trade_no,method:methods.data[0].id});await api('/ai-topup/cancel',{trade_no:can.data.trade_no});assert.equal((await receipt(can.data.trade_no)).status,200);assert.equal((await api('/ai-topup/detail?trade_no='+can.data.trade_no)).data.issue_status,'issued')
  assert.equal(upstreamRecords.size,2)
  const report={passed:true,checks:['real-router-auth','integer-tier-pricing','idempotent-create','amount-conflict','existing-channel-checkout-return','signed-amount-mismatch-rejection','duplicate-paid-callback','automatic-bridge-issue','owner-code-only','activate-once','subscription-unchanged','cancelled-late-payment'],upstreamCreated:upstreamRecords.size}
  await writeFile(resolve(output,'http-report.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report))
 }
}finally{php?.kill();for(const web of webServers)await new Promise(r=>web.close(r));await app?.close();db?.close();await new Promise(r=>upstream.close(r))}
