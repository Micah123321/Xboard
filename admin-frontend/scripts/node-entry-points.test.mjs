import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import ts from 'typescript'
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc'
import { computed, effectScope, nextTick, reactive, ref, watch } from 'vue'
import { compileString } from 'sass'

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
const { createEmptyNodeForm, createNodeEntryPoint, getNodeProtocolOptions, validateNodeForm, NODE_ENTRY_POINT_LIMIT } = options
const { toNodeFormModel, toNodeSavePayload } = mapper
const node = {
  id: 17, name: 'Entry node', type: 'http', host: 'main.example.test', port: 443,
  server_port: 8443, parent_id: 2, show: true,
}
const entries = [{ host: 'extra.example.test', port: 443 }, { host: '2001:db8::1', port: 8443 }]
const enabledForm = () => toNodeFormModel({ ...node, entry_points_enabled: true, entry_points: entries })

test('new and legacy nodes default to disabled entry points with independent lists', () => {
  const forms = [createEmptyNodeForm(), toNodeFormModel(), toNodeFormModel(null), toNodeFormModel(node)]
  for (const form of forms) {
    assert.equal(form.entryPointsEnabled, false)
    assert.deepEqual(form.entryPoints, [])
    const payload = toNodeSavePayload(form)
    assert.equal(payload.entry_points_enabled, false)
    assert.deepEqual(payload.entry_points, [])
  }
  forms[0].entryPoints.push(createNodeEntryPoint())
  assert.deepEqual(forms[1].entryPoints, [])
})

test('every protocol round-trips enabled and disabled entry points without changing the main or server port', () => {
  for (const { value: type } of getNodeProtocolOptions()) {
    for (const entry_points_enabled of [false, true]) {
      const input = { ...node, type, entry_points_enabled, entry_points: entries }
      const form = toNodeFormModel(input)
      assert.equal(form.entryPointsEnabled, entry_points_enabled, type)
      assert.ok(form.entryPoints.every((entry) => typeof entry.port === 'string'))
      const payload = toNodeSavePayload(form)
      assert.equal(payload.entry_points_enabled, entry_points_enabled, type)
      assert.deepEqual(payload.entry_points, entries)
      assert.equal(payload.host, node.host)
      assert.equal(payload.port, String(node.port))
      assert.equal(payload.server_port, String(node.server_port))
      assert.equal(payload.tcp_check_enabled, false)
      assert.deepEqual(toNodeSavePayload(toNodeFormModel({ ...input, ...payload })), payload, type)
    }
  }
})

test('mapping deep-copies each row, trims hosts, canonicalizes IPv6 and emits numeric ports without UI keys', () => {
  const input = { ...node, host: ' [2001:0DB8::2] ', entry_points_enabled: true,
    entry_points: [{ host: ' [2001:0DB8:0000::1] ', port: 8443 }, { host: ' extra.test ', port: 443 }] }
  const before = structuredClone(input)
  const form = toNodeFormModel(input)
  assert.equal(form.host, '2001:db8::2')
  assert.equal(form.entryPoints[0].host, '2001:db8::1')
  assert.notEqual(form.entryPoints[0], input.entry_points[0])
  assert.notEqual(form.entryPoints[0].key, form.entryPoints[1].key)
  form.entryPoints[1].port = ' 00443 '
  const payload = toNodeSavePayload(form)
  assert.deepEqual(payload.entry_points, [{ host: '2001:db8::1', port: 8443 }, { host: 'extra.test', port: 443 }])
  assert.equal(payload.host, '2001:db8::2')
  form.entryPoints[0].host = 'changed.test'
  form.entryPoints.push(createNodeEntryPoint('new.test', '80'))
  assert.equal(payload.entry_points[0].host, '2001:db8::1')
  assert.equal(payload.entry_points.length, 2)
  assert.deepEqual(input, before)
})

test('disabling preserves entries across save and reload and keeps legacy main port ranges available', () => {
  const form = enabledForm()
  form.entryPointsEnabled = false
  form.port = '20000-30000'
  assert.equal(validateNodeForm(form), null)
  const payload = toNodeSavePayload(form)
  assert.equal(payload.entry_points_enabled, false)
  assert.deepEqual(payload.entry_points, entries)
  assert.equal(payload.port, '20000-30000')
  const reloaded = toNodeFormModel({ ...node, ...payload })
  assert.deepEqual(toNodeSavePayload(reloaded).entry_points, entries)
  reloaded.entryPointsEnabled = true
  assert.ok(validateNodeForm(reloaded))
  reloaded.port = '443'
  assert.equal(validateNodeForm(reloaded), null)
})

test('enabling requires at least one extra entry and allows exactly twenty', () => {
  const form = enabledForm()
  form.entryPoints = []
  assert.ok(validateNodeForm(form))
  form.entryPointsEnabled = false
  assert.equal(validateNodeForm(form), null)
  form.entryPoints = Array.from({ length: NODE_ENTRY_POINT_LIMIT }, (_, index) => createNodeEntryPoint('extra-' + index + '.test', '443'))
  form.entryPointsEnabled = true
  assert.equal(validateNodeForm(form), null)
  form.entryPoints.push(createNodeEntryPoint('overflow.test', '443'))
  for (const enabled of [false, true]) {
    form.entryPointsEnabled = enabled
    assert.ok(validateNodeForm(form))
  }
})

test('main and extra ports require one decimal integer from 1 through 65535 in random mode', () => {
  const form = enabledForm()
  for (const port of ['1', '65535', ' 00443 ']) {
    form.port = port
    form.entryPoints[0].port = port
    assert.equal(validateNodeForm(form), null, port)
    assert.equal(toNodeSavePayload(form).entry_points[0].port, Number(port))
  }
  for (const port of ['', ' ', '0', '-1', '65536', '1.5', '443.0', '1e3', '0x50', '+80', '80-90', '80,443', '80/443', '80 443', '80\n443', 'NaN', 'Infinity']) {
    form.port = port
    form.entryPoints[0].port = '443'
    assert.ok(validateNodeForm(form), 'main: ' + port)
    form.port = '443'
    form.entryPoints[0].port = port
    for (const enabled of [false, true]) {
      form.entryPointsEnabled = enabled
      assert.ok(validateNodeForm(form), 'extra: ' + port)
    }
    form.entryPointsEnabled = true
  }
})

test('hosts accept domains, IPv4 and bracketed or bare IPv6 while rejecting URLs and malformed hosts', () => {
  for (const host of ['edge.example.test', 'EDGE.example.test.', '192.0.2.1', '2001:db8::3', '[2001:0db8::3]', '::1', '::ffff:192.0.2.3']) {
    const form = enabledForm()
    form.entryPoints = [createNodeEntryPoint(host, '80')]
    assert.equal(validateNodeForm(form), null, host)
    form.host = host
    form.entryPoints[0].host = 'different.test'
    assert.equal(validateNodeForm(form), null, 'main: ' + host)
  }
  for (const host of ['', ' ', 'https://edge.test', '//edge.test', 'edge.test/path', 'user@edge.test', 'edge.test:443', 'edge.test?x=1', 'edge.test#x', '[edge.test]', '[192.0.2.1]', '256.1.1.1', '192.0.2', '01.2.3.4', '2001:::1', '[::1', '::ffff:999.1.2.3', 'a..test', '-a.test', 'a_.test', 'a'.repeat(64) + '.test', Array(5).fill('a'.repeat(63)).join('.')]) {
    const form = enabledForm()
    form.entryPoints = [createNodeEntryPoint(host, '80')]
    assert.ok(validateNodeForm(form), 'extra: ' + host)
    form.entryPoints[0].host = 'extra.test'
    form.host = host
    assert.ok(validateNodeForm(form), 'main: ' + host)
  }
})

test('empty rows and duplicate host-port combinations are rejected even when disabled', () => {
  for (const enabled of [false, true]) {
    for (const [host, port] of [['', '443'], ['extra.test', ''], [' ', ' ']]) {
      const form = enabledForm()
      form.entryPointsEnabled = enabled
      form.entryPoints = [createNodeEntryPoint(host, port)]
      assert.ok(validateNodeForm(form))
    }
    for (const [main, extra] of [
      ['main.example.test', ' MAIN.EXAMPLE.TEST. '],
      ['192.0.2.1', '192.0.2.1'],
      ['2001:db8::1', '[2001:0DB8:0000::1]'],
      ['::ffff:192.0.2.1', '0:0:0:0:0:ffff:c000:201'],
    ]) {
      const form = enabledForm()
      form.entryPointsEnabled = enabled
      form.host = main
      form.entryPoints = [createNodeEntryPoint(extra, '00443')]
      assert.ok(validateNodeForm(form), 'main duplicate: ' + main)
      form.host = 'other.test'
      form.entryPoints.push(createNodeEntryPoint(main, '443'))
      assert.ok(validateNodeForm(form), 'extra duplicate: ' + main)
      form.entryPoints[1].port = '444'
      assert.equal(validateNodeForm(form), null, 'different port: ' + main)
    }
  }
})

const dialogSource = await readFile(new URL('../src/views/nodes/NodeEditorDialog.vue', import.meta.url), 'utf8')
const { descriptor, errors } = parse(dialogSource)
assert.deepEqual(errors, [])
const setupSource = ts.createSourceFile('NodeEditorDialog.ts', descriptor.scriptSetup.content, ts.ScriptTarget.Latest, true)
const setupBody = setupSource.statements.filter((statement) => !ts.isImportDeclaration(statement))
  .map((statement) => statement.getFullText(setupSource)).join('\n')
const { outputText: setupScript } = ts.transpileModule(setupBody, {
  compilerOptions: { module: ts.ModuleKind.None, target: ts.ScriptTarget.ES2022 },
})

test('actual dialog handlers preserve rows on toggle, keep stable keys on delete and enforce the add limit', async (t) => {
  const scope = effectScope()
  t.after(() => scope.stop())
  const bindings = {
    computed, nextTick, reactive, ref, watch, ...options, ...mapper,
    defineProps: () => ({ visible: true, mode: 'edit', node: { ...node, entry_points_enabled: true, entry_points: entries }, plans: [], allNodes: [] }),
    defineEmits: () => () => {},
  }
  const { form, addEntryPoint, removeEntryPoint } = scope.run(() => new Function(...Object.keys(bindings), setupScript + '\nreturn { form, addEntryPoint, removeEntryPoint }')(...Object.values(bindings)))
  const originalRows = form.entryPoints
  const keys = form.entryPoints.map((entry) => entry.key)
  form.entryPointsEnabled = false
  await nextTick()
  assert.equal(form.entryPoints, originalRows)
  assert.deepEqual(toNodeSavePayload(form).entry_points, entries)
  form.entryPointsEnabled = true
  await nextTick()
  assert.deepEqual(form.entryPoints.map((entry) => entry.key), keys)
  removeEntryPoint(0)
  assert.equal(form.entryPoints[0].key, keys[1])
  while (form.entryPoints.length < NODE_ENTRY_POINT_LIMIT) addEntryPoint()
  addEntryPoint()
  assert.equal(form.entryPoints.length, NODE_ENTRY_POINT_LIMIT)
  assert.equal(new Set(form.entryPoints.map((entry) => entry.key)).size, NODE_ENTRY_POINT_LIMIT)
  assert.equal(form.entryPoints[0].key, keys[1])
})

test('editor template and responsive SCSS compile without a full build', async () => {
  const script = compileScript(descriptor, { id: 'node-entry-points' })
  const template = compileTemplate({
    source: descriptor.template.content, filename: 'NodeEditorDialog.vue', id: 'node-entry-points',
    compilerOptions: { bindingMetadata: script.bindings },
  })
  assert.deepEqual(template.errors, [])
  const styles = await readFile(new URL('../src/views/nodes/NodeEditorDialog.scss', import.meta.url), 'utf8')
  assert.ok(compileString(styles).css.includes('.entry-point-row'))
})
