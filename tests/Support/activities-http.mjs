import {createServer} from 'node:http'
import {spawn} from 'node:child_process'
import {openSync,closeSync} from 'node:fs'
import {readFile,mkdir} from 'node:fs/promises'
import {resolve,extname,sep} from 'node:path'
const root=resolve('.'),output=resolve('output/activities-e2e');await mkdir(output,{recursive:true})
const fd=openSync(resolve(output,'php-http.log'),'a'),php=spawn('php',['-S','127.0.0.1:18912',resolve('tests/Support/activities-http.php')],{cwd:root,stdio:['ignore',fd,fd]});closeSync(fd)
const mime={'.html':'text/html','.js':'text/javascript','.css':'text/css','.webp':'image/webp','.png':'image/png','.svg':'image/svg+xml','.ico':'image/x-icon'}
const servers=[]
for(const[directory,port,admin]of[['E:/code/vue/vue-xboard-theme-micah/output/activities-review',18915,false],['E:/code/php/Xboard-new/admin-frontend/dist-activities-review',18916,true]]){
 const server=createServer(async(req,res)=>{try{
 const url=new URL(req.url,'http://127.0.0.1')
 if(url.pathname.startsWith('/api/')){let body='';for await(const chunk of req)body+=chunk;const headers={...req.headers};delete headers.host;delete headers.connection;delete headers['content-length'];const upstream=await fetch('http://127.0.0.1:18912'+url.pathname+url.search,{method:req.method,headers,body:['GET','HEAD'].includes(req.method)?undefined:body});res.writeHead(upstream.status,{'content-type':upstream.headers.get('content-type')||'application/json','cache-control':'no-store'});return res.end(Buffer.from(await upstream.arrayBuffer()))}
 const relative=decodeURIComponent(url.pathname).replace(admin?/^\/assets\/admin\/?/:/^\//,'').replace(/^\//,'');let file=resolve(directory,relative||'index.html');if(file!==resolve(directory)&&!file.startsWith(resolve(directory)+sep)){res.writeHead(400);return res.end()}
 let bytes;try{bytes=await readFile(file)}catch{if(extname(file)){res.writeHead(404);return res.end()}file=resolve(directory,'index.html');bytes=await readFile(file)}res.writeHead(200,{'content-type':mime[extname(file)]||'application/octet-stream'});res.end(bytes)
 }catch(e){res.writeHead(500);res.end('Local fixture: '+e.message)}})
 await new Promise(r=>server.listen(port,'127.0.0.1',r));servers.push(server)
}
console.log(JSON.stringify({ready:true,backend:'http://127.0.0.1:18912',user:'http://127.0.0.1:18915/dashboard/home',admin:'http://127.0.0.1:18916/assets/admin/#/marketing/activities',transport:'real Laravel isolated SQLite, no browser business API mock'}))
await new Promise(r=>{process.once('SIGINT',r);process.once('SIGTERM',r)})
php.kill();for(const s of servers)s.close()
