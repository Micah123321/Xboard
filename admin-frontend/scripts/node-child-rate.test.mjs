import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import ts from 'typescript'
import { parse } from '@vue/compiler-sfc'
import { computed, effectScope, nextTick, reactive, ref, watch } from 'vue'

async function moduleUrl(path, imports = {}) {
  const source = await readFile(new URL(path, import.meta.url), 'utf8')
  let { outputText } = ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 },
  })
  for (const [specifier, url] of Object.entries(imports)) {
    outputText = outputText.replaceAll("'" + specifier + "'", JSON.stringify(url))
  }
  return 'data:text/javascript;base64,' + Buffer.from(outputText).toString('base64')
}

const optionsUrl = await moduleUrl('../src/utils/nodeEditorOptions.ts')
const options = await import(optionsUrl)
const mapper = await import(await moduleUrl('../src/utils/nodeEditorMapper.ts', { './nodeEditorOptions': optionsUrl }))
const { createEmptyNodeForm, getNodeProtocolOptions } = options
const { toNodeFormModel, toNodeSavePayload } = mapper
const node = {
  id: 17, name: 'Child node', type: 'http', host: 'node.test', port: 443,
  server_port: 8443, parent_id: 2, rate: 1.5, show: true,
}

test('new and legacy nodes default to disabled child rates', () => {
  for (const form of [createEmptyNodeForm(), toNodeFormModel(), toNodeFormModel(node)]) {
    assert.equal(form.childRateEnabled, false)
    assert.equal(toNodeSavePayload(form).child_rate_enabled, false)
  }
})

test('supported child nodes round-trip the flag and reuse existing rate fields', () => {
  const rate_time_ranges = [{ start: '08:00', end: '10:00', rate: 2 }]
  for (const { value: type } of getNodeProtocolOptions().filter(option => option.value !== 'snell')) {
    for (const child_rate_enabled of [false, true]) {
      const input = { ...node, type, child_rate_enabled, rate_time_enable: true, rate_time_ranges }
      const form = toNodeFormModel(input)
      const payload = toNodeSavePayload(form)
      assert.equal(form.childRateEnabled, child_rate_enabled, type)
      assert.equal(payload.child_rate_enabled, child_rate_enabled, type)
      assert.equal(payload.rate, 1.5)
      assert.deepEqual(payload.rate_time_ranges, rate_time_ranges)
      assert.equal(toNodeFormModel({ ...input, ...payload }).childRateEnabled, child_rate_enabled, type)
    }
  }
})

test('parentless and Snell nodes clear stale flags when loaded or saved', () => {
  for (const override of [{ parent_id: null }, { parent_id: undefined }, { type: 'snell' }]) {
    const form = toNodeFormModel({ ...node, child_rate_enabled: true, ...override })
    assert.equal(form.childRateEnabled, false)
    form.childRateEnabled = true
    assert.equal(toNodeSavePayload(form).child_rate_enabled, false)
  }
})

const dialogSource = await readFile(new URL('../src/views/nodes/NodeEditorDialog.vue', import.meta.url), 'utf8')
const { descriptor, errors } = parse(dialogSource)
assert.deepEqual(errors, [])
// Run the actual setup watchers with Vue reactivity, without mounting a DOM.
const setupSource = ts.createSourceFile('NodeEditorDialog.ts', descriptor.scriptSetup.content, ts.ScriptTarget.Latest, true)
const setupBody = setupSource.statements
  .filter(statement => !ts.isImportDeclaration(statement))
  .map(statement => statement.getFullText(setupSource)).join('\n')
const { outputText: setupScript } = ts.transpileModule(setupBody, {
  compilerOptions: { module: ts.ModuleKind.None, target: ts.ScriptTarget.ES2022 },
})

function createDialogForm(t) {
  const scope = effectScope()
  t.after(() => scope.stop())
  const bindings = {
    computed, nextTick, reactive, ref, watch, ...options, ...mapper,
    defineProps: () => ({ visible: true, mode: 'edit', node: { ...node, child_rate_enabled: true }, plans: [], allNodes: [] }),
    defineEmits: () => () => {},
  }
  return scope.run(() => new Function(...Object.keys(bindings), setupScript + '\nreturn form')(...Object.values(bindings)))
}

test('clearing the parent resets the actual dialog switch and does not restore it on reselect', async t => {
  for (const cleared of [null, undefined, '']) {
    const form = createDialogForm(t)
    assert.equal(form.childRateEnabled, true)
    form.parentId = cleared
    await nextTick()
    assert.equal(form.childRateEnabled, false)
    assert.equal(toNodeSavePayload(form).child_rate_enabled, false)
    assert.equal(toNodeSavePayload(form).parent_id, null)
    form.parentId = node.parent_id
    await nextTick()
    assert.equal(form.childRateEnabled, false)
  }
})

test('switching the dialog to Snell resets the switch and keeps it off when switching back', async t => {
  const form = createDialogForm(t)
  form.type = 'snell'
  await nextTick()
  assert.equal(form.childRateEnabled, false)
  assert.equal(toNodeSavePayload(form).child_rate_enabled, false)
  form.type = 'http'
  await nextTick()
  assert.equal(form.childRateEnabled, false)
})
