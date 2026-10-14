import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import ts from 'typescript'
async function load(path, replace = text => text) {
 const text = replace(await readFile(new URL(path, import.meta.url), 'utf8'))
 const { outputText } = ts.transpileModule(text, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } })
 return import('data:text/javascript;base64,' + Buffer.from(outputText).toString('base64'))
}
const model = await load('./model.ts')
let response = { status: 200, data: { data: {} } }
let captured
let failNetwork = false
globalThis.__nodeUpdateMock = async config => { captured = config; if (failNetwork) throw new Error('network'); return response }
const api = await load('../../api/nodeUpdates.ts', text => text.replace("import { adminClient } from './client'", 'const adminClient = { request: config => globalThis.__nodeUpdateMock(config) }'))
const artifacts = ['amd64', 'arm64'].flatMap(arch => ['mi-node', 'xbctl'].map(component => ({ arch, component, https_url: 'https://example.test/' + arch + '/' + component, sha256: 'a'.repeat(64), size_bytes: 123 })))
test('discovery compares release numbers without inferring age from a missing capability', () => {
 assert.equal(model.discoveryVersionLabel('v20261012.38249125239.1', 'v20261012.38249125239.1'), '版本号与最新版一致')
 assert.equal(model.discoveryVersionLabel('v1.9.0', 'v1.10.0'), '版本号低于最新版')
 assert.equal(model.discoveryVersionLabel('v2.0.0', 'v1.10.0'), '版本号高于当前发布源')
 assert.equal(model.discoveryVersionLabel('v1.9007199254740992.0', 'v1.9007199254740993.0'), '版本号低于最新版')
 assert.equal(model.discoveryVersionLabel('5a6626186448cd20472d2ba3c17c3c564533d5b4', 'v20261012.38249125239.1'), '提交 SHA 构建，尚未对应固定版本号')
 assert.equal(model.discoveryVersionLabel('dev'), '版本格式暂未识别')
 assert.equal(model.discoveryVersionLabel('v1.2.3'), '等待获取最新版进行比对')
})
test('enrollment failures show the actual stage and fixed reason without raw strings', () => {
 assert.equal(model.enrollmentStatusLabel(), '尚未收到接入执行结果')
 assert.equal(model.enrollmentStatusLabel({ state: 'retrying', stage: 'layout', code: 'systemd_failed' }), '自动接入失败，等待重试；阶段：检查安装布局；原因：系统服务命令执行失败')
 assert.ok(model.enrollmentStatusLabel({ state: 'retrying', stage: 'enroll', code: 'invalid_credentials' }).includes('现有节点认证失败'))
 assert.ok(!model.enrollmentStatusLabel({ state: 'retrying', stage: 'enroll', code: 'token=private-value' }).includes('private-value'))
 assert.ok(model.enrollmentStatusLabel({ state: 'disabled', stage: 'config', code: 'local_disabled' }).includes('接入已停用'))
})
test('fixed versions and both architecture pairs validate', () => {
 model.validateRelease('v1.2.3', artifacts)
 model.validateRelease('v1.2.3', artifacts.slice(0, 2))
 for (const version of ['latest', '1.2.3', 'v1.2.3-rc1', 'v01.2.3']) assert.throws(() => model.validateRelease(version, artifacts))
 assert.throws(() => model.validateRelease('v1.2.3', artifacts.slice(0, 1)))
})
test('unsafe or unverifiable artifact metadata is rejected', () => {
 for (const patch of [{ https_url: 'http://example.test/a' }, { https_url: 'https://user:pass@example.test/a' }, { https_url: 'https://example.test/a#x' }, { sha256: 'A'.repeat(64) }, { size_bytes: 0 }, { size_bytes: 536870913 }, { size_bytes: 1.5 }]) assert.throws(() => model.validateRelease('v1.2.3', [{ ...artifacts[0], ...patch }, artifacts[1]]))
})
test('scope IDs reject empty, duplicates, fractional and unsafe IDs', () => {
 assert.deepEqual(model.positiveIds('12, 31，32'), [12, 31, 32])
 for (const text of ['', '1,1', '0', '-2', '1.5', '9007199254740992']) assert.throws(() => model.positiveIds(text))
})
test('same failed submission reuses key while changed payload and completed operation get new keys', () => {
 const keys = api.createIdempotencyKeys(); const data = { revision: 1, enabled: false }
 const first = keys.get('settings', data)
 assert.equal(keys.get('settings', { ...data }), first)
 assert.notEqual(keys.get('settings', { ...data, revision: 2 }), first)
 keys.clear('settings', data)
 assert.notEqual(keys.get('settings', data), first)
})
test('HTTP conflicts survive the legacy interceptor and preserve exclusions', async () => {
 response = { status: 409, data: { error: { code: 'revision_conflict', message: 'changed', retryable: false, excluded: [{ installation_id: 'a', code: 'version_conflict' }] } } }
 await assert.rejects(api.updates.saveSettings({ enabled: false, max_concurrency: 5, revision: 1 }, 'stable-key'), error => error instanceof api.UpdateError && error.code === 'revision_conflict' && error.status === 409 && error.excluded.length === 1)
 assert.equal(captured.validateStatus(409), true)
 assert.equal(captured.headers['Idempotency-Key'], 'stable-key')
 assert.equal(captured.url, '/server/update/settings')
 assert.equal(captured.data.revision, 1)
 response = { status: 200, data: { data: {} } }
})
test('preview is read-only and pagination and manual resolution match contract', async () => {
 await api.updates.preview({ release_id: 'r', installation_ids: ['i'] })
 assert.equal(captured.headers, undefined)
 assert.deepEqual(Object.keys(captured.data).sort(), ['installation_ids', 'release_id'])
 await api.updates.tasks('batch', 3, 'uncertain')
 assert.deepEqual(captured.params, { page: 3, page_size: 20, state: 'uncertain' })
 await api.updates.resolve('task', { state: 'rolled_back', reason: 'verified', agent_stopped: true }, 'resolve-key')
 assert.deepEqual(captured.data, { state: 'rolled_back', reason: 'verified', agent_stopped: true })
 assert.equal(captured.url, '/server/update/tasks/task/resolve')
})
test('discovery contract supports plain and wrapped pages, filters, cancellation and 404', async () => {
 const item = { node_id: 7, node_name: 'node', machine_id: null, installation_id: '12345678-1234-4234-8234-123456789abc', version: 'dev-abcdef1234567', os: 'linux', arch: 'amd64', last_seen_at: '2026-01-01T00:00:00Z' }
 const page = { items: [item], page: 2, page_size: 10, total: 21 }
 const controller = new AbortController()
 for (const data of [page, { data: page }]) {
  response = { status: 200, data }
  assert.deepEqual(await api.updates.discoveries(2, { node_id: 7, machine_id: 9, page_size: 10 }, controller.signal), page)
  assert.equal(captured.method, 'GET'); assert.equal(captured.url, '/server/update/discoveries')
  assert.deepEqual(captured.params, { page: 2, page_size: 10, node_id: 7, machine_id: 9 }); assert.equal(captured.signal, controller.signal)
 }
 response = { status: 404, data: {} }
 await assert.rejects(api.updates.discoveries(1, {}), error => error instanceof api.UpdateError && error.status === 404)
 response = { status: 200, data: { data: {} } }
})
test('latest release source and single install use stable authenticated routes', async () => {
 response = { status: 200, data: { data: { latest: null } } }
 await api.updates.releaseSource(); assert.equal(captured.url, '/server/update/release-source')
 await api.updates.syncReleases('sync-key'); assert.equal(captured.url, '/server/update/releases/sync'); assert.deepEqual(captured.data, {}); assert.equal(captured.headers['Idempotency-Key'], 'sync-key')
 await api.updates.updateLatest('installation', 'install-key'); assert.equal(captured.url, '/server/update/installations/installation/update-latest'); assert.deepEqual(captured.data, {}); assert.equal(captured.headers['Idempotency-Key'], 'install-key')
})
test('batch result labels distinguish successful canceled skipped and failed tasks', () => {
 const states = ['queued','claimed','downloading','verifying_artifacts','installing','verifying','rolling_back','uncertain','succeeded','failed','rolled_back','rollback_failed','canceled','skipped']
 const batch = (counts, state = 'completed') => ({ state, counts: { ...Object.fromEntries(states.map(key => [key, 0])), ...counts } })
 assert.equal(model.batchResultLabel(batch({ succeeded: 2 })), '全部更新成功')
 assert.equal(model.batchResultLabel(batch({ skipped: 2 })), '全部已是目标版本')
 assert.equal(model.batchResultLabel(batch({ canceled: 2 })), '全部任务已取消')
 assert.equal(model.batchResultLabel(batch({ succeeded: 1, canceled: 1 })), '已结束，含取消任务')
 assert.equal(model.batchResultLabel(batch({ succeeded: 1, rolled_back: 1 })), '已结束，有失败任务')
 assert.equal(model.batchResultLabel(batch({ succeeded: 1, skipped: 1 })), '更新完成，含已是目标版本')
 assert.deepEqual(model.batchMetrics(batch({ queued: 1, installing: 1, succeeded: 2 }, 'running')), { total: 4, finished: 2, active: 1, failed: 0, percent: 50 })
 assert.equal(model.batchMetrics(batch({})).percent, 0)
})
test('bulk policy API sends selected revisions in one authenticated write', async () => {
 const data = { enabled: true, installations: [{ id: 'a', revision: 2 }, { id: 'b', revision: 3 }] }
 response = { status: 200, data: { data: { items: [], updated_count: 2, global_enabled: false } } }
 const result = await api.updates.bulkPolicy(data, 'bulk-key')
 assert.equal(captured.url, '/server/update/installations/policies'); assert.equal(captured.method, 'PATCH')
 assert.deepEqual(captured.data, data); assert.equal(captured.headers['Idempotency-Key'], 'bulk-key'); assert.equal(result.updated_count, 2)
})
test('network failure retry keeps the same submission key', async () => {
 const keys = api.createIdempotencyKeys(); const body = { scope: { kind: 'machine', machine_id: 12 } }
 failNetwork = true
 const first = keys.get('enroll', body)
 await assert.rejects(api.updates.enroll(body.scope, first))
 failNetwork = false
 await api.updates.enroll(body.scope, keys.get('enroll', body))
 assert.equal(captured.headers['Idempotency-Key'], first)
})
