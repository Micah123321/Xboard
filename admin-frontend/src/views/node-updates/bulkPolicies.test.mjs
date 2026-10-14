import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import ts from 'typescript'

const view = await readFile(new URL('./NodeUpdatesView.vue', import.meta.url), 'utf8')
const script = view.split('<script setup lang="ts">')[1].split('</script>')[0].replace(/^import .*$/gm, '')
const compiled = ts.transpileModule(script, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.None } }).outputText
const page = (items = []) => ({ items, page: 1, page_size: 20, total: items.length })
const managed = (id, revision = 1, patch = {}) => ({ id, policy: { enabled: false, follow_latest: false, target_release_id: null, revision }, capability: { status: 'supported' }, runtime: { os: 'linux', arch: 'amd64', init: 'systemd', container: false }, revoked_at: null, ...patch })
class UpdateError extends Error {
 constructor(code) { super(code); this.code = code; this.excluded = [] }
}
function setup() {
 const updates = { discoveries: async () => page(), settings: async () => ({ enabled: false }), releaseSource: async () => ({}), releases: async () => page(), installations: async () => page() }
 const keyStore = new Map(); let nextKey = 0
 const bindings = {
  updates, UpdateError, createIdempotencyKeys: () => ({ get(operation, payload) { const fingerprint = JSON.stringify([operation, payload]); if (!keyStore.has(fingerprint)) keyStore.set(fingerprint, String(++nextKey)); return keyStore.get(fingerprint) }, clear(operation, payload) { keyStore.delete(JSON.stringify([operation, payload])) } }),
  ref: value => ({ value }), reactive: value => value, computed: fn => ({ get value() { return fn() } }), watch() {}, onMounted() {}, onBeforeUnmount() {},
  document: { hidden: false }, setTimeout: () => 0, clearTimeout() {}, reasonLabel: code => code, ElMessage: { error() {}, success() {} }
 }
 const state = new Function(...Object.keys(bindings), compiled + 'return { refresh, installations, selected, selectedInstallations, pendingBulkPolicy, bulkMessage, toggleSelection, togglePageSelection, clearSelection, setSelectedAutoUpdate, canBulkEnable, pageAllSelected, pagePartlySelected, busy, error }')(...Object.values(bindings))
 return { ...state, updates }
}
function response(data, globalEnabled = true) {
 return { items: data.installations.map(item => ({ id: item.id, policy: { enabled: data.enabled, follow_latest: data.enabled, target_release_id: null, revision: item.revision + 1 } })), updated_count: data.installations.length, global_enabled: globalEnabled }
}
test('bulk auto update selects across pages and changes policies in one request', async () => {
 const s = setup(); s.installations.value = page([managed('a', 2)]); s.togglePageSelection(true)
 s.installations.value = page([managed('b', 4)]); s.togglePageSelection(true)
 assert.deepEqual(s.selected.value, ['a', 'b']); assert.equal(s.canBulkEnable.value, true)
 const writes = []; s.updates.bulkPolicy = async (data, key) => { writes.push({ data, key }); return response(data, false) }
 await s.setSelectedAutoUpdate(true)
 assert.deepEqual(writes[0].data, { enabled: true, installations: [{ id: 'a', revision: 2 }, { id: 'b', revision: 4 }] })
 assert.match(s.bulkMessage.value, /全局自动更新当前关闭/)
 await s.setSelectedAutoUpdate(false)
 assert.deepEqual(writes[1].data, { enabled: false, installations: [{ id: 'a', revision: 3 }, { id: 'b', revision: 5 }] })
 assert.equal(s.selectedInstallations.value.a.policy.enabled, false)
 s.clearSelection(); assert.deepEqual(s.selected.value, []); assert.deepEqual(s.selectedInstallations.value, {})
})
test('unsupported enabled policies can be selected and disabled while invalid installs are excluded', async () => {
 const s = setup(); const supported = managed('a')
 const disabled = managed('b', 1, { capability: { status: 'unsupported' } })
 const enabled = managed('c', 3, { capability: { status: 'unsupported' }, policy: { enabled: true, follow_latest: true, revision: 3 } })
 const revoked = managed('d', 1, { revoked_at: '2026-10-14' })
 s.installations.value = page([supported, disabled, enabled, revoked]); s.togglePageSelection(true)
 assert.deepEqual(s.selected.value, ['a', 'c']); assert.equal(s.pageAllSelected.value, true); assert.equal(s.canBulkEnable.value, false)
 let calls = 0; s.updates.bulkPolicy = async data => { calls++; return response(data) }
 await s.setSelectedAutoUpdate(true); assert.equal(calls, 0)
 await s.setSelectedAutoUpdate(false); assert.equal(calls, 1)
 s.installations.value = page([supported]); s.togglePageSelection(false); assert.deepEqual(s.selected.value, ['c'])
})
test('lost response retry preserves payload and key even after polling sees newer revisions', async () => {
 const s = setup(); s.toggleSelection(managed('a', 2), true)
 const requests = []; s.updates.bulkPolicy = async (data, key) => { requests.push({ data: structuredClone(data), key }); if (requests.length === 1) throw new Error('lost ACK'); return response(data) }
 await s.setSelectedAutoUpdate(true); assert.ok(s.pendingBulkPolicy.value)
 s.updates.installations = async () => page([managed('a', 3)])
 await s.refresh(); await s.setSelectedAutoUpdate(true)
 assert.deepEqual(requests[1], requests[0]); assert.equal(s.pendingBulkPolicy.value, undefined)
})
test('revision conflict retains selection and uses refreshed revision on explicit retry', async () => {
 const s = setup(); s.toggleSelection(managed('a', 2), true)
 s.updates.bulkPolicy = async () => { throw new UpdateError('revision_conflict') }
 s.updates.installations = async () => page([managed('a', 5)])
 await s.setSelectedAutoUpdate(true)
 assert.deepEqual(s.selected.value, ['a']); assert.equal(s.pendingBulkPolicy.value, undefined); assert.equal(s.selectedInstallations.value.a.policy.revision, 5)
 let sent; s.updates.bulkPolicy = async data => { sent = data; return response(data) }
 await s.setSelectedAutoUpdate(false); assert.equal(sent.installations[0].revision, 5)
})
test('selection is locked during saving and page selection rejects more than 1000 installs', async () => {
 const s = setup(); s.toggleSelection(managed('a'), true); let resolve
 s.updates.bulkPolicy = () => new Promise(done => { resolve = done })
 const pending = s.setSelectedAutoUpdate(true)
 s.clearSelection(); s.toggleSelection(managed('b'), true); assert.deepEqual(s.selected.value, ['a'])
 resolve({ items: [], updated_count: 1, global_enabled: true }); await pending
 s.selected.value = Array.from({ length: 1000 }, (_, index) => String(index))
 s.installations.value = page([managed('next')]); s.togglePageSelection(true); assert.equal(s.selected.value.length, 1000)
})
