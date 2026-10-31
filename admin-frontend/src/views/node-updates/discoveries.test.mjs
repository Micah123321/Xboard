import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import ts from 'typescript'

const view = await readFile(new URL('./NodeUpdatesView.vue', import.meta.url), 'utf8')
const script = view.split('<script setup lang="ts">')[1].split('</script>')[0].replace(/^import .*$/gm, '')
const compiled = ts.transpileModule(script, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.None } }).outputText
export class UpdateError extends Error { constructor(status, code = 'request_failed') { super('http'); this.status = status; this.code = code; this.excluded = [] } }
export const page = (items = []) => ({ items, page: 1, page_size: 20, total: items.length })
export const node = { node_id: 7, node_name: 'node', machine_id: null, installation_id: '12345678-1234-4234-8234-123456789abc', version: 'dev-abcdef0123456789', os: 'linux', arch: 'amd64', last_seen_at: '2026-01-01T00:00:00Z' }
export function setup() {
 const requests = []; const timers = new Map(); let nextTimer = 0; let unmount
 const document = { hidden: false, addEventListener() {}, removeEventListener() {} }
 const updates = {
  overview: async (...args) => { requests.push(args); return page([row()]) },
  releaseSource: async () => ({ latest: null, url: '', checked_at: null, error: null }),
   policy: async (_id, data) => data, updateLatest: async () => ({ id: 'batch' }),
   batches: async () => page(), batch: async () => ({ id: 'batch' }), tasks: async () => page(),
   settings: async () => ({ enabled: false }), releases: async () => page(), installations: async () => page([{ id: 'managed' }]),
 }
 const bindings = { updates, UpdateError, createIdempotencyKeys: () => ({ get: () => 'stable-key', clear() {} }), ref: value => ({ value }), reactive: value => value, computed: fn => ({ get value() { return fn() } }), watch() {}, onMounted() {}, onBeforeUnmount: fn => { unmount = fn }, document, setTimeout: (fn, delay) => { timers.set(++nextTimer, { fn, delay }); return nextTimer }, clearTimeout: id => timers.delete(id), positiveIds: text => [Number(text)], reasonLabel: code => code, ElMessage: { error() {}, success() {} }, ElMessageBox: { confirm: async () => true } }
 const state = new Function(...Object.keys(bindings), compiled + '\nreturn { refresh, visibility, pageChange, applyFilters, overview, overviewError, installations, pages, filters, tab, installLatest, followLatest, releaseSourceError, openOnlineUpdate, onlineState, onlineInstallation, onlineNode, onlineError, onlineOpen, onlineRow, selected, selectedInstallations, pendingBulkPolicy, bulkMessage, toggleSelection, togglePageSelection, clearSelection, setSelectedAutoUpdate, canBulkEnable, pageAllSelected, pagePartlySelected, busy, error, ticketKind, ticketIds, ticketInstanceId, ticketOpen }')(...Object.values(bindings))
 return { ...state, updates, requests, timers, document, unmount: () => unmount() }
}
export const row = (installation = null, discoveries = [node]) => ({ installation_id: installation?.id ?? node.installation_id, installation, discoveries, nodes: discoveries.map(d => ({ id: d.node_id, name: d.node_name, machine_id: d.machine_id })), scope_mismatch_node_ids: [], last_seen_at: node.last_seen_at })
export const managed = (id = node.installation_id, patch = {}) => ({ id, scope: { kind: 'legacy', node_ids: [7] }, versions: { mi_node: 'v1.0.0', xbctl: 'v1.0.0' }, installed_sha256: {}, policy: { enabled: false, revision: 1 }, capability: { status: 'supported' }, runtime: { os: 'linux', arch: 'amd64', init: 'systemd', container: false }, update: { available: true, latest_version: 'v2.0.0' }, revoked_at: null, ...patch })
test('overview keeps discovery-only rows when management settings fail', async () => {
 const s = setup(); s.updates.settings = async () => { throw new UpdateError(404) }; await s.refresh(); assert.equal(s.overview.value.items[0].installation, null); assert.equal([...s.timers.values()][0].delay, 5000)
})
test('overview errors retain data and report unavailable endpoint', async () => {
 const s = setup(); await s.refresh(); const old = s.overview.value; s.updates.overview = async () => { throw new UpdateError(404) }; await s.refresh(); assert.equal(s.overview.value, old); assert.match(s.overviewError.value, /暂未部署/)
})
test('one pagination sends node machine and capability filters', async () => {
 const s = setup(); s.pageChange('overview', 3); await s.refresh(); assert.equal(s.requests.at(-1)[0], 3)
 for (const kind of ['node_id', 'machine_id']) { Object.assign(s.filters, { kind, id: '7', capability_status: 'supported' }); s.applyFilters(); await s.refresh(); assert.deepEqual(s.requests.at(-1)[1], { [kind]: 7, capability_status: 'supported' }); assert.equal(s.pages.overview, 1) }
})
test('online update opens exact installation without node lookups and keeps true scope', async () => {
 const s = setup(); s.updates.installations = () => { throw Error('unexpected lookup') }; const item = row(managed('exact', { scope: { kind: 'legacy', node_ids: [99] } })); item.scope_mismatch_node_ids = [7]; s.openOnlineUpdate(item); assert.equal(s.onlineInstallation.value.id, 'exact'); assert.deepEqual(s.onlineInstallation.value.scope.node_ids, [99]); assert.equal(s.onlineRow.value.scope_mismatch_node_ids[0], 7)
})
test('polling refreshes diagnostic recovery and revoked state by installation identity', async () => {
 const s = setup(); s.openOnlineUpdate(row()); assert.equal(s.onlineState.value, 'bootstrap'); const failure = { state: 'retrying', stage: 'enroll', code: 'invalid_credentials' }; s.updates.overview = async () => page([row(null, [{ ...node, enrollment_status: failure }])]); await s.refresh(); assert.equal(s.onlineNode.value.enrollment_status.code, 'invalid_credentials'); s.updates.overview = async () => page([row(managed())]); await s.refresh(); assert.equal(s.onlineState.value, 'ready'); s.updates.overview = async () => page([row(managed(undefined, { revoked_at: 'revoked' }))]); await s.refresh(); let writes = 0; s.updates.updateLatest = () => writes++; s.updates.policy = () => writes++; await s.installLatest(s.onlineInstallation.value); await s.followLatest(s.onlineInstallation.value, true); assert.equal(writes, 0)
})
test('stale overview response is aborted and cannot replace new page', async () => {
 const s = setup(); let finish; let signal; s.updates.overview = (_page, _filters, abort) => { signal = abort; return new Promise(resolve => { finish = resolve }) }; const first = s.refresh(); s.updates.overview = async () => page([row(managed('new'))]); await s.refresh(); finish(page([row(managed('old'))])); await first; assert.equal(signal.aborted, true); assert.equal(s.overview.value.items[0].installation_id, 'new'); s.unmount(); assert.equal(s.timers.size, 0)
})
