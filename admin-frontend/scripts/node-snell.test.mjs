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
const {
  applySnellNodeConstraints, createEmptyNodeForm, getNodeProtocolOptions,
  getNodeProtocolLabel, getNodeTransportOptions, supportsNodeSecurity,
  supportsNodeMultiplex, validateNodeForm,
} = await import(optionsUrl)
const { toNodeFormModel, toNodeSavePayload } = await import(await moduleUrl('../src/utils/nodeEditorMapper.ts', { './nodeEditorOptions': optionsUrl }))
const { getNodeTypeLabel } = await import(await moduleUrl('../src/utils/nodes.ts'))

const node = {
  id: 17, name: 'Snell node', type: 'snell', host: 'node.test', port: 443,
  server_port: 8443, show: true, online: 0, online_conn: 0, is_online: 0,
  available_status: 0, group_ids: ['2'], tags: ['test'],
}
const defaults = { version: 4, obfs: 'off', obfs_host: '', udp: false }

test('Snell defaults to v4 TCP and exposes its label and color', () => {
  const form = { ...createEmptyNodeForm(), type: 'snell' }
  assert.deepEqual(toNodeSavePayload(form).protocol_settings, defaults)
  assert.equal(getNodeProtocolLabel('snell'), 'Snell')
  assert.equal(getNodeTypeLabel('SNELL'), 'Snell')
  assert.ok(getNodeProtocolOptions().find(option => option.value === 'snell').dotColor)
  assert.deepEqual(getNodeTransportOptions('snell'), [])
  assert.equal(supportsNodeSecurity('snell'), false)
  assert.equal(supportsNodeMultiplex('snell'), false)
  assert.equal(validateNodeForm(form), null)
})

test('HTTP settings round-trip without retaining shared PSK or unsupported settings', () => {
  const protocol_settings = {
    version: 5, obfs: 'http', obfs_host: ' cover.test ', udp: true, psk: 'old-shared-key',
    tls: 1, tls_settings: { server_name: 'old.test' }, network: 'ws',
    outbound: { type: 'direct' }, certificate: { mode: 'file' },
  }
  const form = toNodeFormModel({ ...node, machine_id: 3, route_ids: ['8'], protocol_settings })
  const payload = toNodeSavePayload(form)
  assert.deepEqual(payload.protocol_settings, { version: 4, obfs: 'http', obfs_host: 'cover.test', udp: false })
  assert.equal(payload.id, node.id)
  assert.deepEqual(payload.group_ids, ['2'])
  assert.deepEqual(payload.tags, ['test'])
  assert.equal(payload.machine_id, null)
  assert.deepEqual(payload.route_ids, [])
  assert.equal(form.machineId, null)
  assert.equal(form.tlsMode, 0)
  assert.equal(form.network, '')
  assert.equal(form.hysteriaVersion, 2)
  assert.equal(form.tuicVersion, 5)
  assert.equal(form.shadowsocksObfs, '')
  assert.equal(protocol_settings.version, 5)
  assert.equal(protocol_settings.psk, 'old-shared-key')
  assert.equal(validateNodeForm(form), null)
  const reloaded = toNodeFormModel({ ...node, ...payload })
  assert.deepEqual(toNodeSavePayload(reloaded), payload)
})

test('missing and disabled obfuscation use an empty host', () => {
  for (const protocol_settings of [null, {}, { obfs: 'off', obfs_host: 'stale.test' }]) {
    const form = toNodeFormModel({ ...node, protocol_settings })
    assert.equal(form.snellObfsHost, '')
    assert.deepEqual(toNodeSavePayload(form).protocol_settings, defaults)
  }
  const form = { ...createEmptyNodeForm(), type: 'snell', snellObfsHost: 'stale.test' }
  assert.deepEqual(toNodeSavePayload(form).protocol_settings, defaults)
})

test('Snell serialization drops machine and routes even before UI constraints run', () => {
  const form = {
    ...createEmptyNodeForm(), type: 'snell', machineId: 3, routeIds: [8],
    network: 'xhttp', xhttpExtra: 'invalid JSON', tlsMode: 2,
    rawProtocolSettings: { custom: 'old', psk: 'old-shared-key' }, originalType: 'snell',
  }
  const payload = toNodeSavePayload(form)
  assert.deepEqual(payload.protocol_settings, defaults)
  assert.equal(payload.machine_id, null)
  assert.deepEqual(payload.route_ids, [])
})

test('switching to Snell clears incompatible protocol state', () => {
  const form = toNodeFormModel({ ...node, type: 'vless', machine_id: 3, route_ids: [8], protocol_settings: {
    network: 'xhttp', network_settings: { extra: { mode: 'packet-up' } },
    tls: 2, reality_settings: { public_key: 'old', private_key: 'old' },
    tls_settings: { server_name: 'old.test', ech: { enabled: true, config: 'old' } },
    utls: { enabled: true }, multiplex: { enabled: true, brutal: { enabled: true } },
    certificate: { mode: 'file' }, outbound: { type: 'direct' },
  } })
  form.type = 'snell'
  applySnellNodeConstraints(form)
  assert.equal(form.machineId, null)
  assert.deepEqual(form.routeIds, [])
  assert.equal(form.network, '')
  assert.equal(form.tlsMode, 0)
  assert.equal(form.realityPrivateKey, '')
  assert.equal(form.tlsServerName, '')
  assert.equal(form.echEnabled, false)
  assert.equal(form.echConfig, '')
  assert.equal(form.utlsEnabled, false)
  assert.equal(form.multiplexEnabled, false)
  assert.equal(form.multiplexBrutalEnabled, false)
  assert.deepEqual(form.rawProtocolSettings, {})
  assert.equal(validateNodeForm(form), null)
  assert.deepEqual(toNodeSavePayload(form).protocol_settings, defaults)
})

test('Snell validation rejects bindings, routes, invalid obfuscation and multiline hosts', () => {
  const form = { ...createEmptyNodeForm(), type: 'snell' }
  for (const override of [
    { machineId: 3 }, { routeIds: [8] }, { snellObfs: 'tls' },
    { snellObfs: 'http', snellObfsHost: 'x'.repeat(256) },
    { snellObfs: 'http', snellObfsHost: 'host.test\r\nInjected: yes' },
  ]) {
    assert.ok(validateNodeForm({ ...form, ...override }))
  }
  assert.equal(validateNodeForm({ ...form, snellObfs: 'http', snellObfsHost: '' }), null)
})

test('editing other protocols retains routes and protocol extensions without changing machine binding', () => {
  for (const { value: type } of getNodeProtocolOptions().filter(option => option.value !== 'snell')) {
    const form = toNodeFormModel({ ...node, type, machine_id: 3, route_ids: ['8'], protocol_settings: { custom: 'preserved' } })
    const payload = toNodeSavePayload(form)
    assert.equal(payload.protocol_settings.custom, 'preserved', type)
    assert.deepEqual(payload.route_ids, ['8'], type)
    assert.equal(form.machineId, 3, type)
    assert.equal(Object.hasOwn(payload, 'machine_id'), false, type)
    assert.equal(Object.hasOwn(payload.protocol_settings, 'udp'), false, type)
  }
})
