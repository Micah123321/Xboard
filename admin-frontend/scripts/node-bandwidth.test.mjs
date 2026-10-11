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
    outputText = outputText.replaceAll(`'${specifier}'`, JSON.stringify(url))
  }
  return 'data:text/javascript;base64,' + Buffer.from(outputText).toString('base64')
}

const optionsUrl = await moduleUrl('../src/utils/nodeEditorOptions.ts')
const { createEmptyNodeForm } = await import(optionsUrl)
const { toNodeFormModel, toNodeSavePayload } = await import(await moduleUrl('../src/utils/nodeEditorMapper.ts', { './nodeEditorOptions': optionsUrl }))
const { getNodeBandwidthDetail, getNodeStatusMeta, getNodeVisibilityDisabledReason } = await import(await moduleUrl('../src/utils/nodes.ts'))
const { createSystemConfigFormState, normalizeSystemConfigMappings, serializeSystemConfigForm, validateBandwidthCheckConfig } = await import(await moduleUrl('../src/utils/systemConfig.ts'))

const node = { id: 1, name: 'test', type: 'vmess', host: 'node.test', port: 443, server_port: 443, show: true, enabled: true, available_status: 2, protocol_settings: { custom: 'preserved' } }
const health = { status: 'healthy', suspended: false, mbps: 16, checked_at: 1700000000, error: null, low_count: 0, success_count: 2 }

test('bandwidth check defaults off and is independent of parent and auto-online', () => {
  assert.equal(createEmptyNodeForm().bandwidthCheckEnabled, false)
  assert.equal(toNodeFormModel(node).bandwidthCheckEnabled, false)
  assert.equal(toNodeSavePayload(createEmptyNodeForm()).bandwidth_check_enabled, false)
  for (const parent_id of [null, 2]) {
    for (const auto_online of [false, true]) {
      for (const bandwidth_check_enabled of [false, true]) {
        const form = toNodeFormModel({ ...node, parent_id, auto_online, bandwidth_check_enabled })
        assert.equal(form.bandwidthCheckEnabled, bandwidth_check_enabled)
        const payload = toNodeSavePayload(form)
        assert.equal(payload.bandwidth_check_enabled, bandwidth_check_enabled)
        assert.equal(payload.auto_online, auto_online)
        assert.equal(payload.protocol_settings.custom, 'preserved')
      }
    }
  }
})

test('schema keeps the two numeric settings and round-trips backend values', () => {
  const defaults = createSystemConfigFormState()
  assert.equal(defaults.server_bandwidth_check_interval, 3600)
  assert.equal(defaults.server_bandwidth_check_threshold_mbps, 8)
  assert.deepEqual(Object.keys(defaults).filter((key) => key.startsWith('server_bandwidth_check_')).sort(), ['server_bandwidth_check_interval', 'server_bandwidth_check_threshold_mbps'])
  assert.equal(validateBandwidthCheckConfig(defaults), null)
  const form = normalizeSystemConfigMappings({ server: { server_bandwidth_check_interval: '7200', server_bandwidth_check_threshold_mbps: '8.25' } })
  const payload = serializeSystemConfigForm(form)
  assert.equal(payload.server_bandwidth_check_interval, 7200)
  assert.equal(payload.server_bandwidth_check_threshold_mbps, 8.25)
  for (const value of [60, 86400]) assert.equal(validateBandwidthCheckConfig({ ...defaults, server_bandwidth_check_interval: value }), null)
  for (const value of [0.1, 100]) assert.equal(validateBandwidthCheckConfig({ ...defaults, server_bandwidth_check_threshold_mbps: value }), null)
  for (const value of [59, 86401, 60.5, NaN, null, '']) assert.ok(validateBandwidthCheckConfig({ ...defaults, server_bandwidth_check_interval: value }))
  for (const value of [0, 100.1, NaN, null, '']) assert.ok(validateBandwidthCheckConfig({ ...defaults, server_bandwidth_check_threshold_mbps: value }))
})

test('low bandwidth stays distinguishable while heartbeat is online', () => {
  const suspect = { ...node, bandwidth_check_enabled: true, bandwidth_health: { ...health, status: 'suspect', mbps: 2, low_count: 1 } }
  assert.equal(getNodeStatusMeta(suspect).dotClass, 'online')
  assert.equal(getNodeBandwidthDetail(suspect).tagType, 'warning')
  assert.equal(getNodeBandwidthDetail(suspect).speed, '2.00 Mbps')
  assert.equal(getNodeBandwidthDetail(suspect).suspended, false)
  assert.equal(getNodeVisibilityDisabledReason(suspect), '')
  assert.equal(getNodeBandwidthDetail({ ...suspect, bandwidth_health: health }).tagType, 'success')
})

test('only enabled nodes are blocked by shared machine health', () => {
  const suspended = { ...node, bandwidth_check_enabled: true, bandwidth_health: { ...health, status: 'suspended', suspended: true, mbps: 1, low_count: 3 } }
  assert.equal(getNodeBandwidthDetail(suspended).suspended, true)
  assert.equal(getNodeBandwidthDetail(suspended).tagType, 'danger')
  assert.ok(getNodeVisibilityDisabledReason(suspended))
  const disabled = { ...suspended, bandwidth_check_enabled: false }
  assert.equal(getNodeBandwidthDetail(disabled).suspended, false)
  assert.equal(getNodeVisibilityDisabledReason(disabled), '')
  assert.ok(getNodeVisibilityDisabledReason({ ...disabled, auto_online: true }))
  assert.ok(getNodeVisibilityDisabledReason({ ...disabled, gfw_auto_hidden: true }))
})

test('missing and failed measurements never become zero Mbps', () => {
  for (const bandwidth_health of [null, { ...health, status: 'unknown', mbps: null }, { ...health, status: 'unknown', mbps: 0, error: 'timeout' }, { ...health, mbps: NaN }, { ...health, mbps: -1 }]) {
    assert.doesNotMatch(getNodeBandwidthDetail({ bandwidth_check_enabled: true, bandwidth_health }).speed, /Mbps/)
  }
  const unknown = getNodeBandwidthDetail({ bandwidth_check_enabled: true, bandwidth_health: { ...health, status: 'unknown', mbps: null, checked_at: null, error: 'timeout' } })
  assert.equal(unknown.tagType, 'warning')
  assert.equal(unknown.error, 'timeout')
  assert.equal(getNodeBandwidthDetail({ bandwidth_check_enabled: true, bandwidth_health: { ...health, status: 'suspect', mbps: 0 } }).speed, '0.00 Mbps')
  assert.notEqual(getNodeBandwidthDetail({ bandwidth_health: health }).checkedAt, unknown.checkedAt)
})
