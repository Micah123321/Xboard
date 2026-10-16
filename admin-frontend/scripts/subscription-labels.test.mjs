import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import ts from 'typescript'
import { parse } from '@vue/compiler-sfc'

async function moduleUrl(path, imports = {}, suffix = '') {
  let source = await readFile(new URL(path, import.meta.url), 'utf8')
  if (path.endsWith('.vue')) source = parse(source).descriptor.scriptSetup.content
  let { outputText } = ts.transpileModule(source + suffix, {
    compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 },
  })
  for (const [specifier, url] of Object.entries(imports)) outputText = outputText.replaceAll("'" + specifier + "'", JSON.stringify(url))
  return 'data:text/javascript;base64,' + Buffer.from(outputText).toString('base64')
}
const dataUrl = (source) => 'data:text/javascript;base64,' + Buffer.from(source).toString('base64')
const optionsUrl = await moduleUrl('../src/utils/nodeEditorOptions.ts')
const { applyNodeProtocolDefaults, getNodeProtocolOptions, validateNodeForm } = await import(optionsUrl)
const { toNodeFormModel, toNodeSavePayload } = await import(await moduleUrl('../src/utils/nodeEditorMapper.ts', { './nodeEditorOptions': optionsUrl }))
const { getNodePlanRestrictionDetail } = await import(await moduleUrl('../src/utils/nodes.ts'))
const { formatSubscriptionPlan, validateSubscriptionLabel } = await import(await moduleUrl('../src/utils/subscriptionLabels.ts'))
const batchUrl = await moduleUrl('../src/utils/nodeBatchEditor.ts')
const { buildNodeBatchEditPayload } = await import(batchUrl)
const plans = [{ id: 2, name: 'Current', show: true }, { id: 7, name: 'Hidden', show: false }]
const label = { id: 4, name: 'Label A', plan_ids: [2, 7], servers_count: 2 }
const node = { id: 17, name: 'Node', type: 'http', host: 'node.test', port: 443, server_port: 8443, group_ids: [3], plan_restriction_enabled: true, allowed_plan_ids: [99], subscription_label_id: 4 }

test('every protocol preserves the manual whitelist while assigning, switching and clearing a label', () => {
  for (const { value: type } of getNodeProtocolOptions()) {
    const input = { ...node, type }
    const form = toNodeFormModel(input)
    applyNodeProtocolDefaults(form)
    assert.equal(form.subscriptionLabelId, 4)
    assert.equal(validateNodeForm(form, plans), null)
    for (const id of [4, 6, null]) {
      form.subscriptionLabelId = id
      const payload = toNodeSavePayload(form)
      assert.equal(payload.subscription_label_id, id)
      assert.equal(payload.plan_restriction_enabled, true)
      assert.deepEqual(payload.allowed_plan_ids, [99])
      assert.deepEqual(payload.group_ids, ['3'])
      assert.equal(toNodeFormModel({ ...input, ...payload }).subscriptionLabelId, id)
    }
    assert.ok(validateNodeForm(form, plans), 'clearing restores manual whitelist validation')
    assert.equal(input.subscription_label_id, 4)
  }
})

test('absent binding is omitted and the backend relation can supply the label', () => {
  const legacy = { ...node }
  delete legacy.subscription_label_id
  assert.equal(Object.hasOwn(toNodeSavePayload(toNodeFormModel(legacy)), 'subscription_label_id'), false)
  assert.equal(toNodeFormModel({ ...legacy, subscription_label: label }).subscriptionLabelId, 4)
  assert.equal(toNodeFormModel({ ...node, subscription_label_id: null, subscription_label: label }).subscriptionLabelId, null)
})

test('node details prioritize labels and show hidden and removed plans', () => {
  const detail = getNodePlanRestrictionDetail({ ...node, plan_restriction_enabled: false }, plans, [label])
  assert.equal(detail.enabled, true)
  assert.ok(detail.label.includes(label.name))
  assert.ok(detail.planNames[1].includes('隐藏'))
  assert.ok(!detail.label.includes('沿用权限组'))
  const deleted = getNodePlanRestrictionDetail(node, plans, [{ ...label, plan_ids: [99] }])
  assert.ok(deleted.planNames[0].includes('已删除'))
  assert.ok(deleted.label.includes('无人可用'))
  assert.equal(deleted.tagType, 'danger')
})

test('missing labels never fall back to manual rules or stale relations', () => {
  const missing = getNodePlanRestrictionDetail({ ...node, subscription_label: label }, plans, [])
  assert.ok(missing.label.includes('无人可用'))
  assert.ok(!missing.label.includes('沿用权限组'))
  assert.equal(missing.tagType, 'danger')
  const relation = getNodePlanRestrictionDetail({ ...node, subscription_label: label }, plans)
  assert.ok(relation.label.includes(label.name))
  const cleared = getNodePlanRestrictionDetail({ ...node, subscription_label_id: null, plan_restriction_enabled: false }, plans, [label])
  assert.equal(cleared.enabled, false)
  assert.equal(cleared.label, '沿用权限组')
})

test('load errors describe unavailable data without calling it deleted', () => {
  const failed = getNodePlanRestrictionDetail(node, [], undefined, { labels: 'failed', plans: 'failed' })
  assert.equal(failed.tagType, 'warning')
  assert.ok(failed.label.includes('信息待重试'))
  const planError = getNodePlanRestrictionDetail(node, [], [label], { plans: 'failed' })
  assert.ok(!planError.label.includes('无人可用'))
  assert.ok(planError.planNames.every((name) => name.includes('信息待重试')))
  assert.ok(formatSubscriptionPlan(99, [], 'failed').includes('信息待重试'))
})

test('label editing accepts hidden plans and requires valid selections', () => {
  assert.equal(validateSubscriptionLabel({ name: 'A', plan_ids: [7] }, plans), null)
  for (const value of [{ name: ' ', plan_ids: [2] }, { name: 'A', plan_ids: [] }, { name: 'A', plan_ids: [99] }, { name: 'A', plan_ids: [2, 99] }]) {
    assert.ok(validateSubscriptionLabel(value, plans))
  }
  assert.ok(formatSubscriptionPlan(7, plans).includes('隐藏'))
  assert.ok(formatSubscriptionPlan(99, plans).includes('已删除'))
})

const baseForm = { updateHost: false, host: '', updateRate: false, rate: 1, updateGroups: false, groupIds: [], updateAutoOnline: false, autoOnline: true, updateGfwCheck: false, gfwCheckEnabled: true, updateBandwidthCheck: false, bandwidthCheckEnabled: false, updateSubscriptionLabel: false, subscriptionLabelId: 4, labelAction: 'assign' }
test('batch omits disabled labels, assigns numeric IDs and clears with explicit null', () => {
  for (const labelAction of ['assign', 'clear']) {
    assert.equal(Object.hasOwn(buildNodeBatchEditPayload({ ...baseForm, labelAction }), 'subscription_label_id'), false)
  }
  assert.deepEqual(buildNodeBatchEditPayload({ ...baseForm, updateSubscriptionLabel: true }), { subscription_label_id: 4 })
  assert.deepEqual(buildNodeBatchEditPayload({ ...baseForm, updateSubscriptionLabel: true, labelAction: 'clear' }), { subscription_label_id: null })
  assert.deepEqual(buildNodeBatchEditPayload({ ...baseForm, updateSubscriptionLabel: true, updateHost: true, host: ' new.test ', updateGroups: true, groupIds: [3, 3], updateRate: true, rate: 2, updateAutoOnline: true, autoOnline: false, updateGfwCheck: true, updateBandwidthCheck: true }), { host: 'new.test', group_ids: ['3'], rate: 2, auto_online: false, gfw_check_enabled: true, bandwidth_check_enabled: false, subscription_label_id: 4 })
})

const bridgeUrl = dataUrl(
  'export const props = ' + JSON.stringify({ visible: false, groups: [], selectedCount: 2, labels: [label], plans }) + ';' +
  'export const emitted = []; export const warnings = []; export const ElMessage = { warning: (message) => warnings.push(message) };' +
  'export const defineProps = () => props; export const defineEmits = () => (...args) => emitted.push(args);'
)
const bridge = await import(bridgeUrl)
const dialog = await import(await moduleUrl('../src/views/nodes/NodeBatchEditDialog.vue', {
  vue: import.meta.resolve('vue'), 'element-plus': bridgeUrl, '@/utils/nodeBatchEditor': batchUrl,
}, ';import { defineProps, defineEmits } from ' + JSON.stringify(bridgeUrl) + ';export { form, handleSubmit, resetForm };'))

test('batch dialog blocks incomplete assignment and load failures while preserving selection', () => {
  dialog.resetForm()
  dialog.form.updateSubscriptionLabel = true
  dialog.handleSubmit()
  assert.equal(bridge.emitted.length, 0)
  dialog.form.subscriptionLabelId = 4
  bridge.props.labelLoadError = 'failed'
  dialog.handleSubmit()
  assert.equal(bridge.emitted.length, 0)
  assert.equal(dialog.form.subscriptionLabelId, 4)
  bridge.props.labelLoadError = ''
  bridge.props.planLoadError = 'failed'
  dialog.handleSubmit()
  assert.equal(bridge.emitted.length, 0)
  bridge.props.planLoadError = ''
  dialog.handleSubmit()
  assert.deepEqual(bridge.emitted.at(-1), ['submit', { subscription_label_id: 4 }])
  bridge.props.labelLoadError = 'failed'
  dialog.form.labelAction = 'clear'
  dialog.handleSubmit()
  assert.deepEqual(bridge.emitted.at(-1), ['submit', { subscription_label_id: null }])
  dialog.form.updateSubscriptionLabel = false
  dialog.form.updateHost = true
  dialog.form.host = 'new.test'
  dialog.handleSubmit()
  assert.deepEqual(bridge.emitted.at(-1), ['submit', { host: 'new.test' }])
})

const clientUrl = dataUrl('export const calls = []; export const adminClient = { get: async (url, options) => { calls.push({url, options}); return {data:{data:[]}}; }, post: async (url, payload) => { calls.push({url, payload: JSON.parse(JSON.stringify(payload))}); return {data:{data:true}}; } };')
const { calls } = await import(clientUrl)
const api = await import(await moduleUrl('../src/api/admin.ts', { './client': clientUrl }))
test('fixed label endpoints and sparse batch transport keep null distinct from omission', async () => {
  await api.fetchSubscriptionLabels()
  assert.equal(calls.at(-1).url, '/subscription-label/fetch')
  const payload = { name: 'Label', plan_ids: [2, 7] }
  await api.saveSubscriptionLabel(payload)
  assert.deepEqual(calls.at(-1), { url: '/subscription-label/save', payload })
  await api.deleteSubscriptionLabel(4)
  assert.deepEqual(calls.at(-1), { url: '/subscription-label/drop', payload: { id: 4 } })
  for (const fields of [{ host: 'new.test' }, { subscription_label_id: 4 }, { subscription_label_id: null }]) {
    await api.batchUpdateNodes({ ids: [17, 18], ...fields })
    assert.deepEqual(calls.at(-1), { url: '/server/manage/batchUpdate', payload: { ids: [17, 18], ...fields } })
    assert.equal(Object.hasOwn(calls.at(-1).payload, 'allowed_plan_ids'), false)
  }
})
