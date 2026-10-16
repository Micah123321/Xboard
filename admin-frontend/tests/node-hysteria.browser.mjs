import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { readFile, mkdir } from 'node:fs/promises'
import { dirname, extname, resolve, join } from 'node:path'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'

const require = createRequire(import.meta.url)
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright')
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const build = resolve(root, process.env.ADMIN_BUILD_OUT_DIR || 'dist-hysteria-defaults')
const artifacts = join(root, 'dist-hysteria-defaults', 'browser-evidence')
await mkdir(artifacts, { recursive: true })
const mime = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml' }
const server = createServer(async (request, response) => {
  try {
    const path = decodeURIComponent(new URL(request.url, 'http://localhost').pathname).replace(/^\/assets\/admin\/?/, '') || 'index.html'
    const file = resolve(build, path)
    assert.ok(file.startsWith(build + '/') || file.startsWith(build + '\\'))
    response.setHeader('Content-Type', mime[extname(file)] || 'application/octet-stream')
    response.end(await readFile(file))
  } catch { response.writeHead(404); response.end('Not found') }
})
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve))
const origin = 'http://127.0.0.1:' + server.address().port
let browser
const errors = []
const saves = []
const fixture = {
  id: 7, name: 'Existing HY2', type: 'hysteria', host: 'node.test', port: 26111, server_port: 26111,
  show: true, enabled: true, rate: 1, online: 0, online_conn: 0, is_online: 1, available_status: 1, group_ids: [], route_ids: [], gfw_check_enabled: false,
  protocol_settings: { version: 2, bandwidth: { up: 200, down: 300 }, hop_interval: 0, obfs: { open: true, type: 'salamander', password: 'fixture-existing-password' }, tls: { server_name: 'node.test', allow_insecure: false } },
}
try {
  browser = await chromium.launch({ headless: true, ...(process.env.BROWSER_EXECUTABLE ? { executablePath: process.env.BROWSER_EXECUTABLE } : {}) })
  const context = await browser.newContext({ viewport: { width: 1440, height: 1080 } })
  const page = await context.newPage()
  page.on('pageerror', error => errors.push(error.message))
  await context.addInitScript(() => {
    window.settings = { secure_path: 'mock-admin', title: 'HY2 Form Test' }
    sessionStorage.setItem('xboard_admin_auth', 'synthetic-admin-token')
  })
  await page.route('**/*', async route => {
    const url = new URL(route.request().url())
    if (url.origin !== origin) { await route.abort(); return }
    if (!url.pathname.startsWith('/api/')) { await route.continue(); return }
    const json = data => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(data) })
    if (url.pathname.endsWith('/getNodesPaginated') || url.pathname.endsWith('/getAllNodes')) { await json({ data: [fixture], total: 1 }); return }
    if (url.pathname.endsWith('/server/manage/save')) { saves.push(route.request().postDataJSON()); await json({ data: true }); return }
    if (url.pathname.endsWith('/getSystemStatus')) { await json({ data: {} }); return }
    await json({ data: [], total: 0 })
  })
  await page.goto(origin + '/assets/admin/#/nodes')
  await page.getByText('Existing HY2', { exact: true }).first().waitFor()
  const dialog = page.locator('.node-editor-dialog:visible')
  const field = label => dialog.locator('.el-form-item').filter({ has: page.locator('.el-form-item__label').filter({ hasText: label }) })
  const input = label => field(label).locator('input').first()
  const create = async (protocol = 'Hysteria') => {
    await page.getByRole('button', { name: '添加节点', exact: true }).click()
    await dialog.locator('.hero-protocol .el-select').click()
    await page.getByRole('option', { name: protocol, exact: true }).click()
    await page.getByRole('option', { name: protocol, exact: true }).waitFor({ state: 'hidden' })
  }
  await create()
  assert.ok((await field('协议版本').innerText()).includes('Hysteria 2'))
  assert.equal(await input('上行带宽 Mbps').inputValue(), '0')
  assert.equal(await input('下行带宽 Mbps').inputValue(), '0')
  assert.equal(await input('端口跳跃间隔').inputValue(), '30')
  assert.equal(await field('启用混淆').getByRole('switch').getAttribute('aria-checked'), 'true')
  const firstPassword = await input('混淆密码').inputValue()
  assert.match(firstPassword, /^[a-f0-9]{32}$/)
  await field('混淆类型').locator('.el-select').click()
  assert.equal(await page.getByRole('option', { name: 'Salamander', exact: true }).count(), 1)
  await page.getByRole('option', { name: 'Salamander', exact: true }).click()
  console.log('PASS new HY2 defaults and constrained obfuscation selector')
  await field('混淆密码').getByRole('button', { name: '随机生成', exact: true }).click()
  const regenerated = await input('混淆密码').inputValue()
  assert.match(regenerated, /^[a-f0-9]{32}$/)
  assert.notEqual(regenerated, firstPassword)
  await field('启用混淆').locator('.el-switch').click()
  await field('启用混淆').locator('.el-switch').click()
  assert.equal(await input('混淆密码').inputValue(), regenerated)
  await input('混淆密码').fill('')
  await field('启用混淆').locator('.el-switch').click()
  await field('启用混淆').locator('.el-switch').click()
  const generatedOnEnable = await input('混淆密码').inputValue()
  assert.match(generatedOnEnable, /^[a-f0-9]{32}$/)
  console.log('PASS random generation and enable toggle preserve or fill passwords')
  await input('节点名称').fill('New HY2 fixture')
  await input('节点地址').fill('new-node.test')
  await input('连接端口').fill('26111')
  await input('服务端口').fill('26111')
  await field('协议版本').scrollIntoViewIfNeeded()
  await page.screenshot({ path: join(artifacts, 'hy2-defaults-desktop.png') })
  await dialog.getByRole('button', { name: '提交', exact: true }).click()
  await dialog.waitFor({ state: 'hidden' })
  assert.equal(saves.length, 1)
  assert.deepEqual(saves[0].protocol_settings.bandwidth, { up: 0, down: 0 })
  assert.equal(saves[0].protocol_settings.hop_interval, 30)
  assert.equal(saves[0].protocol_settings.obfs.password, generatedOnEnable)
  console.log('PASS submit sends HY2 defaults and the displayed password')
  await page.locator('.el-table__row').filter({ hasText: 'Existing HY2' }).locator('.action-trigger').first().click()
  await page.getByRole('menuitem', { name: '编辑节点', exact: true }).click()
  assert.equal(await input('上行带宽 Mbps').inputValue(), '200')
  assert.equal(await input('下行带宽 Mbps').inputValue(), '300')
  assert.equal(await input('端口跳跃间隔').inputValue(), '0')
  assert.equal(await input('混淆密码').inputValue(), 'fixture-existing-password')
  await dialog.getByRole('button', { name: '保存修改', exact: true }).click()
  await dialog.waitFor({ state: 'hidden' })
  assert.equal(saves.length, 2)
  assert.equal(saves[1].protocol_settings.obfs.password, 'fixture-existing-password')
  assert.equal(saves[1].protocol_settings.hop_interval, 0)
  console.log('PASS editing and saving retain original HY2 parameters and password')
  for (const [protocol, type] of [['Shadowsocks', 'shadowsocks'], ['VMess', 'vmess'], ['Trojan', 'trojan'], ['VLess', 'vless'], ['TUIC', 'tuic'], ['SOCKS', 'socks'], ['Naive', 'naive'], ['HTTP', 'http'], ['Mieru', 'mieru'], ['AnyTLS', 'anytls'], ['Snell', 'snell']]) {
    await create(protocol)
    if (['vmess', 'trojan', 'vless'].includes(type)) assert.ok((await field('传输协议').innerText()).includes('TCP'))
    if (type === 'tuic') {
      assert.ok((await field('协议版本').innerText()).includes('V5'))
      assert.ok((await field('ALPN').innerText()).includes('HTTP/3'))
    }
    if (type === 'anytls') assert.ok((await field('Padding Scheme').locator('textarea').inputValue()).startsWith('stop=8'))
    if (type === 'naive') assert.ok((await field('安全性').innerText()).includes('TLS'))
    await input('节点名称').fill('New ' + protocol + ' fixture')
    await input('节点地址').fill('new-node.test')
    await input('连接端口').fill('443')
    await input('服务端口').fill('443')
    const sni = field('服务器名称（SNI）').locator('input')
    if (await sni.count()) await sni.fill('node.test')
    await dialog.getByRole('button', { name: '提交', exact: true }).click()
    await dialog.waitFor({ state: 'hidden' })
    assert.equal(saves.at(-1).type, type)
    assert.equal(saves.at(-1).id, undefined, 'Create must never retain the previously edited node ID')
    console.log('PASS new ' + protocol + ' default template saves successfully')
  }
  await create('VLess')
  await field('传输协议').locator('.el-select').click()
  await page.getByRole('option', { name: 'mKCP', exact: true }).click()
  assert.match(await input('Seed').inputValue(), /^[a-f0-9]{32}$/)
  await field('KCP 头部类型').locator('.el-select').click()
  await page.getByRole('option', { name: 'SRTP', exact: true }).click()
  await dialog.locator('.hero-protocol .el-select').click()
  await page.getByRole('option', { name: 'VMess', exact: true }).click()
  assert.ok((await field('传输协议').innerText()).includes('TCP'))
  await dialog.getByRole('button', { name: '取消', exact: true }).click()
  await dialog.waitFor({ state: 'hidden' })
  console.log('PASS VLESS mKCP defaults and protocol switching discard unavailable transport')
  await page.setViewportSize({ width: 390, height: 844 })
  await create()
  await field('混淆密码').scrollIntoViewIfNeeded()
  const button = field('混淆密码').getByRole('button', { name: '随机生成', exact: true })
  const box = await button.boundingBox()
  assert.ok(box && box.x >= 0 && box.x + box.width <= 390)
  await button.click()
  await page.screenshot({ path: join(artifacts, 'hy2-defaults-mobile.png') })
  assert.deepEqual(errors, [])
  console.log('PASS mobile password control fits viewport; no browser runtime errors')
  console.log('ARTIFACTS ' + artifacts)
} finally {
  await browser?.close()
  await new Promise(resolve => server.close(resolve))
}
