<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import PageHeader from '@/components/PageHeader.vue'
import { getEntrySyncSettings, getEntrySyncStatus, runEntrySync, saveEntrySyncSettings } from '@/api/entrySync'
import type { EntrySyncAccount, EntrySyncNode, EntrySyncRun, EntrySyncSettings, EntrySyncSettingsPayload, EntrySyncStatus } from '@/types/entrySync'

type Action = 'load' | 'save' | 'preview' | 'sync' | 'refresh'
const form = reactive<Omit<EntrySyncSettings, 'accounts' | 'username' | 'password_set'> & { accounts: EntrySyncAccount[] }>({ enabled: false, base_url: 'https://nya.trp.sh', accounts: [], proxy: '', timeout: 15, server_ids: [], ports: [] })
const passwords = reactive<Record<string, string>>({})
const portsText = ref('')
const baseline = ref('')
const loaded = ref(false)
const action = ref<Action | null>(null)
const retryAction = ref<Action>('load')
const errorMessage = ref('')
const pollError = ref('')
const nodes = ref<EntrySyncNode[]>([])
const lastRun = ref<EntrySyncRun | null>(null)
const resultRun = ref<EntrySyncRun | null>(null)
const checkedAt = ref<number | null>(null)
let timer: ReturnType<typeof setTimeout> | undefined
let pollController: AbortController | undefined
let disposed = false
const snapshot = () => JSON.stringify({ ...form, portsText: portsText.value })
const dirty = computed(() => loaded.value && (snapshot() !== baseline.value || Object.values(passwords).some(Boolean)))
const running = computed(() => lastRun.value?.status === 'running')
const busy = computed(() => action.value !== null || running.value)
const result = computed(() => resultRun.value?.result)
const accountHint = computed(() => {
  const enabled = form.accounts.filter((account) => account.enabled)
  if (!enabled.length) return '请至少添加并启用一个 NYA 账号。'
  for (const account of enabled) {
    const name = account.name.trim() || '未命名账号'
    if (!account.username.trim()) return name + '：请填写 NYA 用户名。'
    if (!account.password_set && !passwords[account.id]) return name + '：请填写 NYA 密码。'
  }
  return ''
})
const runHint = computed(() => !loaded.value ? '请先加载设置。' : accountHint.value || (!form.server_ids.length ? '请至少选择一个节点，再预览或同步。' : ''))
const statusLabel = computed(() => ({ running: '运行中', succeeded: '已完成', failed: '执行失败' }[lastRun.value?.status ?? 'succeeded']))

function applySettings(settings: EntrySyncSettings) {
  const accounts = settings.accounts ?? [{ id: 'legacy', name: '账号 1', username: settings.username || 'mxmicah', password_set: settings.password_set ?? false, enabled: true }]
  Object.assign(form, {
    enabled: settings.enabled, base_url: settings.base_url, proxy: settings.proxy, timeout: settings.timeout,
    server_ids: [...settings.server_ids], ports: [...settings.ports],
    accounts: accounts.map((account, index) => ({ id: account.id, name: account.name || '账号 ' + (index + 1), username: account.username, password_set: account.password_set, enabled: account.enabled })),
  })
  portsText.value = settings.ports.join(', ')
  for (const id of Object.keys(passwords)) delete passwords[id]
  baseline.value = snapshot()
}
function addAccount() {
  if (form.accounts.length >= 10) return
  const id = 'account-' + Array.from(crypto.getRandomValues(new Uint8Array(16)), (byte) => byte.toString(16).padStart(2, '0')).join('')
  const number = form.accounts.length + 1
  form.accounts.push({ id, name: '账号 ' + number, username: '', password_set: false, enabled: true })
}
function removeAccount(id: string) {
  form.accounts = form.accounts.filter((account) => account.id !== id)
  delete passwords[id]
}
function applyRun(run: EntrySyncRun | null) {
  lastRun.value = run
  if (run?.result && run.status !== 'failed') resultRun.value = run
}
function applyStatus(status: EntrySyncStatus) {
  applyRun(status.last_run)
  checkedAt.value = status.scheduler.last_checked_at
}
function stopPolling() {
  clearTimeout(timer)
  pollController?.abort()
  pollController = undefined
}
function schedulePoll() {
  clearTimeout(timer)
  if (!disposed && running.value && !action.value) timer = setTimeout(() => void poll(), 2000)
}
async function poll() {
  const controller = new AbortController()
  pollController = controller
  try {
    const status = await getEntrySyncStatus(controller.signal)
    if (!disposed && !controller.signal.aborted) {
      applyStatus(status)
      pollError.value = ''
    }
  } catch {
    if (!disposed && !controller.signal.aborted) pollError.value = '状态刷新失败，正在重试；也可点击刷新状态。'
  } finally {
    if (pollController === controller) {
      pollController = undefined
      schedulePoll()
    }
  }
}
function payload(): EntrySyncSettingsPayload {
  const tokens = portsText.value.trim().split(/[，,;；\s]+/).filter(Boolean)
  if (tokens.some((port) => !/^\d+$/.test(port) || Number(port) < 1 || Number(port) > 65535)) throw new Error('端口须为 1–65535 的整数，使用逗号或空格分隔。')
  let url: URL
  try { url = new URL(form.base_url.trim()) } catch { throw new Error('请填写有效的 NYA 地址。') }
  if (!['http:', 'https:'].includes(url.protocol)) throw new Error('NYA 地址须使用 http 或 https。')
  if (form.accounts.length > 10) throw new Error('最多配置 10 个 NYA 账号。')
  if (form.enabled && runHint.value) throw new Error(runHint.value)
  if (!Number.isInteger(form.timeout) || form.timeout < 1 || form.timeout > 60) throw new Error('请求超时须为 1–60 秒。')
  return {
    enabled: form.enabled, base_url: form.base_url.trim(), proxy: form.proxy.trim(), timeout: form.timeout,
    server_ids: [...form.server_ids], ports: [...new Set(tokens.map(Number))],
    accounts: form.accounts.map((account, index) => ({
      id: account.id, name: account.name.trim() || '账号 ' + (index + 1), username: account.username.trim(), enabled: account.enabled,
      ...(passwords[account.id] ? { password: passwords[account.id] } : {}),
    })),
  }
}
async function persist() {
  const response = await saveEntrySyncSettings(payload())
  applySettings(response.settings)
  nodes.value = response.nodes
  applyStatus(response)
}
async function perform(next: Action) {
  if (action.value || (running.value && next !== 'refresh')) return
  if (next !== 'load' && !loaded.value) return
  if ((next === 'preview' || next === 'sync') && runHint.value) return
  action.value = next
  stopPolling()
  errorMessage.value = ''
  retryAction.value = next
  try {
    if (next === 'load') {
      const response = await getEntrySyncSettings()
      nodes.value = response.nodes
      applySettings(response.settings)
      applyStatus(response)
      loaded.value = true
    } else if (next === 'refresh') {
      applyStatus(await getEntrySyncStatus())
      pollError.value = ''
    } else if (next === 'save') {
      await persist()
      ElMessage.success('设置已保存')
    } else {
      payload()
      if (next === 'sync') {
        await ElMessageBox.confirm('将按当前设置更新所选节点的主入口及随机额外入口 IP。连接端口保持不变，确认立即同步？', '确认同步入口 IP', { type: 'warning', confirmButtonText: dirty.value ? '保存并同步' : '立即同步', cancelButtonText: '取消' })
      }
      if (dirty.value) await persist()
      if (running.value) throw new Error('已有同步任务运行中，请等待完成后重试。')
      applyRun(await runEntrySync(next === 'preview'))
      if (lastRun.value?.status === 'succeeded') {
        if (next === 'sync') {
          try {
            const latest = await getEntrySyncSettings()
            nodes.value = latest.nodes
            checkedAt.value = latest.scheduler.last_checked_at
          } catch {
            pollError.value = '同步已完成，节点地址列表刷新失败；刷新页面可重新读取。'
          }
        }
        ElMessage.success(next === 'preview' ? '预览完成，未修改节点' : '入口 IP 同步完成')
      }
    }
  } catch (error) {
    if (error !== 'cancel' && error !== 'close') {
      errorMessage.value = error instanceof Error ? error.message : '请求失败，请重试或刷新状态。'
      if (next === 'preview' || next === 'sync') {
        retryAction.value = 'refresh'
        try { applyStatus(await getEntrySyncStatus()) } catch { /* Keep the previous result until status can be refreshed. */ }
      }
    }
  } finally {
    action.value = null
    schedulePoll()
  }
}
function time(value: number | null | undefined) {
  return value ? new Date(value * 1000).toLocaleString('zh-CN', { hour12: false }) : '—'
}
function nodeLabel(id: number) {
  return nodes.value.find((node) => node.id === id)?.name ?? '节点 #' + id
}
function entryLabel(field: string) {
  if (field === 'host') return '主入口'
  const index = field.match(/entry_points[.\[]([0-9]+)/)?.[1]
  return index !== undefined ? '额外入口 ' + (Number(index) + 1) : '额外入口 · ' + field
}
onMounted(() => void perform('load'))
onUnmounted(() => { disposed = true; stopPolling() })
</script>

<template>
  <div class="entry-sync-page">
    <PageHeader title="入口 IP 自动同步" :stats="[{ label: '已选节点', value: form.server_ids.length }, { label: '自动同步', value: form.enabled ? '每 5 分钟' : '未启用' }]">
      从 NYA 规则同步所选节点的主入口与随机额外入口 IP，保持连接端口不变。先预览，再确认更新。
    </PageHeader>
    <ElAlert v-if="errorMessage" type="error" :closable="false" show-icon title="请求未完成">
      <p>{{ errorMessage }}</p>
      <ElButton size="small" :disabled="busy" @click="perform(retryAction)">重试</ElButton>
      <ElButton size="small" :disabled="!!action" @click="perform(loaded ? 'refresh' : 'load')">{{ loaded ? '刷新状态' : '重新加载设置' }}</ElButton>
    </ElAlert>
    <section class="sync-panel" aria-labelledby="settings-title" :aria-busy="action === 'load'">
      <header class="section-heading">
        <div><h2 id="settings-title">同步设置</h2><p>配置连接信息与同步范围</p></div>
        <ElTag v-if="dirty" type="warning" effect="plain">有未保存修改</ElTag>
      </header>
      <ElForm label-position="top" :disabled="!loaded || busy" @submit.prevent="perform('save')">
        <div class="schedule-row">
          <div><strong>自动同步</strong><p>启用后，后台每 5 分钟检查并同步一次。</p></div>
          <ElSwitch v-model="form.enabled" aria-label="每 5 分钟自动同步" />
        </div>
        <div class="form-grid">
          <ElFormItem label="NYA 地址"><ElInput v-model="form.base_url" placeholder="https://nya.trp.sh" /></ElFormItem>
          <ElFormItem label="请求超时（秒）"><ElInputNumber v-model="form.timeout" :min="1" :max="60" :precision="0" controls-position="right" /></ElFormItem>
          <ElFormItem label="代理地址（可选）" class="full-width">
            <ElInput v-model="form.proxy" clearable placeholder="例如 http://127.0.0.1:10807" />
            <p class="field-hint">代理须由后端服务器访问；127.0.0.1 指后端服务器本机。留空表示直连。</p>
          </ElFormItem>
          <section class="accounts-section full-width" aria-labelledby="accounts-title">
            <header class="section-heading">
              <div><h2 id="accounts-title">NYA 账号 <span class="field-hint">{{ form.accounts.length }}/10</span></h2><p>地址、代理和超时由所有账号共用；按下列顺序读取规则。</p></div>
              <ElButton :disabled="form.accounts.length >= 10" @click="addAccount">添加账号</ElButton>
            </header>
            <div class="account-grid">
              <article v-for="(account, index) in form.accounts" :key="account.id" class="account-card">
                <header class="account-heading">
                  <strong>{{ account.name || '账号 ' + (index + 1) }}</strong>
                  <div class="account-actions"><ElSwitch v-model="account.enabled" :aria-label="(account.name || '账号') + '启用'" /><ElButton text type="danger" :aria-label="'删除' + (account.name || '账号')" @click="removeAccount(account.id)">删除</ElButton></div>
                </header>
                <ElFormItem label="账号名称"><ElInput v-model="account.name" :placeholder="'账号 ' + (index + 1)" /></ElFormItem>
                <ElFormItem label="用户名"><ElInput v-model="account.username" autocomplete="off" placeholder="请输入 NYA 用户名" /></ElFormItem>
                <ElFormItem label="密码">
                  <ElInput v-model="passwords[account.id]" type="password" show-password autocomplete="new-password" :placeholder="account.password_set ? '已设置，留空保留原密码' : '请输入 NYA 密码'" />
                  <p class="field-hint">{{ account.password_set ? '已保存密码；留空保留，保存成功后清空输入。' : '尚未保存密码，启用此账号运行前请填写。' }}</p>
                </ElFormItem>
              </article>
            </div>
            <p v-if="!form.accounts.length" class="field-hint">尚未添加账号。关闭自动同步时可保存空配置。</p>
            <p class="field-hint">主入口和额外入口按各自连接端口匹配规则；同一连接端口出现在多个账号或规则中时整轮停止并提示冲突。任一账号读取失败，本轮同步失败，保留上次结果。</p>
          </section>
          <ElFormItem label="同步节点" class="full-width">
            <ElSelect v-model="form.server_ids" multiple filterable clearable collapse-tags collapse-tags-tooltip :max-collapse-tags="3" placeholder="搜索并选择节点" no-data-text="暂无可选节点" no-match-text="未找到匹配节点">
              <ElOption v-for="node in nodes" :key="node.id" :value="node.id" :label="'#' + node.id + ' ' + node.name + ' · ' + node.host + ':' + node.port">
                <span>#{{ node.id }} {{ node.name }} · {{ node.host }}:{{ node.port }}</span>
              </ElOption>
            </ElSelect>
            <p class="field-hint">已选 {{ form.server_ids.length }} 个节点，包含各节点的主入口及随机额外入口。</p>
          </ElFormItem>
          <ElFormItem label="端口白名单（可选）" class="full-width">
            <ElInput v-model="portsText" placeholder="例如 443, 8443；使用逗号或空格分隔" />
            <p class="field-hint">留空不限制连接端口；填写后仅同步指定连接端口的主入口和额外入口。</p>
          </ElFormItem>
        </div>
      </ElForm>
      <div class="actions">
        <ElButton :loading="action === 'save'" :disabled="!loaded || busy" @click="perform('save')">保存设置</ElButton>
        <ElButton :loading="action === 'preview'" :disabled="busy || !!runHint" @click="perform('preview')">{{ dirty ? '保存并预览' : '预览变更' }}</ElButton>
        <ElButton type="primary" :loading="action === 'sync'" :disabled="busy || !!runHint" @click="perform('sync')">{{ dirty ? '保存并同步' : '立即同步' }}</ElButton>
      </div>
      <p v-if="runHint || action === 'load'" class="field-hint">{{ action === 'load' ? '正在加载同步设置…' : runHint }}</p>
    </section>
    <section class="sync-panel results-panel" aria-labelledby="result-title">
      <header class="section-heading">
        <div><h2 id="result-title">最近运行</h2><p>最近运行结果保留 7 天，刷新页面后仍可查看。</p></div>
        <ElButton :loading="action === 'refresh'" :disabled="!loaded || !!action" @click="perform('refresh')">刷新状态</ElButton>
      </header>
      <p class="field-hint">调度最近检查：{{ time(checkedAt) }}</p>
      <ElAlert v-if="pollError" :title="pollError" type="warning" show-icon :closable="false" />
      <template v-if="lastRun">
        <div class="run-meta">
          <ElTag :type="lastRun.status === 'failed' ? 'danger' : lastRun.status === 'running' ? 'warning' : 'success'">{{ statusLabel }}</ElTag>
          <strong>{{ lastRun.dry_run ? '预览 · 不修改节点' : '实际同步' }}</strong>
          <span>{{ { manual: '手动触发', schedule: '自动调度', cli: '命令行触发' }[lastRun.source] }}</span>
          <span>开始 {{ time(lastRun.started_at) }}</span><span>结束 {{ time(lastRun.finished_at) }}</span>
        </div>
        <ElAlert v-if="running" title="同步正在运行，状态每 2 秒刷新；请勿重复执行。" type="info" :closable="false" show-icon />
        <ElAlert v-if="lastRun.status === 'failed'" title="执行失败" :description="lastRun.error || '同步未完成，请检查连接设置后重试。'" type="error" :closable="false" show-icon />
      </template>
      <template v-if="result">
        <p v-if="resultRun?.id !== lastRun?.id" class="field-hint">以下保留上一次有结果的记录（{{ time(resultRun?.started_at) }}），供排查参考。</p>
        <div class="metrics">
          <div><span>扫描节点</span><strong>{{ result.servers }}</strong></div>
          <div><span>匹配入口</span><strong>{{ result.matched }}</strong></div>
          <div><span>{{ resultRun?.dry_run ? '预计更新节点' : '已更新节点' }}</span><strong>{{ result.updated }}</strong></div>
          <div><span>未变化</span><strong>{{ result.unchanged }}</strong></div>
          <div><span>已跳过</span><strong>{{ result.skipped }}</strong></div>
        </div>
        <div v-if="result.accounts_checked !== undefined || result.account_summaries?.length" class="account-summary">
          <span v-if="result.accounts_checked !== undefined" class="field-hint">已读取 {{ result.accounts_checked }} 个账号</span>
          <ElTag v-for="account in result.account_summaries" :key="account.id" effect="plain">{{ account.name }} · {{ account.rules }} 条规则</ElTag>
        </div>
        <ElAlert v-if="result.warnings.length" type="warning" title="运行提醒" :closable="false" show-icon>
          <ul class="warnings"><li v-for="(warning, index) in result.warnings" :key="index">{{ warning }}</li></ul>
        </ElAlert>
        <ElTable :data="result.changes" empty-text="本次没有需要变更的入口 IP" class="changes-table">
          <ElTableColumn label="节点" min-width="180"><template #default="{ row }">{{ nodeLabel(row.server_id) }} <span class="field-hint">#{{ row.server_id }}</span></template></ElTableColumn>
          <ElTableColumn label="入口" min-width="150"><template #default="{ row }">{{ entryLabel(row.field) }}</template></ElTableColumn>
          <ElTableColumn prop="port" label="连接端口" width="105" />
          <ElTableColumn prop="from" label="旧 IP" min-width="170" show-overflow-tooltip />
          <ElTableColumn prop="to" label="新 IP" min-width="170" show-overflow-tooltip />
          <ElTableColumn label="来源账号" min-width="140" show-overflow-tooltip><template #default="{ row }">{{ row.account_name || '—' }}</template></ElTableColumn>
          <ElTableColumn prop="rule_id" label="规则 ID" min-width="120" show-overflow-tooltip />
        </ElTable>
      </template>
      <ElEmpty v-else-if="!running" :description="lastRun ? '本次未返回变更明细，可检查设置后重试' : '暂无运行记录，选择节点后可先预览变更'" :image-size="72" />
    </section>
  </div>
</template>

<style scoped lang="scss">
.entry-sync-page { display: grid; gap: 16px; min-width: 0; }
.sync-panel { min-width: 0; padding: 20px; border: 1px solid var(--xboard-border); border-radius: 16px; background: var(--xboard-surface); }
.section-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 18px;
  h2 { margin: 0; font-size: 16px; font-weight: 600; }
  p { margin-top: 5px; color: var(--xboard-text-muted); font-size: 13px; }
}
.schedule-row { display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 14px 16px; margin-bottom: 20px; border-radius: 12px; background: var(--xboard-surface-soft); border: 1px solid var(--xboard-border);
  strong { font-size: 14px; } p { margin-top: 4px; font-size: 13px; color: var(--xboard-text-muted); }
}
.form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); column-gap: 24px; }
.full-width { grid-column: 1 / -1; }
.accounts-section { min-width: 0; margin: 0 0 22px; }
.account-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.account-card { min-width: 0; padding: 16px; border: 1px solid var(--xboard-border); border-radius: 12px; background: var(--xboard-surface-soft); :deep(.el-form-item:last-child) { margin-bottom: 0; } }
.account-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; strong { min-width: 0; overflow-wrap: anywhere; font-size: 14px; } }
.account-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.account-summary { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin: 12px 0; :deep(.el-tag) { max-width: 100%; height: auto; white-space: normal; overflow-wrap: anywhere; } }
.field-hint { font-size: 12px; line-height: 1.6; color: var(--xboard-text-muted); margin-top: 6px; overflow-wrap: anywhere; }
.actions { display: flex; flex-wrap: wrap; gap: 10px; padding-top: 4px; :deep(.el-button + .el-button) { margin-left: 0; } }
.run-meta { display: flex; align-items: center; flex-wrap: wrap; gap: 10px 16px; margin: 16px 0; font-size: 13px; color: var(--xboard-text-secondary); }
.metrics { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; margin: 18px 0;
  div { display: grid; gap: 8px; padding: 14px; border-radius: 12px; background: var(--xboard-surface-soft); border: 1px solid var(--xboard-border); }
  span { color: var(--xboard-text-muted); font-size: 12px; } strong { font-size: 24px; font-weight: 600; font-variant-numeric: tabular-nums; }
}
.warnings { padding-left: 18px; overflow-wrap: anywhere; }
.results-panel :deep(.el-alert) { margin-top: 12px; }
.changes-table { width: 100%; margin-top: 16px; }
:deep(.el-select) { width: 100%; }
@media (max-width: 767px) {
  .sync-panel { padding: 16px; }
  .form-grid, .account-grid { grid-template-columns: minmax(0, 1fr); }
  .metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .section-heading { align-items: flex-start; flex-wrap: wrap; }
  .actions .el-button { flex: 1 1 auto; }
}
</style>
