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
const { createEmptyNodeForm, getNodeProtocolOptions, validateNodeForm } = await import(optionsUrl)
const { toNodeFormModel, toNodeSavePayload } = await import(await moduleUrl('../src/utils/nodeEditorMapper.ts', { './nodeEditorOptions': optionsUrl }))
const { getNodePlanRestrictionDetail } = await import(await moduleUrl('../src/utils/nodes.ts'))

const plans = [
  { id: 2, name: 'Current plan', show: true },
  { id: 7, name: 'Legacy hidden plan', show: false },
]
const node = {
  id: 17, name: 'Restricted node', type: 'http', host: 'node.test', port: 443,
  server_port: 8443, show: true, group_ids: ['3'],
  plan_restriction_enabled: true, allowed_plan_ids: [2, 7],
}

test('new and legacy nodes default to group permissions and an empty plan list', () => {
  const legacy = { ...node }
  delete legacy.plan_restriction_enabled
  delete legacy.allowed_plan_ids
  for (const form of [createEmptyNodeForm(), toNodeFormModel(legacy)]) {
    assert.equal(form.planRestrictionEnabled, false)
    assert.deepEqual(form.allowedPlanIds, [])
    const payload = toNodeSavePayload(form)
    assert.equal(payload.plan_restriction_enabled, false)
    assert.deepEqual(payload.allowed_plan_ids, [])
  }
})

test('disabling and re-enabling preserves plans and the original permission groups', () => {
  const form = toNodeFormModel(node)
  form.planRestrictionEnabled = false
  assert.equal(validateNodeForm(form, []), null)
  const disabled = toNodeSavePayload(form)
  assert.equal(disabled.plan_restriction_enabled, false)
  assert.deepEqual(disabled.allowed_plan_ids, [2, 7])
  assert.deepEqual(disabled.group_ids, ['3'])
  const reloaded = toNodeFormModel({ ...node, ...disabled })
  reloaded.planRestrictionEnabled = true
  assert.equal(validateNodeForm(reloaded, plans), null)
  assert.deepEqual(toNodeSavePayload(reloaded).allowed_plan_ids, [2, 7])
  assert.deepEqual(node.allowed_plan_ids, [2, 7])
})

test('enabled restrictions require existing integer plan IDs, including hidden plans', () => {
  const form = toNodeFormModel(node)
  for (const allowedPlanIds of [[], [99], [2, 99], [2.5], [NaN], [0], [-1]]) {
    assert.ok(validateNodeForm({ ...form, allowedPlanIds }, plans))
  }
  assert.ok(validateNodeForm(form, []))
  assert.equal(validateNodeForm({ ...form, allowedPlanIds: [7] }, plans), null)
  assert.equal(validateNodeForm({ ...form, planRestrictionEnabled: false, allowedPlanIds: [] }, plans), null)
})

test('all protocols round-trip plan restrictions independently of protocol constraints', () => {
  for (const { value: type } of getNodeProtocolOptions()) {
    for (const plan_restriction_enabled of [false, true]) {
      const input = { ...node, type, plan_restriction_enabled, protocol_settings: { custom: 'preserved' } }
      const form = toNodeFormModel(input)
      const payload = toNodeSavePayload(form)
      assert.equal(payload.plan_restriction_enabled, plan_restriction_enabled, type)
      assert.deepEqual(payload.allowed_plan_ids, [2, 7], type)
      assert.deepEqual(payload.group_ids, ['3'], type)
      const reloaded = toNodeFormModel({ ...input, ...payload })
      assert.deepEqual(toNodeSavePayload(reloaded), payload, type)
    }
  }
})

test('mapping uses independent numeric arrays without mutating fetched node data', () => {
  const input = { ...node, allowed_plan_ids: ['2', 7, 2] }
  const form = toNodeFormModel(input)
  const payload = toNodeSavePayload(form)
  assert.deepEqual(payload.allowed_plan_ids, [2, 7])
  assert.ok(payload.allowed_plan_ids.every((id) => typeof id === 'number'))
  form.allowedPlanIds.push(99)
  assert.deepEqual(payload.allowed_plan_ids, [2, 7])
  assert.deepEqual(input.allowed_plan_ids, ['2', 7, 2])
})

test('list details distinguish group access, restricted access, empty and deleted plans', () => {
  const detail = getNodePlanRestrictionDetail(node, plans)
  assert.equal(detail.enabled, true)
  assert.match(detail.label, /2/)
  assert.ok(detail.tooltip.includes(plans[0].name))
  assert.ok(detail.tooltip.includes(plans[1].name))
  const disabled = getNodePlanRestrictionDetail({ ...node, plan_restriction_enabled: false }, plans)
  assert.equal(disabled.enabled, false)
  assert.equal(disabled.label, '沿用权限组')
  const empty = getNodePlanRestrictionDetail({ ...node, allowed_plan_ids: [] }, plans)
  assert.equal(empty.tagType, 'danger')
  assert.ok(empty.label.includes('无人可用'))
  const deleted = getNodePlanRestrictionDetail({ ...node, allowed_plan_ids: [99] }, plans)
  assert.ok(deleted.tooltip.includes('#99'))
  assert.ok(deleted.tooltip.includes('已删除'))
})

const clientUrl = 'data:text/javascript;base64,' + Buffer.from(`
  export const calls = [];
  export const adminClient = {
    get: async (url, options) => {
      calls.push({ url, options });
      return { data: { data: ${JSON.stringify(plans)} } };
    },
    post: async (url, payload) => {
      calls.push({ url, payload });
      return { data: { data: true } };
    },
  };
`).toString('base64')
const { calls } = await import(clientUrl)
const { getPlans, saveNode, updateNode, batchUpdateNodes, copyNode } = await import(await moduleUrl('../src/api/admin.ts', { './client': clientUrl }))

test('the admin plan API preserves hidden plans without visibility filters', async () => {
  const response = await getPlans()
  assert.deepEqual(response.data, plans)
  assert.deepEqual(calls.at(-1), { url: '/plan/fetch', options: { params: undefined } })
})

test('save sends explicit restriction fields while quick update and copy keep sparse requests', async () => {
  for (const enabled of [false, true]) {
    const form = toNodeFormModel({ ...node, plan_restriction_enabled: enabled })
    const payload = toNodeSavePayload(form)
    await saveNode(payload)
    assert.equal(calls.at(-1).url, '/server/manage/save')
    assert.equal(calls.at(-1).payload.plan_restriction_enabled, enabled)
    assert.deepEqual(calls.at(-1).payload.allowed_plan_ids, [2, 7])
  }
  for (const payload of [{ id: 17, show: 0 }, { id: 17, auto_online: true }, { id: 17, gfw_check_enabled: false }]) {
    await updateNode(payload)
    assert.deepEqual(calls.at(-1), { url: '/server/manage/update', payload })
    assert.equal(Object.hasOwn(calls.at(-1).payload, 'plan_restriction_enabled'), false)
    assert.equal(Object.hasOwn(calls.at(-1).payload, 'allowed_plan_ids'), false)
  }
  await batchUpdateNodes({ ids: [17], group_ids: ['3'] })
  assert.deepEqual(calls.at(-1), { url: '/server/manage/batchUpdate', payload: { ids: [17], group_ids: ['3'] } })
  await copyNode(17)
  assert.deepEqual(calls.at(-1), { url: '/server/manage/copy', payload: { id: 17 } })
})
