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
test('network failure retry keeps the same submission key', async () => {
 const keys = api.createIdempotencyKeys(); const body = { scope: { kind: 'machine', machine_id: 12 } }
 failNetwork = true
 const first = keys.get('enroll', body)
 await assert.rejects(api.updates.enroll(body.scope, first))
 failNetwork = false
 await api.updates.enroll(body.scope, keys.get('enroll', body))
 assert.equal(captured.headers['Idempotency-Key'], first)
})
