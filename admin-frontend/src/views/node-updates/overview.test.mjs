import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import ts from 'typescript'
const source = await readFile(new URL('../../api/nodeUpdates.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source.replace(/^import .*$/gm, '').replace(/^export /gm, ''), { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.None } }).outputText
test('overview passes one server page and filters, accepts wrapped and direct Page', async () => {
 const page = { items: [{ installation_id: 'one', installation: null, discoveries: [], nodes: [], scope_mismatch_node_ids: [], last_seen_at: null }], total: 21, page: 2, page_size: 20 }; let call; let wrapped = true
 const updates = new Function('adminClient', compiled + ';return updates')({ request: async options => { call = options; return { status: 200, data: wrapped ? { data: page } : page } } }); const signal = new AbortController().signal
 assert.deepEqual(await updates.overview(2, { node_id: 328, capability_status: 'supported' }, signal), page); assert.equal(call.url, '/server/update/overview'); assert.deepEqual(call.params, { page: 2, page_size: 20, node_id: 328, capability_status: 'supported' }); assert.equal(call.signal, signal); wrapped = false; assert.deepEqual(await updates.overview(2, { machine_id: 7 }), page); assert.equal(typeof updates.installations, 'function'); assert.equal(typeof updates.discoveries, 'function')
})
