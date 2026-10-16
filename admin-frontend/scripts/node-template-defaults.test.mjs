import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import ts from 'typescript'

async function moduleUrl(path, imports = {}) {
  const source = await readFile(new URL(path, import.meta.url), 'utf8')
  let { outputText } = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } })
  for (const [specifier, url] of Object.entries(imports)) outputText = outputText.replaceAll("'" + specifier + "'", JSON.stringify(url))
  return 'data:text/javascript;base64,' + Buffer.from(outputText).toString('base64')
}
const optionsUrl = await moduleUrl('../src/utils/nodeEditorOptions.ts')
const { createEmptyNodeForm, applyNodeProtocolDefaults, getNodeProtocolOptions, validateNodeForm, NODE_KCP_HEADER_OPTIONS } = await import(optionsUrl)
const { toNodeFormModel, toNodeSavePayload } = await import(await moduleUrl('../src/utils/nodeEditorMapper.ts', { './nodeEditorOptions': optionsUrl }))
const node = { id: 17, name: 'Fixture', host: 'node.test', port: 443, server_port: 443 }

test('resetting an edited form for creation clears its node ID and original settings', () => {
  const form = toNodeFormModel({ ...node, type: 'tuic', protocol_settings: { version: 4 } })
  Object.assign(form, createEmptyNodeForm())
  assert.equal(form.id, undefined)
  assert.equal(form.originalType, '')
  assert.deepEqual(form.rawProtocolSettings, {})
  form.type = 'naive'
  applyNodeProtocolDefaults(form)
  assert.equal(form.tlsMode, 1)
  assert.equal(toNodeSavePayload(form).id, undefined)
})

test('every new protocol has a valid default configuration after common connection data is supplied', () => {
  for (const { value: type } of getNodeProtocolOptions()) {
    const form = { ...createEmptyNodeForm(), type, name: 'Fixture', host: 'node.test', port: '443', serverPort: '443', tlsServerName: 'node.test' }
    applyNodeProtocolDefaults(form)
    assert.equal(validateNodeForm(form), null, type)
    assert.ok(toNodeSavePayload(form).protocol_settings, type)
  }
})

test('TUIC null and empty versions default to V5 and other protocols cannot overwrite it', () => {
  for (const version of [null, undefined, '']) {
    const form = toNodeFormModel({ ...node, type: 'tuic', protocol_settings: { version } })
    assert.equal(form.tuicVersion, 5)
    assert.equal(toNodeSavePayload(form).protocol_settings.version, 5)
  }
  const form = toNodeFormModel({ ...node, type: 'hysteria', protocol_settings: { version: 2, obfs: { open: true, type: 'salamander', password: 'fixture' } } })
  form.type = 'tuic'
  applyNodeProtocolDefaults(form)
  assert.equal(form.tuicVersion, 5)
})

test('switching HY2 to Shadowsocks never serializes the HY2 obfuscation object', () => {
  const form = toNodeFormModel({ ...node, type: 'hysteria', protocol_settings: { version: 2, obfs: { open: true, type: 'salamander', password: 'fixture' } } })
  form.type = 'shadowsocks'
  applyNodeProtocolDefaults(form)
  assert.equal(form.shadowsocksObfs, '')
  assert.equal(toNodeSavePayload(form).protocol_settings.obfs, undefined)
})

test('switching from VLESS KCP/Reality to VMess resets unavailable choices', () => {
  const form = { ...createEmptyNodeForm(), type: 'vless', network: 'kcp', tlsMode: 2 }
  form.type = 'vmess'
  applyNodeProtocolDefaults(form)
  assert.equal(form.network, 'tcp')
  assert.equal(form.tlsMode, 0)
  assert.equal(NODE_KCP_HEADER_OPTIONS.some(option => option.value === 'http'), false)
})

test('transport paths are prefilled and cleared TCP HTTP paths remain safe to export', () => {
  const form = { ...createEmptyNodeForm(), type: 'vmess', network: 'tcp', tcpHeaderType: 'http', tcpRequestPath: '' }
  assert.deepEqual(toNodeSavePayload(form).protocol_settings.network_settings.header.request.path, ['/'])
  for (const [network, field] of [['ws', 'wsPath'], ['h2', 'h2Path'], ['httpupgrade', 'httpupgradePath'], ['xhttp', 'xhttpPath']]) {
    form.network = network
    assert.equal(form[field], '/')
    assert.equal(toNodeSavePayload(form).protocol_settings.network_settings.path, '/')
  }
  assert.equal(form.grpcServiceName, 'grpc')
})

test('editing WS retains unexposed headers, early-data settings and TLS extensions', () => {
  const protocol_settings = { network: 'ws', network_settings: { path: '/old', headers: { Host: 'old.test', 'X-Custom': 'retained' }, max_early_data: 2048, early_data_header_name: 'Sec-WebSocket-Protocol' }, tls: 1, tls_settings: { server_name: 'node.test', alpn: ['h2'], ech: { enabled: true, config: 'fixture', custom: 'retained' } } }
  const form = toNodeFormModel({ ...node, type: 'vmess', protocol_settings })
  form.wsPath = '/edited'
  form.wsHost = 'new.test'
  const settings = toNodeSavePayload(form).protocol_settings
  assert.deepEqual(settings.network_settings, { ...protocol_settings.network_settings, path: '/edited', headers: { Host: 'new.test', 'X-Custom': 'retained' } })
  assert.deepEqual(settings.tls_settings.alpn, ['h2'])
  assert.equal(settings.tls_settings.ech.custom, 'retained')
  form.network = 'grpc'
  assert.equal(toNodeSavePayload(form).protocol_settings.network_settings.max_early_data, undefined)
  assert.equal(protocol_settings.network_settings.path, '/old')
})

test('MUX missing values default while disabled Brutal omits both bandwidths', () => {
  const form = toNodeFormModel({ ...node, type: 'vmess', protocol_settings: { multiplex: { enabled: true, max_connections: null, brutal: { enabled: false, up_mbps: null, down_mbps: null } } } })
  assert.equal(form.multiplexMaxConnections, 4)
  assert.equal(form.multiplexBrutalUpMbps, 10)
  assert.equal(form.multiplexBrutalDownMbps, 10)
  const settings = toNodeSavePayload(form).protocol_settings.multiplex
  assert.equal(settings.brutal.up_mbps, undefined)
  assert.equal(settings.brutal.down_mbps, undefined)
})

test('Reality and VLESS encryption reject incomplete newly enabled key pairs', () => {
  const form = { ...createEmptyNodeForm(), type: 'vless', network: 'tcp', tlsMode: 2, realityServerName: 'target.test', realityPublicKey: 'fixture-public' }
  assert.ok(validateNodeForm(form)?.includes('私钥'))
  form.realityPrivateKey = 'fixture-private'
  assert.equal(validateNodeForm(form), null)
  form.realityShortId = 'xyz'
  assert.ok(validateNodeForm(form)?.includes('Short ID'))
  form.tlsMode = 0
  form.vlessEncryptionEnabled = true
  assert.ok(validateNodeForm(form)?.includes('配对'))
})
