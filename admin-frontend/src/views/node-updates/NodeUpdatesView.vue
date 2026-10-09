<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { updates, UpdateError, createIdempotencyKeys } from '@/api/nodeUpdates'
import type { Settings, Release, ReleaseInput, Installation, Policy, Batch, BatchInput, Task, TaskState, UpdateEvent, Page, Preview, Enrollment, Coverage, Scope } from '@/types/nodeUpdates'
import { batchLabels, taskLabels, reasonLabel, positiveIds } from './model'
import ReleaseEditor from './ReleaseEditor.vue'
import UpdatePager from './UpdatePager.vue'

const emptyPage = <T,>(): Page<T> => ({ items: [], page: 1, page_size: 20, total: 0 })
const tab = ref('installations')
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const settings = ref<Settings>()
const settingsDraft = ref<Settings>()
const settingsDialog = ref(false)
const releases = ref(emptyPage<Release>())
const installations = ref(emptyPage<Installation>())
const batches = ref(emptyPage<Batch>())
const tasks = ref(emptyPage<Task>())
const events = ref(emptyPage<UpdateEvent>())
const pages = reactive({ releases: 1, installations: 1, batches: 1, tasks: 1, events: 1 })
const filters = reactive({ kind: 'machine_id', id: '', capability_status: '' })
const appliedFilters = ref<object>({})
const taskState = ref<TaskState | ''>('')
const selected = ref<string[]>([])
const batch = ref<Batch>()
const task = ref<Task>()
const batchOpen = ref(false)
const taskOpen = ref(false)
const releaseOpen = ref(false)
const policyOpen = ref(false)
const policyInstallation = ref<Installation>()
const policyDraft = ref<Policy>()
const ticketOpen = ref(false)
const ticket = ref<Enrollment>()
const ticketKind = ref<'machine' | 'legacy'>('machine')
const ticketIds = ref('')
const ticketInstanceId = ref('')
const coverageKind = ref<'machine_id' | 'node_id'>('node_id')
const coverageId = ref('')
const coverage = ref<Coverage>()
const plan = reactive<BatchInput>({ release_id: '', installation_ids: [], max_concurrency: 5, fail_pause_after: 1 })
const preview = ref<Preview>()
const frozenPlan = ref<BatchInput>()
const previewOpen = ref(false)
const acknowledged = ref(false)
const resolveOpen = ref(false)
const resolveTask = ref<Task>()
const resolution = reactive<{ state: 'failed' | 'rolled_back' | 'rollback_failed'; reason: string; agent_stopped: boolean }>({ state: 'failed', reason: '', agent_stopped: false })
const keys = createIdempotencyKeys()
const activeReleases = computed(() => releases.value.items.filter(r => !r.revoked_at))
let controller: AbortController | undefined
let timer: ReturnType<typeof setTimeout> | undefined
let disposed = false
let generation = 0
function stopRefresh() { clearTimeout(timer); controller?.abort(); generation++ }
function schedule() { clearTimeout(timer); if (!disposed && !document.hidden) timer = setTimeout(() => void refresh(), 5000) }
async function refresh() {
 if (disposed || document.hidden) return
 controller?.abort()
 const token = ++generation
 controller = new AbortController()
 const signal = controller.signal
 loading.value = true
 try {
  const nextSettings = await updates.settings(signal)
  if (token !== generation) return
  settings.value = nextSettings
  // Fetch only the active lists; forms use separate drafts and are never overwritten by polling.
  if (tab.value === 'releases' || tab.value === 'installations') {
   const result = await updates.releases(pages.releases, signal)
   if (token !== generation) return
   releases.value = result
  }
  if (tab.value === 'installations') {
   const result = await updates.installations(pages.installations, appliedFilters.value, signal)
   if (token !== generation) return
   installations.value = result
  }
  if (tab.value === 'batches') {
   const result = await updates.batches(pages.batches, signal)
   if (token !== generation) return
   batches.value = result
  }
  if (batchOpen.value && batch.value) {
   const detail = await updates.batch(batch.value.id, signal)
   const result = await updates.tasks(detail.id, pages.tasks, taskState.value || undefined, signal)
   if (token !== generation) return
   batch.value = detail; tasks.value = result
   if (task.value) task.value = result.items.find(t => t.id === task.value?.id) || task.value
  }
  if (taskOpen.value && task.value) {
   const result = await updates.events(task.value.id, pages.events, signal)
   if (token !== generation) return
   events.value = result
  }
  error.value = ''
 } catch (failure) {
  if (token === generation && !signal.aborted) error.value = failure instanceof UpdateError ? reasonLabel(failure.code) + '：' + failure.message : '请求失败，请检查连接后重试'
 } finally { if (token === generation) { loading.value = false; schedule() } }
}
function report(failure: unknown) {
 if (failure === 'cancel' || failure === 'close') return
 if (failure instanceof UpdateError) {
  error.value = reasonLabel(failure.code) + '：' + failure.message
  if (failure.excluded.length) error.value += '；' + failure.excluded.map(e => e.installation_id + '：' + reasonLabel(e.code)).join('；')
 } else error.value = failure instanceof Error ? failure.message : '请求失败，请重试'
 ElMessage.error(error.value)
}
async function mutate<T>(operation: string, payload: unknown, action: (key: string) => Promise<T>, done?: (data: T) => void) {
 if (busy.value) return
 busy.value = true
 try {
  const data = await action(keys.get(operation, payload))
  keys.clear(operation, payload)
  if (!disposed) { done?.(data); ElMessage.success('操作成功'); await refresh() }
 } catch (failure) {
  if (disposed) return
  if (failure instanceof UpdateError && failure.code === 'revision_conflict') {
   try {
    if (operation === 'settings') { const latest = await updates.settings(); settings.value = latest; settingsDraft.value = { ...latest } }
    else { policyOpen.value = false; await refresh() }
   } catch { error.value = '配置冲突后刷新失败，请重新加载后再保存'; ElMessage.error(error.value); return }
  }
  report(failure)
 } finally { busy.value = false }
}
async function confirmAction(message: string, action: () => Promise<unknown>) {
 try { await ElMessageBox.confirm(message, '确认节点更新操作', { type: 'warning', confirmButtonText: '确认', cancelButtonText: '取消' }); await action() } catch (failure) { report(failure) }
}
function saveSettings() {
 if (!settingsDraft.value) return
 const data = { ...settingsDraft.value }
 void mutate('settings', data, key => updates.saveSettings(data, key), () => { settingsDialog.value = false })
}
function publish(data: ReleaseInput) { void mutate('publish', data, key => updates.publish(data, key), () => { releaseOpen.value = false }) }
function openPolicy(item: Installation) { policyInstallation.value = item; policyDraft.value = { ...item.policy }; policyOpen.value = true }
function savePolicy() {
 if (!policyDraft.value || !policyInstallation.value) return
 const data = { ...policyDraft.value }; const id = policyInstallation.value.id
 if (data.enabled && !data.target_release_id) { ElMessage.error('启用策略须选择固定目标版本'); return }
 void mutate('policy:' + id, data, key => updates.policy(id, data, key), () => { policyOpen.value = false })
}
function applyFilters() {
 try {
  const ids = filters.id.trim() ? positiveIds(filters.id) : []
  if (ids.length > 1) throw new Error('筛选只接受一个 ID')
  appliedFilters.value = { ...(ids.length ? { [filters.kind]: ids[0] } : {}), ...(filters.capability_status ? { capability_status: filters.capability_status } : {}) }
  pages.installations = 1; void refresh()
 } catch (failure) { report(failure) }
}
function toggleSelection(item: Installation, checked: unknown) {
 selected.value = checked ? [...new Set([...selected.value, item.id])] : selected.value.filter(id => id !== item.id)
 preview.value = undefined
}
function selectable(item: Installation) { return !item.revoked_at && item.capability.status === 'supported' && item.runtime.init === 'systemd' && item.runtime.container === false }
async function generateTicket() {
 try {
  const ids = positiveIds(ticketIds.value)
  if (ticketKind.value === 'machine' && ids.length !== 1) throw new Error('机器范围只接受一个 ID')
  const scope: Scope = ticketKind.value === 'machine' ? { kind: 'machine', machine_id: ids[0]! } : { kind: 'legacy', node_ids: ids }
  await mutate('enroll', scope, key => updates.enroll(scope, key), result => {
   if (!result.enrollment_secret) throw new Error('上次票据已生成，但秘密未被当前页面接收。请重新生成票据；旧票据将在到期后失效。')
   ticket.value = result
  })
 } catch (failure) { report(failure) }
}
function downloadTicket() {
 if (!ticket.value) return
 if (!ticketInstanceId.value.trim()) { ElMessage.error('请输入节点本机的绑定实例 ID'); return }
 const url = URL.createObjectURL(new Blob([JSON.stringify({ ...ticket.value, instance_id: ticketInstanceId.value.trim() }, null, 2)], { type: 'application/json' }))
 const anchor = document.createElement('a'); anchor.href = url; anchor.download = 'mi-node-enrollment.json'; anchor.click(); URL.revokeObjectURL(url)
}
async function checkCoverage() {
 if (busy.value) return
 busy.value = true; coverage.value = undefined
 try { const ids = positiveIds(coverageId.value); if (ids.length !== 1) throw new Error('请输入一个 ID'); coverage.value = await updates.coverage(coverageKind.value === 'machine_id' ? { machine_id: ids[0]! } : { node_id: ids[0]! }) } catch (failure) { report(failure) } finally { busy.value = false }
}
async function previewBatch() {
 if (busy.value) return
 if (!plan.release_id || selected.value.length === 0 || selected.value.length > 1000) { ElMessage.error('请选择固定发行及 1–1000 个安装实例'); return }
 busy.value = true
 try {
  const snapshot = { ...plan, installation_ids: [...new Set(selected.value)] }
  const result = await updates.preview({ release_id: snapshot.release_id, installation_ids: snapshot.installation_ids })
  frozenPlan.value = snapshot; preview.value = result; acknowledged.value = false; previewOpen.value = true
 } catch (failure) { report(failure) } finally { busy.value = false }
}
function createBatch() {
 if (!frozenPlan.value || !preview.value || preview.value.excluded.length || !acknowledged.value) return
 const data = frozenPlan.value
 void mutate('createBatch', data, key => updates.createBatch(data, key), result => { previewOpen.value = false; selected.value = []; tab.value = 'batches'; openBatch(result) })
}
function openBatch(item: Batch) { batch.value = item; batchOpen.value = true; pages.tasks = 1; taskState.value = ''; tasks.value = emptyPage(); void refresh() }
function openTask(item: Task) { task.value = item; taskOpen.value = true; pages.events = 1; events.value = emptyPage(); void refresh() }
function batchAction(item: Batch, action: 'pause' | 'resume' | 'cancel') {
 const labels = { pause: '暂停后保留排队任务；已领取但未安装的任务会取消。', resume: '恢复批次；有结果不确定的任务时需先人工核验。', cancel: '取消排队任务并阻止尚未开始的安装；已经安装中的事务继续验证或回滚，不会被强杀。' }
 void confirmAction(labels[action], () => mutate('batch:' + item.id + ':' + action, {}, key => updates.batchAction(item.id, action, key), result => { if (batch.value?.id === result.id) batch.value = result }))
}
function openResolve(item: Task) { resolveTask.value = item; resolution.state = 'failed'; resolution.reason = ''; resolution.agent_stopped = false; resolveOpen.value = true }
function resolve() {
 if (!resolveTask.value || !resolution.agent_stopped || !resolution.reason.trim()) return
 const id = resolveTask.value.id
 const data = { state: resolution.state, reason: resolution.reason.trim(), agent_stopped: true as const }
 void mutate('resolve:' + id, data, key => updates.resolve(id, data, key), result => { resolveOpen.value = false; if (task.value?.id === result.id) task.value = result })
}
function pageChange(kind: keyof typeof pages, page: number) { pages[kind] = page; void refresh() }
function visibility() { stopRefresh(); if (!document.hidden) void refresh() }
watch(tab, () => void refresh())
watch(ticketOpen, value => { if (!value) { ticket.value = undefined; ticketIds.value = '' } })
onMounted(() => { document.addEventListener('visibilitychange', visibility); void refresh() })
onBeforeUnmount(() => { disposed = true; stopRefresh(); ticket.value = undefined; document.removeEventListener('visibilitychange', visibility) })
</script>

<template>
 <div class="node-updates">
  <div class="toolbar"><div><h2>节点更新</h2><p>以实际安装为升级单元，仅支持 Linux amd64 / arm64 的 systemd 宿主。</p></div><el-button :loading="loading" @click="refresh">刷新</el-button></div>
  <el-alert title="更新会重启同一安装的全部绑定，包括其他面板的绑定。Docker 请更新镜像；OpenRC 请手工更新。" type="warning" show-icon :closable="false" />
  <el-alert v-if="error" :title="error" type="error" :closable="false" />
  <el-card shadow="never"><div class="toolbar"><span>全局自动更新：<el-tag :type="settings?.enabled ? 'success' : 'info'">{{ settings ? (settings.enabled ? '已启用' : '已关闭') : '尚未读取' }}</el-tag> 并发上限 {{ settings?.max_concurrency ?? '—' }} / 修订 {{ settings?.revision ?? '—' }}</span><el-button :disabled="!settings || busy" @click="settingsDraft = settings && { ...settings }; settingsDialog = true">修改全局设置</el-button></div><p>全局与安装策略均启用且目标版本明确才允许更新。缩容、关闭或撤销不会中断已开始安装的本地事务。</p></el-card>
  <el-tabs v-model="tab">
   <el-tab-pane label="安装实例与批次预览" name="installations">
    <div class="toolbar"><el-select v-model="filters.kind" aria-label="筛选范围"><el-option label="机器 ID" value="machine_id" /><el-option label="节点 ID" value="node_id" /></el-select><el-input v-model="filters.id" placeholder="可选 ID" /><el-select v-model="filters.capability_status" placeholder="能力状态" clearable><el-option label="支持" value="supported" /><el-option label="不支持" value="unsupported" /></el-select><el-button @click="applyFilters">查询</el-button><el-button @click="ticketOpen = true">生成登记票据</el-button></div>
    <el-table :data="installations.items" row-key="id">
     <el-table-column label="选择" width="65"><template #default="{ row }"><el-checkbox :model-value="selected.includes(row.id)" :disabled="!selectable(row) || busy" :aria-label="'选择安装 ' + row.id" @change="toggleSelection(row, $event)" /></template></el-table-column>
     <el-table-column label="安装 / 绑定范围" min-width="240"><template #default="{ row }"><code>{{ row.id }}</code><p>{{ row.scope.kind === 'machine' ? '机器 ' + row.scope.machine_id : '节点 ' + row.scope.node_ids.join(', ') }}</p><el-tag v-if="row.revoked_at" type="danger">登记已撤销</el-tag></template></el-table-column>
     <el-table-column label="版本 / 架构 / 散列" min-width="230"><template #default="{ row }"><div>mi-node：{{ row.versions.mi_node ?? '未知' }}</div><div>xbctl：{{ row.versions.xbctl ?? '未知' }}</div><div>{{ row.runtime.os ?? '未知系统' }} / {{ row.runtime.arch ?? '未知架构' }} / {{ row.runtime.init ?? '未报告' }}</div><details><summary>已安装 SHA256</summary><p>mi-node：{{ row.installed_sha256.mi_node ?? '未知' }}</p><p>xbctl：{{ row.installed_sha256.xbctl ?? '未知' }}</p></details></template></el-table-column>
     <el-table-column label="能力 / agent 状态" min-width="220"><template #default="{ row }"><div>{{ reasonLabel(row.capability.reason || row.capability.status) }}</div><div>{{ row.online ? 'agent 最近 180 秒有报告' : 'agent 离线或未报告' }}</div><small>非业务在线状态；最近报告：{{ row.last_seen_at ?? '尚无' }}</small><div v-if="row.active_task_id">在途任务：{{ row.active_task_id }}</div></template></el-table-column>
     <el-table-column label="策略" min-width="180"><template #default="{ row }"><div>{{ row.policy.enabled ? '已启用' : '已关闭' }}</div><code>{{ row.policy.target_release_id ?? '未设目标' }}</code><div>修订 {{ row.policy.revision }}</div></template></el-table-column>
     <el-table-column label="操作" width="155"><template #default="{ row }"><el-button link type="primary" :disabled="!!row.revoked_at || busy" @click="openPolicy(row)">配置策略</el-button><el-button link type="danger" :disabled="!!row.revoked_at || busy" @click="confirmAction('撤销登记将使该 agent 凭据失效；本地恢复继续，未补报事务可能需要人工核验。', () => mutate('revokeInstallation:' + row.id, {}, key => updates.revokeInstallation(row.id, key)))">撤销登记</el-button></template></el-table-column>
    </el-table>
    <UpdatePager :page="pages.installations" :total="installations.total" @change="pageChange('installations', $event)" />
    <el-card shadow="never"><h3>创建更新批次</h3><p>已跨页选择 {{ selected.length }} 个安装（按安装 ID 去重）。实际并发取全局与批次设置的较小值。</p><el-button :disabled="busy" @click="selected = []">清空选择</el-button><el-form label-position="top"><el-form-item label="固定目标发行（当前发行页）"><el-select v-model="plan.release_id" placeholder="选择发行"><el-option v-for="release in activeReleases" :key="release.id" :label="release.version" :value="release.id" /></el-select></el-form-item><UpdatePager :page="pages.releases" :total="releases.total" @change="pageChange('releases', $event)" /><el-form-item label="批次并发"><el-input-number v-model="plan.max_concurrency" :min="1" :max="100" :precision="0" /></el-form-item><el-form-item label="新增失败达到此数量暂停"><el-input-number v-model="plan.fail_pause_after" :min="1" :max="1000" :precision="0" /></el-form-item><el-button type="primary" :loading="busy" :disabled="!settings?.enabled" @click="previewBatch">预览选中实例</el-button></el-form></el-card>
   </el-tab-pane>
   <el-tab-pane label="固定版本发行" name="releases"><el-button type="primary" :disabled="busy" @click="releaseOpen = true">发布版本</el-button><el-table :data="releases.items"><el-table-column type="expand"><template #default="{ row }"><el-table :data="row.artifacts"><el-table-column prop="arch" label="架构" /><el-table-column prop="component" label="组件" /><el-table-column prop="https_url" label="HTTPS URL" min-width="240" /><el-table-column prop="sha256" label="SHA256" min-width="280" /><el-table-column prop="size_bytes" label="字节数" /></el-table></template></el-table-column><el-table-column prop="version" label="版本" /><el-table-column prop="id" label="发行 ID" min-width="260" /><el-table-column prop="min_agent_protocol" label="最低协议" /><el-table-column prop="published_at" label="发布时间" min-width="180" /><el-table-column label="状态"><template #default="{ row }">{{ row.revoked_at ? '已撤销' : '已发布' }}</template></el-table-column><el-table-column label="操作"><template #default="{ row }"><el-button link type="danger" :disabled="!!row.revoked_at || busy" @click="confirmAction('撤销发行将阻止尚未开始的安装，已安装中的事务继续。', () => mutate('revokeRelease:' + row.id, {}, key => updates.revokeRelease(row.id, key)))">撤销</el-button></template></el-table-column></el-table><UpdatePager :page="pages.releases" :total="releases.total" @change="pageChange('releases', $event)" /></el-tab-pane>
   <el-tab-pane label="更新批次" name="batches"><el-table :data="batches.items"><el-table-column prop="id" label="批次 ID" min-width="260" /><el-table-column prop="release_id" label="发行 ID" min-width="260" /><el-table-column label="状态" min-width="200"><template #default="{ row }">{{ batchLabels[row.state as keyof typeof batchLabels] }}</template></el-table-column><el-table-column label="失败 / 不确定" width="150"><template #default="{ row }">{{ row.counts.failed + row.counts.rolled_back + row.counts.rollback_failed }} / {{ row.counts.uncertain }}</template></el-table-column><el-table-column label="操作" width="245"><template #default="{ row }"><el-button link @click="openBatch(row)">任务详情</el-button><el-button v-if="row.state === 'running'" link :disabled="busy" @click="batchAction(row, 'pause')">暂停</el-button><el-button v-if="row.state === 'paused'" link :disabled="busy || row.counts.uncertain > 0" @click="batchAction(row, 'resume')">恢复</el-button><el-button v-if="['running', 'paused'].includes(row.state)" link type="danger" :disabled="busy" @click="batchAction(row, 'cancel')">取消</el-button></template></el-table-column></el-table><UpdatePager :page="pages.batches" :total="batches.total" @change="pageChange('batches', $event)" /></el-tab-pane>
   <el-tab-pane label="旧节点覆盖查询" name="coverage"><el-form inline @submit.prevent="checkCoverage"><el-form-item label="查询范围"><el-select v-model="coverageKind"><el-option value="node_id" label="节点 ID" /><el-option value="machine_id" label="机器 ID" /></el-select></el-form-item><el-form-item label="ID"><el-input v-model="coverageId" /></el-form-item><el-button native-type="submit" :loading="busy">查询登记覆盖</el-button></el-form><template v-if="coverage"><el-alert :title="coverage.status === 'bootstrap_required' ? '需手动升级一次并启用更新agent' : '已有登记，请在安装实例中核对能力与版本'" :type="coverage.status === 'bootstrap_required' ? 'warning' : 'success'" :closable="false" /><p>未登记不代表任何已知版本，页面不提供远程一键引导。</p><p v-for="id in coverage.installation_ids" :key="id">安装：{{ id }}</p></template></el-tab-pane>
  </el-tabs>
  <el-dialog v-model="settingsDialog" title="全局更新设置" width="480px"><el-form v-if="settingsDraft" label-position="top" :disabled="busy"><el-form-item label="启用自动更新"><el-switch v-model="settingsDraft.enabled" /></el-form-item><el-form-item label="最大并发"><el-input-number v-model="settingsDraft.max_concurrency" :min="1" :max="100" :precision="0" /></el-form-item><el-button type="primary" :loading="busy" @click="saveSettings">保存修订 {{ settingsDraft.revision }}</el-button></el-form></el-dialog>
  <el-dialog v-model="releaseOpen" title="发布不可变固定版本" width="min(760px, 95vw)" destroy-on-close><ReleaseEditor :busy="busy" @publish="publish" /></el-dialog>
  <el-dialog v-model="policyOpen" title="安装更新策略" width="min(620px, 95vw)"><template v-if="policyDraft && policyInstallation"><p>{{ policyInstallation.id }}</p><el-alert title="同安装全部绑定（含其他面板）都会重启。目标必须与批次发行一致。" type="warning" :closable="false" /><el-form :disabled="busy" label-position="top"><el-form-item label="启用策略"><el-switch v-model="policyDraft.enabled" :disabled="!selectable(policyInstallation)" /></el-form-item><el-form-item label="固定目标发行"><el-select v-model="policyDraft.target_release_id" clearable @clear="policyDraft.target_release_id = null"><el-option v-for="release in activeReleases" :key="release.id" :label="release.version" :value="release.id" /></el-select><p>当前目标 ID：{{ policyDraft.target_release_id ?? '未设置' }}</p></el-form-item><UpdatePager :page="pages.releases" :total="releases.total" @change="pageChange('releases', $event)" /><el-button type="primary" :loading="busy" @click="savePolicy">保存修订 {{ policyDraft.revision }}</el-button></el-form></template></el-dialog>
  <el-dialog v-model="ticketOpen" title="一次性登记票据" width="min(650px, 95vw)" :close-on-click-modal="false" :before-close="done => { if (!busy) done() }"><el-alert title="有效期 900 秒。秘密仅在本弹窗展示，关闭即清除；下载后在节点以 0600 权限保存，勿放入命令行或日志。" type="warning" :closable="false" /><template v-if="ticket"><p>票据 ID：{{ ticket.enrollment_id }}</p><p>有效至：{{ ticket.expires_at }}</p><el-input :model-value="ticket.enrollment_secret" readonly type="textarea" aria-label="登记票据秘密" /><el-input v-model="ticketInstanceId" placeholder="本机绑定实例 ID（xbctl list 中的 ID）" aria-label="本机绑定实例 ID" maxlength="255" /><el-button :disabled="!ticketInstanceId.trim()" @click="downloadTicket">下载票据 JSON</el-button><p>本机绑定实例 ID 用于从既有配置读取认证信息，与面板机器 ID、节点 ID 不同。仅写入下载文件，不作为后台权限依据。</p></template><el-form v-else label-position="top" :disabled="busy"><el-form-item label="登记范围"><el-radio-group v-model="ticketKind"><el-radio value="machine">单机器</el-radio><el-radio value="legacy">旧节点集合</el-radio></el-radio-group></el-form-item><el-form-item :label="ticketKind === 'machine' ? '机器 ID' : '节点 ID（逗号分隔）'"><el-input v-model="ticketIds" /></el-form-item><el-button type="primary" :loading="busy" @click="generateTicket">生成并显示一次</el-button></el-form></el-dialog>
  <el-dialog v-model="previewOpen" title="核对更新批次" width="min(760px, 95vw)"><template v-if="preview && frozenPlan"><el-alert title="同一安装的全部绑定，包括其他面板绑定，都会重启。" type="warning" :closable="false" /><p>发行 {{ frozenPlan.release_id }}；影响 {{ preview.affected_installation_count }} 个安装；合格 {{ preview.eligible_ids.length }} 个；批次并发 {{ frozenPlan.max_concurrency }}；新增失败 {{ frozenPlan.fail_pause_after }} 次暂停。</p><details><summary>合格安装 ID</summary><p v-for="id in preview.eligible_ids" :key="id">{{ id }}</p></details><el-table :data="preview.excluded"><el-table-column prop="installation_id" label="排除安装" /><el-table-column label="原因"><template #default="{ row }">{{ reasonLabel(row.code) }}</template></el-table-column></el-table><p v-if="preview.excluded.length">存在不合格安装，请关闭预览调整选择后重新预览；不会静默忽略。</p><el-checkbox v-model="acknowledged">已确认全部绑定重启影响</el-checkbox><el-button type="primary" :loading="busy" :disabled="!acknowledged || preview.excluded.length > 0 || preview.eligible_ids.length === 0" @click="createBatch">创建批次（服务端再次校验）</el-button></template></el-dialog>
  <el-drawer v-model="batchOpen" title="批次与任务" size="90%"><template v-if="batch"><p>{{ batch.id }} / {{ batchLabels[batch.state] }}</p><p>并发 {{ batch.max_concurrency }}；新增失败阈值 {{ batch.fail_pause_after }}；创建 {{ batch.created_at }}；结束 {{ batch.finished_at ?? '尚未结束' }}</p><div class="counts"><el-tag v-for="(count, state) in batch.counts" :key="state">{{ taskLabels[state] }}：{{ count }}</el-tag></div><el-select v-model="taskState" clearable placeholder="全部任务状态" @change="pages.tasks = 1; refresh()"><el-option v-for="(label, state) in taskLabels" :key="state" :label="label" :value="state" /></el-select><el-table :data="tasks.items"><el-table-column prop="id" label="任务 ID" min-width="240" /><el-table-column prop="installation_id" label="安装 ID" min-width="240" /><el-table-column label="状态" min-width="200"><template #default="{ row }">{{ taskLabels[row.state as TaskState] }}<p>最后阶段：{{ row.last_phase ? taskLabels[row.last_phase as TaskState] : '—' }}</p></template></el-table-column><el-table-column label="原因" min-width="180"><template #default="{ row }">{{ reasonLabel(row.code) }}<p>{{ row.message }}</p></template></el-table-column><el-table-column label="操作" width="180"><template #default="{ row }"><el-button link @click="openTask(row)">事件详情</el-button><el-button v-if="row.state === 'uncertain'" link type="danger" :disabled="busy" @click="openResolve(row)">人工核验</el-button></template></el-table-column></el-table><UpdatePager :page="pages.tasks" :total="tasks.total" @change="pageChange('tasks', $event)" /></template></el-drawer>
  <el-drawer v-model="taskOpen" title="任务与事件详情" size="min(850px, 95vw)" append-to-body><template v-if="task"><el-descriptions :column="1" border><el-descriptions-item label="任务">{{ task.id }}</el-descriptions-item><el-descriptions-item label="状态">{{ taskLabels[task.state] }}</el-descriptions-item><el-descriptions-item label="尝试 ID">{{ task.attempt_id ?? '未领取' }}</el-descriptions-item><el-descriptions-item label="租约到期">{{ task.lease_expires_at ?? '—' }}</el-descriptions-item><el-descriptions-item label="创建 / 结束">{{ task.created_at }} / {{ task.finished_at ?? '尚未结束' }}</el-descriptions-item><el-descriptions-item label="原因">{{ reasonLabel(task.code) }} {{ task.message }}</el-descriptions-item></el-descriptions><el-alert v-if="task.state === 'uncertain'" title="结果不确定持续占用并发容量。仅超时不构成人工结束依据。" type="warning" :closable="false" /><el-card v-for="event in events.items" :key="event.id" shadow="never"><p>序号 {{ event.seq }} / 来源 {{ event.source === 'agent' ? '更新代理' : event.source === 'admin' ? '管理员' : event.source }} / {{ event.received_at }}</p><pre>{{ JSON.stringify(event.payload, null, 2) }}</pre></el-card><UpdatePager :page="pages.events" :total="events.total" @change="pageChange('events', $event)" /></template></el-drawer>
  <el-dialog v-model="resolveOpen" title="人工核验不确定任务" width="min(650px, 95vw)" append-to-body><el-alert title="必须实地确认 agent 已停止且本地事务已解决。此操作释放占位并记录审计依据，不能仅凭超时处理。" type="warning" :closable="false" /><el-form label-position="top" :disabled="busy"><el-form-item label="确认结果"><el-select v-model="resolution.state"><el-option label="失败（本地事务已解决）" value="failed" /><el-option label="已回滚" value="rolled_back" /><el-option label="回滚失败（已核实现场并解决事务）" value="rollback_failed" /></el-select></el-form-item><el-form-item label="核验依据"><el-input v-model="resolution.reason" type="textarea" :rows="4" maxlength="1024" show-word-limit /></el-form-item><el-checkbox v-model="resolution.agent_stopped">已实地确认 agent 已停止，本地事务已解决</el-checkbox><el-button type="danger" :loading="busy" :disabled="!resolution.agent_stopped || !resolution.reason.trim()" @click="resolve">记录人工核验结果</el-button></el-form></el-dialog>
 </div>
</template>

<style scoped lang="scss">
.node-updates { display: grid; gap: 18px; min-width: 0; }
.toolbar { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; justify-content: space-between; }
.toolbar .el-input, .toolbar .el-select { width: 180px; }
h2, h3 { margin: 0 0 10px; }
p { color: var(--el-text-color-secondary); line-height: 1.6; overflow-wrap: anywhere; }
code, pre { overflow-wrap: anywhere; white-space: pre-wrap; word-break: break-all; }
.el-pagination { margin: 16px 0; overflow-x: auto; }
.el-card, .el-alert { margin-bottom: 12px; }
.el-select { min-width: 180px; }
.counts { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
:deep(.el-dialog__body), :deep(.el-drawer__body) { overflow-wrap: anywhere; }
</style>
