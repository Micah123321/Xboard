import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { readFile, mkdir } from 'node:fs/promises'
import { dirname, extname, join, resolve } from 'node:path'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'

const require = createRequire(import.meta.url)
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright')
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const build = process.env.ADMIN_BUILD_OUT_DIR || join(root, 'dist-access-traffic')
const artifacts = join(build, 'browser-evidence')
await mkdir(artifacts, { recursive: true })
const mime = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml' }
let unhandledApi = 0
const server = createServer(async (request, response) => {
  if (request.url.startsWith('/api/')) { unhandledApi++; response.writeHead(500); response.end('Unmocked API'); return }
  try {
    const path = decodeURIComponent(new URL(request.url, 'http://localhost').pathname).replace(/^\/assets\/admin\/?/, '') || 'index.html'
    const file = resolve(build, path)
    if (!file.startsWith(resolve(build) + '/') && !file.startsWith(resolve(build) + '\\')) throw new Error('Invalid path')
    response.setHeader('Content-Type', mime[extname(file)] || 'application/octet-stream')
    response.end(await readFile(file))
  } catch { response.writeHead(404); response.end('Not found') }
})
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve))
const origin = process.env.ADMIN_TEST_ORIGIN || 'http://127.0.0.1:' + server.address().port
const browser = await chromium.launch({ headless: true, ...(process.env.BROWSER_EXECUTABLE ? { executablePath: process.env.BROWSER_EXECUTABLE } : {}) })
const context = await browser.newContext({ viewport: { width: 1440, height: 1080 }, acceptDownloads: true })
const page = await context.newPage()
const errors = []
page.on('pageerror', error => errors.push(error.message))
await context.addInitScript(() => {
  window.settings = { secure_path: 'mock-admin', title: 'Access Traffic Test' }
  sessionStorage.setItem('xboard_admin_auth', 'synthetic-admin-token')
})
const requests = []
const exports = []
const saves = []
let failConfig = false
let failTraffic = false
let releaseSlow
let slowFinished
let savedConfig = { enabled: true, bucket_seconds: 300, retention_days: 30, max_entries: 2000, node_ids: [] }
const row = (domain = 'cdn.example', override = {}) => ({
  id: 'row-1', user_id: 123, user_email: 'customer@example.test', node_id: 7, node_name: 'Mock Node', domain, ip: '203.0.113.8', port: 443, network: 'tcp', domain_source: 'request',
  first_seen: 1767225600, last_seen: 1767225900, raw_u: 1000, raw_d: 9000, raw_total: 10000,
  billed_u: null, billed_d: null, billed_total: null, rate: null, service: 'Generic CDN', category: 'cdn', confidence: 'low',
  reason: '通用 CDN 域名匹配，实际用途未知', connections: 3, share: 12.5, coverage: 'partial', ...override,
})
const serviceExamples = [
  ['static.okx.com', 'OKX 交易所静态资源', 'crypto'],
  ['static.oklink.com', 'OKLink 区块链浏览器静态资源', 'crypto'],
  ['utility.onekeycn.com', 'OneKey 钱包 API', 'crypto'],
  ['www.itdog.cn', 'ITDOG 网络测速与诊断工具', 'tools'],
  ['api.onesignal.com', 'OneSignal 消息推送', 'push'],
  ['toblog.tobsnssdk.com', 'BytePlus SDK 日志与统计上报', 'telemetry'],
]
const traffic = params => ({
  data: params.get('search') === 'empty' ? [] : params.get('search') === 'service-coverage' ? serviceExamples.map(([domain, service, category], index) => row(domain, { id: `coverage-${index}`, service, category, confidence: 'high', reason: '匹配经过核实的官方服务域名，未采集具体交易或消息内容' })) : [row(params.get('search') === 'fast' ? 'fast.example' : params.get('search') === 'slow' ? 'slow.example' : params.get('page') === '2' ? 'page-two.example' : 'cdn.example'), row('', { id: 'row-2', ip: '', ip_unavailable_reason: '节点上报了未指定地址，未采集到真实目标 IP', domain_source: 'unknown', service: '', category: 'unknown', confidence: 'unknown', reason: '目的地未归属', billed_u: 2000, billed_d: 18000, billed_total: 20000, rate: 2, network: 'unknown', coverage: 'complete', raw_u: 1000, raw_d: 2097152, raw_total: 2098152, activity_hint: '疑似下载 / 内容接收', activity_confidence: 'low', activity_reason: '接收占主导，仅为流量形态线索。真实目标未采集。' }), row('chatgpt.com', { id: 'row-ai', service: 'ChatGPT / OpenAI', category: 'ai', confidence: 'high', reason: '匹配域名后缀 chatgpt.com，服务归属可信度高' }), row('', { id: 'row-ip', ip: '91.108.56.137', domain_source: 'ip', service: 'Telegram', category: 'messaging', confidence: 'medium', reason: '目标 IP 匹配 Telegram 官方专用网段 91.108.56.0/22' })],
  total: params.get('search') === 'empty' ? 0 : 41,
  summary: { raw_u: 8000, raw_d: 3137728, raw_total: 3145728, billed_u: 2000, billed_d: 18000, billed_total: 20000, identified_bytes: 1047576, unidentified_bytes: 2098152, identified_percent: 33.30, unattributed_bytes: 2098152, report_count: 4, partial_report_count: 1, unsettled_report_count: 1 },
  analysis: [{ category: 'cdn', category_label: '通用 CDN', raw_total: 1027576, share: 32.67, confidence: 'low', reason: '域名后缀依据，实际用途未知' }, { category: 'ai', category_label: 'AI 服务', raw_total: 10000, share: 0.32, confidence: 'high', reason: 'OpenAI 域名规则' }, { category: 'messaging', category_label: '即时通讯', raw_total: 10000, share: 0.32, confidence: 'medium', reason: '官方服务专用网段' }, { category: 'unknown', category_label: '未知', raw_total: 2098152, share: 66.70, confidence: 'unknown', reason: '没有目的地证据' }],
  meta: { classification_database: { available: true, source: 'GeoSite', rule_count: 5000, updated_at: 1767225600, group_count: 24, skipped_rules: 1 }, timezone: 'UTC', bucket_seconds: 300, retention_days: 30, enabled: savedConfig.enabled, first_collected_at: 1767225600, last_collected_at: 1767225900, coverage: params.get('search') === 'empty' ? 'none' : 'partial', reason: '存在未采集时间和部分报告' },
})
await page.route('**/*', async route => {
  const url = new URL(route.request().url())
  if (url.origin !== origin) { await route.abort(); return }
  if (!url.pathname.startsWith('/api/')) { await route.continue(); return }
  assert.ok(url.pathname.startsWith('/api/v2/mock-admin/'), 'Reuse secure admin base path')
  assert.equal(route.request().headers().authorization, 'synthetic-admin-token')
  const endpoint = url.pathname.split('/').at(-1)
  const json = data => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(data) })
  if (endpoint === 'getSystemStatus') { await json({ data: {} }); return }
  if (url.pathname.endsWith('/user/fetch')) {
    await json({ data: [{ id: 123, email: 'fixture@example.test', banned: false, total_used: 1000, transfer_enable: 1000000, balance: 0, created_at: 1767225600, plan_id: null, expired_at: null, u: 100, d: 900 }], total: 1 }); return
  }
  if (endpoint === 'getNodesPaginated' || endpoint === 'getAllNodes') {
    const nodes = [{ id: 7, name: 'Fixture Node', type: 'vmess', host: '192.0.2.7', port: 443, show: true, rate: 1, online: 0, online_conn: 0, is_online: 1, available_status: 1, group_ids: [], route_ids: [] }]
    await json({ data: nodes, total: 1 }); return
  }
  if (endpoint === 'getStatUser') { await json({ data: [], total: 0, summary: { upload: 0, download: 0, total: 0 } }); return }
  if (endpoint === 'getAccessTraffic') {
    requests.push(Object.fromEntries(url.searchParams))
    if (url.searchParams.get('search') === 'slow') {
      const pending = new Promise(resolve => { releaseSlow = resolve })
      await pending
      await json(traffic(url.searchParams))
      slowFinished?.()
      return
    }
    if (failTraffic) { await route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: 'mock API failed' }) }); return }
    await json(traffic(url.searchParams)); return
  }
  if (endpoint === 'accessTrafficConfig') {
    if (route.request().method() === 'POST') {
      const payload = route.request().postDataJSON()
      saves.push(payload)
      if (failConfig) { await route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ message: 'mock config validation failed' }) }); return }
      savedConfig = payload
      await json({ data: true }); return
    }
    await json(savedConfig); return
  }
  if (endpoint === 'exportAccessTraffic') {
    exports.push(Object.fromEntries(url.searchParams))
    const html = url.searchParams.get('format') === 'html'
    await route.fulfill({ status: 200, contentType: html ? 'text/html' : 'text/csv', body: html ? '<!doctype html><html><body>Mock report: raw and allocated billed; classification is inferred; timezone UTC; partial coverage.</body></html>' : 'raw_total,billed_total,reason\n10000,,Unknown CDN purpose\n' }); return
  }
  await json({ data: [], total: 0, summary: {}, meta: {} })
})
const checks = []
const record = message => { checks.push(message); console.log('PASS ' + message) }
const waitRequest = async predicate => {
  const until = Date.now() + 5000
  while (!requests.some(predicate)) { assert.ok(Date.now() < until, 'Expected request was not emitted'); await new Promise(resolve => setTimeout(resolve, 20)) }
}
const visible = async text => { await page.getByText(text, { exact: true }).first().waitFor({ state: 'visible' }) }
const apply = () => page.getByRole('button', { name: '应用筛选', exact: true }).click()
try {
  await page.goto(origin + '/assets/admin/#/access-traffic?user_id=123&node_id=7')
  await visible('cdn.example')
  assert.equal(requests[0].user_id, '123')
  assert.equal(requests[0].node_id, '7')
  assert.equal(requests[0].group_by, 'domain')
  assert.equal(requests[0].pageSize, '20')
  assert.ok(await page.getByText('未结算 / 未知', { exact: true }).count())
  assert.ok(await page.getByText('通用 CDN 用途未知，不能据此认定在观看视频。', { exact: true }).count())
  assert.ok(await page.getByText('未归属目的地', { exact: true }).count())
  assert.ok(await page.getByText('3.00 MB', { exact: true }).count(), 'Global summary is independent of visible page rows')
  assert.ok(await page.getByText('customer@example.test', { exact: true }).count(), 'Email is visible beside user ID')
  assert.ok(await page.getByText(/GeoSite · 5000 条域名规则/).count(), 'Active database status is visible')
  assert.ok(await page.getByText(/节点上报了未指定地址/).count(), 'Unspecified addresses are explained')
  assert.ok(await page.getByText(/服务识别可信度/).count(), 'Confidence refers to service attribution')
  record('query initialization, historical billing, unknown destinations, evidence and collection gaps')
  record('user email, target capture reason, GeoSite status and service confidence are explicit')
  assert.ok(await page.getByText('ChatGPT / OpenAI · 请求域名', { exact: true }).count())
  assert.ok(await page.getByText('Telegram · 仅 IP', { exact: true }).count())
  assert.ok(await page.getByText('AI 服务（推断） · 服务识别可信度 高', { exact: true }).count())
  assert.ok(await page.getByText('流量形态（低可信度）：疑似下载 / 内容接收', { exact: true }).count())
  record('AI and dedicated IP services are separated from low confidence activity hints')

  await page.getByRole('textbox', { name: '域名 / IP 搜索', exact: true }).fill('service-coverage')
  await apply()
  await visible('static.okx.com')
  for (const [, service] of serviceExamples) assert.ok(await page.getByText(service + ' · 请求域名', { exact: true }).count(), service)
  for (const label of ['加密货币', '开发与网络工具', '消息推送', '统计与遥测']) {
    await page.locator('.filter-grid .el-select').nth(0).click()
    await page.getByRole('option', { name: label, exact: true }).click()
    await apply()
    assert.equal(requests.at(-1).category, { 加密货币: 'crypto', 开发与网络工具: 'tools', 消息推送: 'push', 统计与遥测: 'telemetry' }[label])
  }
  await page.locator('.records-card').screenshot({ path: join(artifacts, 'service-coverage.png') })
  record('verified exchange wallet tools push and telemetry services render with working category filters')
  await page.getByRole('textbox', { name: '域名 / IP 搜索', exact: true }).fill('')
  await page.locator('.filter-grid .el-select').nth(0).click()
  await page.getByRole('option', { name: '通用 CDN', exact: true }).click()
  await apply()
  await visible('cdn.example')

  await page.getByRole('button', { name: 'Go to next page' }).click()
  await visible('page-two.example')
  assert.equal(requests.at(-1).page, '2')
  record('pagination sends page=2 and renders the next result')

  await page.getByRole('textbox', { name: '用户 ID', exact: true }).fill('456')
  await page.getByRole('textbox', { name: '节点 ID', exact: true }).fill('9')
  await page.getByRole('textbox', { name: '域名 / IP 搜索', exact: true }).fill('example')
  await page.locator('.filter-grid .el-select').nth(0).click()
  await page.getByRole('option', { name: '通用 CDN', exact: true }).click()
  await page.locator('.filter-grid .el-select').nth(1).click()
  await page.getByRole('option', { name: 'UDP', exact: true }).click()
  const dateInputs = page.locator('.range-filter input')
  await dateInputs.nth(0).fill('2026-01-01 00:00:00')
  await dateInputs.nth(1).fill('2026-01-02 00:00:00')
  await dateInputs.nth(1).press('Enter')
  await apply()
  await visible('cdn.example')
  const filters = requests.at(-1)
  assert.equal(filters.user_id, '456'); assert.equal(filters.node_id, '9')
  assert.equal(filters.search, 'example'); assert.equal(filters.category, 'cdn'); assert.equal(filters.network, 'udp'); assert.equal(filters.page, '1')
  assert.ok(Number(filters.end_time) > Number(filters.start_time))
  record('search, category, network, user, node and half-open date range filters reset pagination')

  await page.getByText('时间视图', { exact: true }).click()
  await visible('cdn.example')
  assert.equal(requests.at(-1).group_by, 'time')
  assert.ok(await page.getByText('采集时间', { exact: true }).count())
  record('time view requests group_by=time and renders collection times')

  for (const [label, extension] of [['导出 CSV', 'csv'], ['导出 HTML 报告', 'html']]) {
    const download = page.waitForEvent('download')
    await page.getByRole('button', { name: label, exact: true }).click()
    const file = await download
    assert.ok(file.suggestedFilename().endsWith('.' + extension))
    assert.equal(await file.failure(), null)
    const saved = join(artifacts, 'mock-report.' + extension)
    await file.saveAs(saved)
    const content = await readFile(saved, 'utf8')
    assert.ok(content.includes(extension === 'csv' ? 'raw_total,billed_total' : 'classification is inferred'))
    assert.equal(exports.at(-1).group_by, 'time')
    assert.equal(exports.at(-1).search, 'example')
    assert.equal(exports.at(-1).page, undefined)
    assert.equal(exports.at(-1).pageSize, undefined)
  }
  record('CSV and HTML downloads preserve all active filters and exclude pagination')

  await page.getByRole('button', { name: '采集配置', exact: true }).click()
  await page.locator('.el-dialog .el-switch').waitFor()
  await page.locator('.el-dialog .el-switch').click()
  assert.equal(await page.getByRole('switch', { name: '启用采集' }).getAttribute('aria-checked'), 'false')
  await page.getByRole('spinbutton', { name: '分桶间隔', exact: true }).fill('600')
  await page.getByRole('spinbutton', { name: '保留天数', exact: true }).fill('14')
  await page.getByRole('spinbutton', { name: '每批最大条目数', exact: true }).fill('1000')
  await page.getByRole('textbox', { name: '节点 ID 白名单', exact: true }).fill('7, 8, 7')
  failConfig = true
  await page.getByRole('button', { name: '保存配置', exact: true }).click()
  await visible('mock config validation failed')
  assert.ok(await page.getByRole('dialog').isVisible())
  failConfig = false
  await page.getByRole('button', { name: '保存配置', exact: true }).click()
  await page.getByRole('dialog').waitFor({ state: 'hidden' })
  await visible('cdn.example')
  assert.deepEqual(saves.at(-1), { enabled: false, bucket_seconds: 600, retention_days: 14, max_entries: 1000, node_ids: [7, 8] })
  record('configuration loads, edits all fields, deduplicates nodes and preserves draft after server validation error')

  await page.getByRole('textbox', { name: '域名 / IP 搜索', exact: true }).fill('slow')
  await apply()
  await waitRequest(request => request.search === 'slow')
  await page.getByRole('textbox', { name: '域名 / IP 搜索', exact: true }).fill('fast')
  await apply()
  await visible('fast.example')
  const delayedDone = new Promise(resolve => { slowFinished = resolve })
  releaseSlow()
  await delayedDone
  await page.waitForTimeout(150)
  assert.ok(await page.getByText('fast.example', { exact: true }).isVisible())
  assert.equal(await page.getByText('slow.example', { exact: true }).count(), 0)
  record('a late response cannot overwrite a newer filter result')

  await page.getByRole('textbox', { name: '域名 / IP 搜索', exact: true }).fill('empty')
  await apply()
  await visible('尚无采集记录，请检查采集配置与节点上报')
  record('empty collection state explains collection setup')

  failTraffic = true
  await page.getByRole('textbox', { name: '域名 / IP 搜索', exact: true }).fill('failure')
  await apply()
  await visible('mock API failed')
  failTraffic = false
  await page.getByRole('button', { name: '重试', exact: true }).click()
  await visible('cdn.example')
  record('failed data request exposes retry and recovers')

  await page.getByRole('button', { name: '重置', exact: true }).click()
  await visible('cdn.example')
  assert.equal(requests.at(-1).user_id, undefined)
  assert.equal(requests.at(-1).category, undefined)
  assert.equal(requests.at(-1).start_time, undefined)
  await page.waitForFunction(() => document.querySelectorAll('.el-message').length === 0)
  await page.screenshot({ path: join(artifacts, 'desktop.png'), fullPage: true })
  await page.locator('.records-card').screenshot({ path: join(artifacts, 'desktop-table.png') })
  await page.setViewportSize({ width: 390, height: 844 })
  await page.waitForTimeout(100)
  const layout = await page.locator('.access-traffic-view').evaluate(element => ({ width: element.getBoundingClientRect().width, scroll: element.scrollWidth, right: element.getBoundingClientRect().right }))
  assert.ok(layout.scroll <= layout.width + 2, JSON.stringify(layout))
  assert.ok(layout.right <= 390 + 2, JSON.stringify(layout))
  await page.getByRole('button', { name: '采集配置', exact: true }).click()
  await page.getByRole('dialog').waitFor()
  const dialog = await page.getByRole('dialog').boundingBox()
  assert.ok(dialog.x >= 0 && dialog.x + dialog.width <= 392)
  await page.getByRole('button', { name: '取消', exact: true }).click()
  await page.getByRole('dialog').waitFor({ state: 'hidden' })
  await page.locator('.page-heading').scrollIntoViewIfNeeded()
  await page.screenshot({ path: join(artifacts, 'mobile.png'), fullPage: true })
  await page.locator('.records-card').screenshot({ path: join(artifacts, 'mobile-table.png') })
  record('reset clears filters; 390px mobile page and configuration dialog fit viewport')

  await page.setViewportSize({ width: 1440, height: 1080 })
  await page.goto(origin + '/assets/admin/#/users')
  await visible('fixture@example.test')
  await page.locator('.users-table .action-trigger').first().click()
  await page.getByRole('menuitem', { name: 'TA的访问流量分析', exact: true }).click()
  await visible('cdn.example')
  assert.equal(requests.at(-1).user_id, '123')
  assert.equal(requests.at(-1).node_id, undefined)
  record('user action opens access traffic with the selected user')

  await page.goto(origin + '/assets/admin/#/users')
  await visible('fixture@example.test')
  await page.locator('.users-table .action-trigger').first().click()
  await page.getByRole('menuitem', { name: 'TA的流量记录', exact: true }).click()
  await page.getByRole('button', { name: '访问目的地与消耗分析', exact: true }).click()
  await visible('cdn.example')
  assert.equal(requests.at(-1).user_id, '123')
  record('existing traffic log dialog opens destination analysis')

  await page.goto(origin + '/assets/admin/#/nodes')
  await visible('Fixture Node')
  await page.locator('.el-table .action-trigger').first().click()
  await page.getByRole('menuitem', { name: '访问流量分析', exact: true }).click()
  await visible('cdn.example')
  assert.equal(requests.at(-1).node_id, '7')
  assert.equal(requests.at(-1).user_id, undefined)
  record('node action opens access traffic with the selected node')
  assert.equal(unhandledApi, 0)
  assert.deepEqual(errors, [])
  console.log(JSON.stringify({ passed: checks.length, browser: browser.version(), unhandledApi, errors, artifacts }, null, 2))
} catch (error) {
  await page.screenshot({ path: join(artifacts, 'failure.png'), fullPage: true }).catch(() => {})
  console.error('Recent requests:', JSON.stringify(requests.slice(-8)))
  throw error
} finally {
  releaseSlow?.()
  await browser.close()
  await new Promise(resolve => server.close(resolve))
}
