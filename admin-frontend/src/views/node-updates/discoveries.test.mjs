import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import ts from 'typescript'

const view = await readFile(new URL('./NodeUpdatesView.vue', import.meta.url), 'utf8')
const script = view.split('<script setup lang="ts">')[1].split('</script>')[0].replace(/^import .*$/gm, '')
const compiled = ts.transpileModule(script, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.None } }).outputText
class UpdateError extends Error { constructor(status) { super('http'); this.status = status } }
const page = (items = []) => ({ items, page: 1, page_size: 20, total: items.length })
const node = { node_id: 7, node_name: 'node', machine_id: null, installation_id: '12345678-1234-4234-8234-123456789abc', version: 'dev-abcdef0123456789', os: 'linux', arch: 'amd64', last_seen_at: '2026-01-01T00:00:00Z' }
function setup() {
 const requests = []; const timers = new Map(); let nextTimer = 0; let unmount
 const document = { hidden: false, addEventListener() {}, removeEventListener() {} }
 const updates = {
  discoveries: async (...args) => { requests.push(args); return page([node]) },
  settings: async () => ({ enabled: false }), releases: async () => page(), installations: async () => page([{ id: 'managed' }]),
 }
 const bindings = { updates, UpdateError, createIdempotencyKeys: () => ({}), ref: value => ({ value }), reactive: value => value, computed: fn => ({ get value() { return fn() } }), watch() {}, onMounted() {}, onBeforeUnmount: fn => { unmount = fn }, document, setTimeout: (fn, delay) => { timers.set(++nextTimer, { fn, delay }); return nextTimer }, clearTimeout: id => timers.delete(id), positiveIds: text => [Number(text)], reasonLabel: code => code, ElMessage: { error() {} } }
 const state = new Function(...Object.keys(bindings), compiled + '\nreturn { refresh, visibility, pageChange, applyFilters, discoveries, discoveryError, installations, pages, filters, tab }')(...Object.values(bindings))
 return { ...state, updates, requests, timers, document, unmount: () => unmount() }
}
test('discovery survives unavailable management and preserves SHA version', async () => {
 const s = setup(); s.updates.settings = async () => { throw new UpdateError(404) }
 await s.refresh(); assert.deepEqual(s.discoveries.value.items, [node]); assert.equal([...s.timers.values()][0].delay, 5000)
})
test('discovery failures retain data and do not block managed installations', async () => {
 const s = setup(); await s.refresh()
 for (const failure of [new UpdateError(404), new Error('network')]) {
  s.updates.discoveries = async () => { throw failure }; await s.refresh()
  assert.deepEqual(s.discoveries.value.items, [node]); assert.equal(s.installations.value.items[0].id, 'managed')
  assert.match(s.discoveryError.value, failure.status === 404 ? /暂未部署/ : /刷新失败/)
 }
 s.updates.discoveries = async () => page(); await s.refresh(); assert.equal(s.discoveryError.value, ''); assert.equal(s.discoveries.value.total, 0)
})
test('independent discovery pagination and shared ID filters exclude capability', async () => {
 const s = setup(); s.pages.installations = 4; s.pageChange('discoveries', 3); await s.refresh()
 assert.equal(s.requests.at(-1)[0], 3); assert.equal(s.pages.installations, 4)
 for (const kind of ['node_id', 'machine_id']) {
  Object.assign(s.filters, { kind, id: '7', capability_status: 'supported' }); s.applyFilters(); await s.refresh()
  assert.deepEqual(s.requests.at(-1)[1], { [kind]: 7 }); assert.equal(s.pages.discoveries, 1); assert.equal(s.pages.installations, 1)
 }
 s.filters.id = ''; s.applyFilters(); await s.refresh(); assert.deepEqual(s.requests.at(-1)[1], {})
})
test('hidden and unmounted views cancel discovery and ignore stale results', async () => {
 const s = setup(); await s.refresh(); let resolve; let signal
 s.updates.discoveries = (_page, _filters, nextSignal) => { signal = nextSignal; return new Promise(done => { resolve = done }) }
 const pending = s.refresh(); s.document.hidden = true; s.visibility()
 assert.equal(signal.aborted, true); assert.equal(s.timers.size, 0)
 resolve(page([{ ...node, version: 'stale' }])); await pending; assert.equal(s.discoveries.value.items[0].version, node.version)
 s.document.hidden = false; const final = s.refresh(); s.unmount(); assert.equal(signal.aborted, true)
 resolve(page()); await final; assert.equal(s.discoveries.value.total, 1); assert.equal(s.timers.size, 0)
})
