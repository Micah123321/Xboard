import assert from 'node:assert/strict'
import { readFile, mkdir, writeFile } from 'node:fs/promises'
import { createServer } from 'node:http'
import { resolve, extname, sep } from 'node:path'
import { createRequire } from 'node:module'
const { chromium } = createRequire(import.meta.url)(process.env.PLAYWRIGHT_MODULE || 'playwright')
const root = resolve(process.env.ADMIN_BUILD_OUT_DIR || 'dist-node-updates-merged-review')
const output = resolve(root, 'browser-evidence'); await mkdir(output, { recursive: true })
const server = createServer(async (req, res) => {
 try { const pathname = decodeURIComponent(new URL(req.url, 'http://localhost').pathname); const file = resolve(root, (pathname.startsWith('/assets/admin/') ? pathname.slice('/assets/admin/'.length) : '') || 'index.html'); if (!file.startsWith(root + sep)) throw Error('path'); const body = await readFile(file); res.setHeader('Content-Type', ({ '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml' })[extname(file)] || 'application/octet-stream'); res.end(body) } catch { res.writeHead(404); res.end() }
})
await new Promise(done => server.listen(0, '127.0.0.1', done))
const origin = 'http://127.0.0.1:' + server.address().port
const pageUrl = origin + '/assets/admin/#/node-updates'
const stamp = '2026-10-31T16:00:00Z'
const installation = (id, ids, patch = {}) => ({ id, scope: { kind: 'legacy', node_ids: ids }, versions: { mi_node: 'v1.0.0', xbctl: 'v1.0.0' }, installed_sha256: { mi_node: 'a'.repeat(64), xbctl: 'b'.repeat(64) }, runtime: { os: 'linux', arch: 'amd64', init: 'systemd', container: false }, capability: { status: 'supported', reason: null }, last_seen_at: stamp, online: true, policy: { enabled: false, follow_latest: false, target_release_id: null, revision: 1 }, active_task_id: null, revoked_at: null, update: { latest_release_id: 'release', latest_version: 'v2.0.0', available: true, status: 'update_available' }, ...patch })
const row = (id, ids, managed = true, patch = {}) => ({ installation_id: id, installation: managed ? installation(id, ids) : null, discoveries: ids.map(node_id => ({ node_id, node_name: '测试节点 ' + node_id, machine_id: null, installation_id: id, version: 'v1.0.0', os: 'linux', arch: 'amd64', last_seen_at: stamp, auto_enroll_supported: true, enrollment_status: { state: 'registered', stage: 'poll', code: null, updated_at: stamp } })), nodes: ids.map(id => ({ id, name: '测试节点 ' + id, machine_id: null })), scope_mismatch_node_ids: [], last_seen_at: stamp, ...patch })
const rows = [row('install-a', [328, 376]), row('discovery-only', [500], false), row('managed-only', [], true), row('revoked', [501], true), row('mismatch', [502], true)]
rows[1].discoveries[0].enrollment_status = { state: 'retrying', stage: 'enroll', code: 'invalid_credentials', updated_at: stamp }
rows[3].installation.revoked_at = stamp; rows[4].installation.scope.node_ids = [999]; rows[4].scope_mismatch_node_ids = [502]
const second = row('install-b', [398, 433]); const writes = [], requests = [], errors = [], unexpected = []
const browser = await chromium.launch({ headless: true, ...(process.env.BROWSER_CHANNEL ? { channel: process.env.BROWSER_CHANNEL } : {}), ...(process.env.BROWSER_EXECUTABLE ? { executablePath: process.env.BROWSER_EXECUTABLE } : {}) })
try {
 const context = await browser.newContext({ viewport: { width: 1600, height: 1200 }, serviceWorkers: 'block' })
 await context.addInitScript(() => { window.settings = { secure_path: 'fixture', title: 'Xboard Admin' }; sessionStorage.setItem('xboard_admin_auth', 'Bearer synthetic-fixture') })
 await context.route('**/*', async route => {
  const request = route.request(), url = new URL(request.url());
  if (url.pathname.includes('/api/')) {
   requests.push(url.pathname); const endpoint = url.pathname.split('/server/update')[1]; let data = {}
   if (endpoint === '/overview') { const page = Number(url.searchParams.get('page') || 1); data = { items: page === 1 ? rows : [second], page, page_size: 20, total: 21 } }
   else if (endpoint === '/settings') data = { enabled: false, revision: 1, max_concurrency: 5 }
   else if (endpoint === '/release-source') data = { latest: { id: 'release', version: 'v2.0.0' }, url: 'https://fixture.invalid/releases', checked_at: stamp, error: null }
   else if (endpoint === '/releases') data = { items: [], page: 1, page_size: 20, total: 0 }
   else if (endpoint === '/installations/policies') { const body = request.postDataJSON(); writes.push(body); data = { items: body.installations.map(item => { const target = [...rows, second].find(row => row.installation_id === item.id).installation; target.policy = { enabled: body.enabled, follow_latest: body.enabled, target_release_id: null, revision: item.revision + 1 }; return { id: item.id, policy: target.policy } }), updated_count: body.installations.length, global_enabled: false } }
   else if (endpoint) unexpected.push(endpoint)
   await route.fulfill({ json: { data } }); return
  }
  if (url.origin === origin && request.method() === 'GET') return route.continue()
  unexpected.push(request.url()); await route.abort()
 })
 const page = await context.newPage(); page.on('pageerror', e => errors.push(e.message)); page.on('console', m => { if (m.type() === 'error') errors.push(m.text()) })
 await page.goto(pageUrl, { waitUntil: 'networkidle' })
 const table = page.locator('.overview-table'); await table.getByText('测试节点 328', { exact: true }).waitFor()
 assert.equal(await table.locator('.el-table__body tbody > tr').count(), 5)
 const a = table.locator('.el-table__row').filter({ hasText: '测试节点 328' }); assert.match(await a.innerText(), /376/)
 await a.locator('.el-checkbox').click()
 await page.locator('.el-pagination').first().getByRole('button', { name: 'Go to next page' }).click()
 await table.getByText('测试节点 398', { exact: true }).waitFor(); await table.locator('.el-table__row').filter({ hasText: '测试节点 398' }).locator('.el-checkbox').click()
 await page.getByRole('button', { name: '一键开启自动更新', exact: true }).click(); await page.getByText('已为 2 个安装', { exact: false }).waitFor()
 assert.deepEqual(writes[0].installations.map(i => i.id), ['install-a', 'install-b'])
 await page.locator('.el-pagination').first().getByRole('button', { name: 'Go to previous page' }).click(); await a.waitFor()
 await table.scrollIntoViewIfNeeded(); await page.waitForTimeout(400); await page.screenshot({ path: resolve(output, 'desktop.png'), fullPage: true })
 await a.getByRole('button', { name: '在线更新', exact: true }).click(); let dialog = page.getByRole('dialog'); await dialog.getByText('安装 ID：install-a', { exact: false }).waitFor(); await dialog.getByRole('button', { name: 'Close this dialog' }).click()
 await table.locator('.el-table__row').filter({ hasText: '测试节点 500' }).getByRole('button', { name: '接入诊断' }).click(); dialog = page.getByRole('dialog'); await dialog.getByText('现有节点认证失败', { exact: false }).waitFor(); assert.equal(await dialog.getByRole('button', { name: '安装最新版', exact: true }).count(), 0); await page.waitForTimeout(400); await page.screenshot({ path: resolve(output, 'diagnostic.png'), fullPage: true }); await dialog.getByRole('button', { name: 'Close this dialog' }).click()
 const revoked = table.locator('.el-table__row').filter({ hasText: '测试节点 501' }); assert.equal(await revoked.getByRole('button', { name: '安装最新版' }).isDisabled(), true)
 const mismatch = table.locator('.el-table__row').filter({ hasText: '测试节点 502' }); assert.match(await mismatch.innerText(), /节点 999/); assert.match(await mismatch.innerText(), /#502/)
 await page.setViewportSize({ width: 390, height: 844 }); await dialog.waitFor({ state: 'hidden' }); await table.scrollIntoViewIfNeeded(); await page.waitForTimeout(400); await page.screenshot({ path: resolve(output, 'mobile.png'), fullPage: true }); assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)); assert.ok(await table.locator('.el-scrollbar__wrap').first().evaluate(el => el.scrollWidth > el.clientWidth))
 assert.deepEqual(errors, []); assert.deepEqual(unexpected, []); assert.ok(requests.every(url => !url.endsWith('/installations') && !url.endsWith('/discoveries')))
 const report = { passed: true, pageUrl, viewports: ['1600x1200', '390x844'], writes, errors, unexpected, screenshots: ['desktop.png', 'diagnostic.png', 'mobile.png'].map(name => resolve(output, name)) }; await writeFile(resolve(output, 'report.json'), JSON.stringify(report, null, 2)); console.log(JSON.stringify(report))
} finally { await browser.close(); server.close() }
