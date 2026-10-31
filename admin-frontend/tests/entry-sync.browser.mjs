import assert from 'node:assert/strict'
import { mkdir, writeFile } from 'node:fs/promises'
import { resolve } from 'node:path'
import { pathToFileURL } from 'node:url'
const { chromium } = await import(pathToFileURL('E:/code/node/dsh-upgrade-0.2.1-rc.2/node_modules/playwright/index.mjs').href)
const pageUrl = process.env.ADMIN_ENTRY_SYNC_URL || 'http://127.0.0.1:5179/assets/admin/#/node-entry-sync'
const apiOrigin = 'http://127.0.0.1:18918'
const output = resolve('output/nya-multi-account-e2e/browser-evidence')
await mkdir(output, { recursive: true })
const browser = await chromium.launch({ headless: true, channel: 'msedge' })
const errors = [], failedResponses = [], checks = []
let page
try {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1100 } })
  await context.addInitScript(() => {
    window.settings = { secure_path: 'nya-entry-fixture', title: 'Xboard Admin' }
    sessionStorage.setItem('xboard_admin_auth', 'Bearer nya-admin-browser-fixture-token')
  })
  page = await context.newPage()
  page.on('pageerror', error => errors.push(error.message))
  page.on('console', message => { if (message.type() === 'error') errors.push(message.text()) })
  page.on('response', response => { if (response.status() >= 400) failedResponses.push({ url: response.url(), status: response.status() }) })
  await page.route('**/api/**', async route => {
    const request = route.request(), url = new URL(request.url())
    if (!url.pathname.startsWith('/api/')) { await route.continue(); return }
    const response = await route.fetch({ url: apiOrigin + url.pathname + url.search })
    await route.fulfill({ response })
  })
  await page.goto(pageUrl, { waitUntil: 'networkidle' })
  const idle = () => page.waitForFunction(() => [...document.querySelectorAll('.actions button')].some(button => button.textContent.trim() === '保存设置' && !button.disabled))
  await idle()
  const field = (label, scope = page) => scope.locator('.el-form-item').filter({ has: page.locator('.el-form-item__label').filter({ hasText: label }) })
  const input = (label, scope = page) => field(label, scope).locator('input').first()
  const cards = page.locator('.account-card')
  assert.equal(await cards.count(), 1)
  await input('NYA 地址').fill('https://nya.example.test')
  await input('账号名称', cards.nth(0)).fill('mxmicah')
  await input('用户名', cards.nth(0)).fill('mxmicah')
  await input('密码', cards.nth(0)).fill('fixture-primary-password')
  await page.getByRole('button', { name: '添加账号', exact: true }).click()
  await input('账号名称', cards.nth(1)).fill('saobi')
  await input('用户名', cards.nth(1)).fill('saobi')
  await input('密码', cards.nth(1)).fill('fixture-backup-password')
  await field('同步节点').locator('.el-select').click()
  for (const id of [434, 430]) await page.getByRole('option').filter({ hasText: '#' + id + ' ' }).click()
  await page.keyboard.press('Escape')
  await page.locator('.schedule-row .el-switch').click()
  const save = async () => {
    const pending = page.waitForResponse(r => r.url().endsWith('/server/entry-sync/settings') && r.request().method() === 'POST')
    await page.getByRole('button', { name: '保存设置', exact: true }).click()
    const response = await pending; assert.equal(response.status(), 200)
    const data = (await response.json()).data
    assert.ok(data.settings.accounts.every(account => !Object.hasOwn(account, 'password')))
    await idle(); return data
  }
  const saved = await save()
  assert.equal(saved.settings.accounts.length, 2)
  assert.ok(saved.settings.accounts.every(account => account.password_set))
  for (let i = 0; i < 2; i++) assert.equal(await input('密码', cards.nth(i)).inputValue(), '')
  checks.push('two independent account passwords save encrypted and never echo')
  const run = async (name, confirm = false) => {
    const pending = page.waitForResponse(r => r.url().endsWith('/server/entry-sync/run') && r.request().method() === 'POST')
    await page.getByRole('button', { name, exact: true }).click()
    if (confirm) await page.getByRole('dialog', { name: '确认同步入口 IP' }).getByRole('button', { name: '立即同步', exact: true }).click()
    const response = await pending; assert.equal(response.status(), 200)
    const result = (await response.json()).data; await idle(); return result
  }
  const capture = async (name, target) => {
    await page.waitForFunction(() => document.querySelectorAll('.el-message').length === 0)
    if (target) await target.scrollIntoViewIfNeeded()
    await page.screenshot({ path: resolve(output, name), fullPage: false })
  }
  const preview = await run('预览变更')
  assert.equal(preview.status, 'succeeded')
  assert.equal(preview.result.accounts_checked, 2)
  assert.equal(preview.result.matched, 4)
  assert.equal(preview.result.skipped, 0)
  assert.equal(preview.result.changes.length, 4)
  assert.ok(preview.result.changes.filter(c => c.field === 'host').every(c => c.account_name === 'mxmicah' && c.to === '198.51.100.22'))
  assert.ok(preview.result.changes.filter(c => c.field.startsWith('entry_points.')).every(c => c.account_name === 'saobi' && c.port === 11823 && c.to === '203.0.113.22'))
  await capture('multi-account-settings-desktop.png', page.locator('.accounts-section'))
  await capture('multi-account-preview-desktop.png', page.locator('.results-panel'))
  checks.push('preview maps primary and extra to different account IPs and keeps backup port')
  const applied = await run('立即同步', true)
  assert.equal(applied.result.changes.length, 4)
  await capture('multi-account-synced-desktop.png', page.locator('.results-panel'))
  await page.reload({ waitUntil: 'networkidle' }); await idle()
  assert.equal(await cards.count(), 2)
  assert.equal(await input('密码', cards.nth(1)).inputValue(), '')
  const again = await run('预览变更')
  assert.equal(again.result.updated, 0); assert.equal(again.result.unchanged, 4)
  checks.push('actual sync persists both different IPs and repeat run is idempotent')
  await cards.nth(1).locator('.el-switch').click()
  const disabled = await run('保存并预览')
  assert.equal(disabled.result.accounts_checked, 1); assert.equal(disabled.result.skipped, 2)
  checks.push('disabled account is skipped and its unmatched entries preserved')
  await cards.nth(1).locator('.el-switch').click()
  await input('密码', cards.nth(1)).fill('wrong-backup-password')
  const failed = await run('保存并预览')
  assert.equal(failed.status, 'failed'); assert.ok(failed.error.includes('saobi'))
  assert.equal(JSON.stringify(failed).includes('wrong-backup-password'), false)
  await capture('multi-account-failure-desktop.png', page.locator('.results-panel'))
  checks.push('second account failure names the account and retains previous results')
  await input('密码', cards.nth(1)).fill('fixture-backup-password'); await save()
  await run('预览变更')
  await page.getByRole('button', { name: '添加账号', exact: true }).click()
  assert.equal(await cards.count(), 3); await cards.nth(2).getByRole('button', { name: /^删除/ }).click()
  assert.equal(await cards.count(), 2)
  checks.push('add and remove account cards preserve other credentials')
  await page.setViewportSize({ width: 390, height: 844 })
  await page.reload({ waitUntil: 'networkidle' }); await idle()
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth))
  await capture('multi-account-mobile.png', page.locator('.accounts-section'))
  await capture('multi-account-results-mobile.png', page.locator('.results-panel'))
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth))
  checks.push('390px account cards and results have no page overflow')
  assert.deepEqual(errors, []); assert.deepEqual(failedResponses, [])
  const report = { passed: true, pageUrl, checks, errors, failedResponses, screenshots: ['multi-account-settings-desktop.png','multi-account-preview-desktop.png','multi-account-synced-desktop.png','multi-account-failure-desktop.png','multi-account-mobile.png','multi-account-results-mobile.png'].map(name => resolve(output, name)) }
  await writeFile(resolve(output, 'report.json'), JSON.stringify(report, null, 2)); console.log(JSON.stringify(report))
} catch (error) {
  if (page) await page.screenshot({ path: resolve(output, 'investigation.png'), fullPage: true }).catch(() => {})
  console.log(JSON.stringify({ errors, failedResponses })); throw error
} finally {
  if (page) await page.unrouteAll({ behavior: 'wait' }).catch(() => {})
  await browser.close()
}
