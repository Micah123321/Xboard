import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import ts from 'typescript'

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
const { createEmptyNodeForm, generateRandomHysteriaObfsPassword, validateNodeForm } = await import(optionsUrl)
const { toNodeFormModel, toNodeSavePayload } = await import(await moduleUrl('../src/utils/nodeEditorMapper.ts', { './nodeEditorOptions': optionsUrl }))
const node = { id: 7, type: 'hysteria', name: 'HY2 fixture', host: 'node.test', port: 26111, server_port: 26111 }

test('new HY2 nodes serialize ready-to-use defaults and independently generated passwords', () => {
  const form = { ...createEmptyNodeForm(), type: 'hysteria' }
  const settings = toNodeSavePayload(form).protocol_settings
  assert.equal(settings.version, 2)
  assert.deepEqual(settings.bandwidth, { up: 0, down: 0 })
  assert.equal(settings.hop_interval, 30)
  assert.equal(settings.obfs.open, true)
  assert.equal(settings.obfs.type, 'salamander')
  assert.match(settings.obfs.password, /^[a-f0-9]{32}$/)
  assert.notEqual(settings.obfs.password, createEmptyNodeForm().hysteriaObfsPassword)
  assert.notEqual(settings.obfs.password, generateRandomHysteriaObfsPassword())
  assert.equal(validateNodeForm(form), null)
})

test('editing retains existing bandwidth, zero hop interval and obfuscation credentials', () => {
  const protocol_settings = { version: 2, bandwidth: { up: 200, down: 300 }, hop_interval: 0, obfs: { open: true, type: 'salamander', password: 'existing-password' } }
  const form = toNodeFormModel({ ...node, protocol_settings })
  const settings = toNodeSavePayload(form).protocol_settings
  assert.equal(settings.version, 2)
  assert.deepEqual(settings.bandwidth, protocol_settings.bandwidth)
  assert.equal(settings.hop_interval, 0)
  assert.deepEqual(settings.obfs, protocol_settings.obfs)
  assert.equal(validateNodeForm(form), null)
})

test('legacy empty settings get numeric defaults while obfuscation stays disabled', () => {
  for (const version of [undefined, null, 2]) {
    const settings = toNodeSavePayload(toNodeFormModel({ ...node, protocol_settings: { version } })).protocol_settings
    assert.equal(settings.version, 2)
    assert.deepEqual(settings.bandwidth, { up: 0, down: 0 })
    assert.equal(settings.hop_interval, 30)
    assert.equal(settings.obfs.open, false)
    assert.equal(settings.obfs.password, undefined)
  }
})

test('HY1 version, bandwidth and password survive editing', () => {
  const protocol_settings = { version: 1, bandwidth: { up: 100, down: 150 }, hop_interval: 14, obfs: { open: true, password: 'hy1-password' } }
  const settings = toNodeSavePayload(toNodeFormModel({ ...node, protocol_settings })).protocol_settings
  assert.equal(settings.version, 1)
  assert.deepEqual(settings.bandwidth, protocol_settings.bandwidth)
  assert.equal(settings.hop_interval, 14)
  assert.equal(settings.obfs.password, protocol_settings.obfs.password)
})

test('switching from another protocol keeps HY2 defaults independent of its version field', () => {
  const form = toNodeFormModel({ ...node, type: 'tuic', protocol_settings: { version: 5 } })
  form.type = 'hysteria'
  const settings = toNodeSavePayload(form).protocol_settings
  assert.equal(settings.version, 2)
  assert.equal(settings.obfs.open, true)
  assert.match(settings.obfs.password, /^[a-f0-9]{32}$/)
})
