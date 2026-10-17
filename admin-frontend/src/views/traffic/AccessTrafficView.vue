<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage } from 'element-plus'
import { exportAccessTraffic, fetchAccessTraffic, getAccessTrafficConfig, saveAccessTrafficConfig } from '@/api/admin'
import type { AccessTrafficCategory, AccessTrafficConfidence, AccessTrafficConfig, AccessTrafficFilters, AccessTrafficResult } from '@/types/api'
import { formatTraffic } from '@/utils/dashboard'

const route = useRoute()
const categories: Record<AccessTrafficCategory, string> = { video: '视频服务', web: '网页服务', download: '下载', update: '更新', sync: '同步', storage: '云存储', cdn: '通用 CDN', unknown: '未知' }
const confidences: Record<AccessTrafficConfidence, string> = { high: '高', medium: '中', low: '低', unknown: '未知' }
const coverageLabels = { complete: '已采集报告完整', partial: '部分采集', none: '暂无采集' }
const sourceLabels = { request: '请求域名', sniff: '嗅探域名', ip: '仅 IP', unknown: '未知' }
const draft = reactive({ user: '', node: '', search: '', category: '' as AccessTrafficCategory | '', network: '' as NonNullable<AccessTrafficFilters['network']> | '', range: [] as string[] })
const groupBy = ref<AccessTrafficFilters['group_by']>('domain')
const applied = ref<AccessTrafficFilters>({ group_by: 'domain' })
const current = ref(1)
const pageSize = ref(20)
const paginationTotal = ref(0)
const loading = ref(false)
const result = ref<AccessTrafficResult | null>(null)
const errorMessage = ref('')
const exporting = ref<'csv' | 'html' | null>(null)
let requestSequence = 0
let alive = true
const configVisible = ref(false)
const configLoading = ref(false)
const configSaving = ref(false)
const configError = ref('')
const configLoaded = ref(false)
const config = reactive<AccessTrafficConfig>({ enabled: false, bucket_seconds: 300, retention_days: 30, max_entries: 2000, node_ids: [] })
const nodeIds = ref('')
const summary = computed(() => result.value?.summary)
const meta = computed(() => result.value?.meta)
const pendingChanges = computed(() => {
  try { return JSON.stringify(readFilters()) !== JSON.stringify(applied.value) } catch { return true }
})

function positiveId(value: string, label: string): number | undefined {
  if (!value.trim()) return undefined
  const number = Number(value)
  if (!/^\d+$/.test(value.trim()) || !Number.isSafeInteger(number) || number <= 0) throw new Error(label + '须为正整数')
  return number
}

function readFilters(): AccessTrafficFilters {
  const filters: AccessTrafficFilters = {
    user_id: positiveId(draft.user, '用户 ID'), node_id: positiveId(draft.node, '节点 ID'),
    search: draft.search.trim() || undefined, category: draft.category || undefined,
    network: draft.network || undefined, group_by: groupBy.value,
  }
  if (draft.range?.length === 2) {
    const start = Number(draft.range[0])
    const end = Number(draft.range[1])
    if (!Number.isFinite(start) || !Number.isFinite(end) || start >= end) throw new Error('结束时间须晚于开始时间')
    if (meta.value && end - start > meta.value.retention_days * 86400) throw new Error('时间跨度超过保留天数')
    filters.start_time = start
    filters.end_time = end
  }
  return filters
}

async function loadRecords() {
  const sequence = ++requestSequence
  loading.value = true
  result.value = null
  errorMessage.value = ''
  try {
    const response = await fetchAccessTraffic({ ...applied.value, page: current.value, pageSize: pageSize.value })
    // A late response must never replace a newer filter/page result.
    if (alive && sequence === requestSequence) {
      result.value = response
      paginationTotal.value = response.total
    }
  } catch (error) {
    if (alive && sequence === requestSequence) errorMessage.value = error instanceof Error ? error.message : '访问流量加载失败'
  } finally {
    if (alive && sequence === requestSequence) loading.value = false
  }
}

function applyFilters() {
  try {
    applied.value = readFilters()
    current.value = 1
    void loadRecords()
  } catch (error) { ElMessage.error((error as Error).message) }
}

function resetFilters() {
  Object.assign(draft, { user: '', node: '', search: '', category: '', network: '', range: [] })
  applyFilters()
}

function changeGroup(value: string | number | boolean | undefined) {
  groupBy.value = value === 'time' ? 'time' : 'domain'
  applyFilters()
}

function changePage(value: number) { current.value = value; void loadRecords() }
function changePageSize(value: number) { pageSize.value = value; current.value = 1; void loadRecords() }
function billed(value: number | null | undefined) { return value == null ? '未结算 / 未知' : formatTraffic(value) }
function percent(value: number) { return Number.isFinite(value) ? value.toFixed(1) + '%' : '未知' }
function date(value: number | null | undefined) {
  if (value == null) return '暂无'
  try {
    return new Intl.DateTimeFormat('zh-CN', { timeZone: meta.value?.timezone || undefined, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }).format(new Date(value * 1000))
  } catch { return '未知时间' }
}

async function download(format: 'csv' | 'html') {
  exporting.value = format
  try {
    const blob = await exportAccessTraffic({ ...applied.value }, format)
    if (blob.type.includes('json')) {
      const error = JSON.parse(await blob.text()) as { message?: string }
      throw new Error(error.message || '导出失败')
    }
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = 'access-traffic-' + Date.now() + '.' + format
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.setTimeout(() => URL.revokeObjectURL(url), 1000)
    ElMessage.success(format === 'html' ? 'HTML 报告已下载，可在浏览器打开并打印' : 'CSV 已下载')
  } catch (error) { ElMessage.error(error instanceof Error ? error.message : '导出失败') }
  finally { exporting.value = null }
}

async function openConfig() {
  configVisible.value = true
  configLoading.value = true
  configLoaded.value = false
  configError.value = ''
  try {
    const response = await getAccessTrafficConfig()
    Object.assign(config, response)
    nodeIds.value = response.node_ids.join(', ')
    configLoaded.value = true
  } catch (error) { configError.value = error instanceof Error ? error.message : '配置加载失败' }
  finally { configLoading.value = false }
}

async function saveConfig() {
  try {
    for (const key of ['bucket_seconds', 'retention_days', 'max_entries'] as const) {
      if (!Number.isSafeInteger(config[key]) || config[key] <= 0) throw new Error('采集间隔、保留天数和容量须为正整数')
    }
    const ids = nodeIds.value.trim() ? nodeIds.value.split(/[,，\s]+/).filter(Boolean).map(value => positiveId(value, '节点 ID')!) : []
    const payload = { ...config, node_ids: [...new Set(ids)] }
    configSaving.value = true
    await saveAccessTrafficConfig(payload)
    configVisible.value = false
    ElMessage.success('采集配置已保存')
    await loadRecords()
  } catch (error) { ElMessage.error(error instanceof Error ? error.message : '配置保存失败') }
  finally { configSaving.value = false }
}

watch(() => [route.query.user_id, route.query.node_id, route.query.start_time, route.query.end_time], () => {
  const queryString = (value: unknown) => typeof value === 'string' ? value : ''
  draft.user = queryString(route.query.user_id)
  draft.node = queryString(route.query.node_id)
  const start = queryString(route.query.start_time)
  const end = queryString(route.query.end_time)
  draft.range = start && end ? [start, end] : []
  applyFilters()
}, { immediate: true })
onBeforeUnmount(() => { alive = false; requestSequence++ })
</script>

<template>
  <div class="access-traffic-view">
    <header class="page-heading">
      <div><h1>节点访问流量与消耗分析</h1><p>目的地字节记录与历史计费分摊。分类为服务类型推断；具体页面、视频内容和用户意图未被记录。</p></div>
      <ElButton @click="openConfig">采集配置</ElButton>
    </header>

    <ElCard shadow="never" class="filter-card">
      <ElForm label-position="top" @submit.prevent="applyFilters">
        <div class="filter-grid">
          <ElFormItem label="用户 ID"><ElInput v-model="draft.user" placeholder="全部用户" inputmode="numeric" aria-label="用户 ID" clearable /></ElFormItem>
          <ElFormItem label="节点 ID"><ElInput v-model="draft.node" placeholder="全部节点" inputmode="numeric" aria-label="节点 ID" clearable /></ElFormItem>
          <ElFormItem label="域名 / IP 搜索"><ElInput v-model="draft.search" placeholder="搜索目的地" aria-label="域名 / IP 搜索" clearable /></ElFormItem>
          <ElFormItem label="分类（推断）"><ElSelect v-model="draft.category" placeholder="全部分类" aria-label="分类" clearable><ElOption v-for="(label, key) in categories" :key="key" :label="label" :value="key" /></ElSelect></ElFormItem>
          <ElFormItem label="网络"><ElSelect v-model="draft.network" placeholder="全部网络" aria-label="网络" clearable><ElOption label="TCP" value="tcp" /><ElOption label="UDP" value="udp" /><ElOption label="未知" value="unknown" /></ElSelect></ElFormItem>
          <ElFormItem label="采集时间范围（含开始，不含结束）" class="range-filter"><ElDatePicker v-model="draft.range" type="datetimerange" value-format="X" start-placeholder="开始时间" end-placeholder="结束时间" range-separator="至" clearable /></ElFormItem>
        </div>
        <div class="filter-actions"><ElButton type="primary" native-type="submit">应用筛选</ElButton><ElButton @click="resetFilters">重置</ElButton><span>未选时间默认最近 24 小时；日期选择器使用本机时区，结果使用报告时区。</span></div>
      </ElForm>
    </ElCard>

    <ElAlert v-if="errorMessage" :title="errorMessage" type="error" :closable="false" show-icon><ElButton text @click="loadRecords">重试</ElButton></ElAlert>
    <ElAlert v-if="meta" :type="meta.coverage === 'complete' && meta.enabled ? 'info' : 'warning'" :closable="false" show-icon>
      <template #title>{{ meta.enabled ? '采集已启用' : '采集已关闭' }} · {{ coverageLabels[meta.coverage] }}</template>
      <p>{{ meta.reason || '覆盖状态仅描述已收到的报告。' }} 完整报告不代表所有时间段或节点均有采集；缺口不等于零流量。</p>
      <p v-if="meta.collection_gaps">节点级缺口 {{ meta.collection_gaps.node_billing_reports_without_details }} 个计费批次 · 明细待处理 {{ meta.collection_gaps.pending_detail_reports }} 份 · 结算关联异常 {{ meta.collection_gaps.failed_settlement_reports }} 份</p>
      <p v-if="meta.classification_database">服务识别数据库：{{ meta.classification_database.available ? `GeoSite · ${meta.classification_database.rule_count} 条域名规则 · 更新于 ${date(meta.classification_database.updated_at)}` : '内置规则（GeoSite 数据库未就绪）' }}。识别可信度表示服务归属，具体观看、下载行为仍需结合内容证据。</p>
      <p>首次采集 {{ date(meta.first_collected_at) }} · 最近采集 {{ date(meta.last_collected_at) }} · 时区 {{ meta.timezone }} · 分桶 {{ meta.bucket_seconds }} 秒 · 保留 {{ meta.retention_days }} 天</p>
    </ElAlert>

    <div v-if="summary" class="summary-grid">
      <article><span>原始流量</span><strong>{{ formatTraffic(summary.raw_total) }}</strong><small>上行 {{ formatTraffic(summary.raw_u) }} / 下行 {{ formatTraffic(summary.raw_d) }}</small></article>
      <article><span>已分摊计费量</span><strong>{{ billed(summary.billed_total) }}</strong><small>上行 {{ billed(summary.billed_u) }} / 下行 {{ billed(summary.billed_d) }}</small><small>使用历史结算快照；{{ summary.unsettled_report_count }} 份报告未结算</small></article>
      <article><span>已识别分类（推断）</span><strong>{{ percent(summary.identified_percent) }}</strong><small>{{ formatTraffic(summary.identified_bytes) }} 已识别 / {{ formatTraffic(summary.unidentified_bytes) }} 未识别</small></article>
      <article><span>采集与未知</span><strong>{{ summary.report_count }} 份报告</strong><small>{{ summary.partial_report_count }} 份部分采集 · {{ formatTraffic(summary.unattributed_bytes) }} 未归属目的地</small></article>
    </div>

    <ElCard v-if="result?.analysis.length" shadow="never">
      <template #header><div class="section-heading"><h2>消耗构成（依据域名推断）</h2><span>占全部筛选原始流量，不局限当前页</span></div></template>
      <div class="analysis-grid">
        <article v-for="item in result.analysis" :key="item.category" class="analysis-item">
          <div class="section-heading"><strong>{{ categories[item.category] || item.category_label }}（推断）</strong><ElTag size="small" type="info">服务识别可信度 {{ confidences[item.confidence] }}</ElTag></div>
          <p>{{ formatTraffic(item.raw_total) }} · {{ percent(item.share) }}</p>
          <ElProgress :percentage="Math.min(100, Math.max(0, item.share))" :show-text="false" />
          <small>{{ item.reason || '尚无分类依据' }}</small>
          <small v-if="item.category === 'cdn'">通用 CDN 用途未知，不能据此认定在观看视频。</small>
        </article>
      </div>
    </ElCard>

    <ElCard shadow="never" class="records-card" v-loading="loading">
      <template #header>
        <div class="records-toolbar">
          <ElRadioGroup :model-value="groupBy" @change="changeGroup"><ElRadioButton value="domain">域名排行</ElRadioButton><ElRadioButton value="time">时间视图</ElRadioButton></ElRadioGroup>
          <div class="export-actions"><ElButton :disabled="!result || loading || !!exporting || pendingChanges" :loading="exporting === 'csv'" @click="download('csv')">导出 CSV</ElButton><ElButton :disabled="!result || loading || !!exporting || pendingChanges" :loading="exporting === 'html'" @click="download('html')">导出 HTML 报告</ElButton></div>
        </div>
        <p class="muted-copy">汇总覆盖全部筛选结果；导出包含全部匹配行。{{ pendingChanges ? '筛选已修改，请先应用。' : '用户与节点分别分组。' }}</p>
      </template>
      <ElTable :data="result?.data || []" row-key="id" class="access-table">
        <ElTableColumn v-if="applied.group_by === 'time'" label="采集时间" min-width="200"><template #default="{ row }"><div class="cell-stack"><strong>{{ date(row.first_seen) }}</strong><small>至 {{ date(row.last_seen) }}</small></div></template></ElTableColumn>
        <ElTableColumn v-else label="排行" width="64"><template #default="{ $index }">{{ (current - 1) * pageSize + $index + 1 }}</template></ElTableColumn>
        <ElTableColumn label="目的地 / 服务" min-width="220"><template #default="{ row }"><div class="cell-stack"><strong>{{ row.domain || row.ip || '未归属目的地' }}</strong><small>{{ row.service || '未知服务' }} · {{ sourceLabels[row.domain_source as keyof typeof sourceLabels] || '未知来源' }}</small><small>{{ row.ip || row.ip_unavailable_reason || '目标 IP 未采集' }} · 端口 {{ row.port || '未知' }} · {{ row.network }}</small></div></template></ElTableColumn>
        <ElTableColumn label="用户 / 节点" min-width="160"><template #default="{ row }"><div class="cell-stack"><RouterLink :to="{ path: '/users', query: { user_id: row.user_id } }" class="text-link">用户 #{{ row.user_id }}</RouterLink><small v-if="row.user_email">{{ row.user_email }}</small><span>{{ row.node_name || '未知节点' }} #{{ row.node_id }}</span></div></template></ElTableColumn>
        <ElTableColumn label="原始量 / 占比" min-width="180"><template #default="{ row }"><div class="cell-stack"><strong>{{ formatTraffic(row.raw_total) }} · {{ percent(row.share) }}</strong><small>上 {{ formatTraffic(row.raw_u) }} / 下 {{ formatTraffic(row.raw_d) }}</small><ElProgress :percentage="Math.min(100, Math.max(0, row.share))" :show-text="false" /></div></template></ElTableColumn>
        <ElTableColumn label="计费分摊 / 历史倍率" min-width="180"><template #default="{ row }"><div class="cell-stack"><strong>{{ billed(row.billed_total) }}</strong><small>上 {{ billed(row.billed_u) }} / 下 {{ billed(row.billed_d) }}</small><small>{{ row.rate == null ? '倍率混合或未知' : '历史倍率 ×' + row.rate }}</small></div></template></ElTableColumn>
        <ElTableColumn label="分类推断 / 依据" min-width="240"><template #default="{ row }"><div class="cell-stack"><strong>{{ categories[row.category as AccessTrafficCategory] || '未知' }}（推断） · 服务识别可信度 {{ confidences[row.confidence as AccessTrafficConfidence] || '未知' }}</strong><small>{{ row.reason || '尚无判断依据' }}</small></div></template></ElTableColumn>
        <ElTableColumn label="采集 / 连接" min-width="145"><template #default="{ row }"><div class="cell-stack"><span>{{ coverageLabels[row.coverage as keyof typeof coverageLabels] || '未知覆盖' }}</span><small>{{ row.connections }} 次连接</small><small v-if="applied.group_by !== 'time'">最近 {{ date(row.last_seen) }}</small></div></template></ElTableColumn>
        <template #empty><ElEmpty :description="loading ? '正在加载访问流量…' : errorMessage ? '请求失败，请重试' : meta?.coverage === 'none' ? '尚无采集记录，请检查采集配置与节点上报' : '当前筛选下暂无记录'" /></template>
      </ElTable>
      <footer class="pagination"><span>共 {{ result?.total || 0 }} 条分组记录</span><ElPagination :current-page="current" :page-size="pageSize" :page-sizes="[20, 50, 100]" :total="paginationTotal" layout="sizes, prev, pager, next" background :disabled="loading || !!errorMessage" @current-change="changePage" @size-change="changePageSize" /></footer>
    </ElCard>

    <ElDialog v-model="configVisible" title="访问流量采集配置" width="min(560px, calc(100vw - 32px))" :close-on-click-modal="!configSaving" :close-on-press-escape="!configSaving" :show-close="!configSaving">
      <div v-loading="configLoading">
        <ElAlert v-if="configError" :title="configError" type="error" :closable="false"><ElButton text @click="openConfig">重新加载</ElButton></ElAlert>
        <ElForm v-if="configLoaded" label-position="top" @submit.prevent="saveConfig">
          <ElFormItem label="启用采集"><ElSwitch v-model="config.enabled" aria-label="启用采集" :disabled="configSaving" /></ElFormItem>
          <div class="config-grid">
            <ElFormItem label="分桶间隔（秒）"><ElInputNumber v-model="config.bucket_seconds" :min="1" :precision="0" aria-label="分桶间隔" :disabled="configSaving" /></ElFormItem>
            <ElFormItem label="保留天数"><ElInputNumber v-model="config.retention_days" :min="1" :precision="0" aria-label="保留天数" :disabled="configSaving" /></ElFormItem>
            <ElFormItem label="每批最大条目数"><ElInputNumber v-model="config.max_entries" :min="1" :precision="0" aria-label="每批最大条目数" :disabled="configSaving" /></ElFormItem>
          </div>
          <ElFormItem label="节点 ID 白名单"><ElInput v-model="nodeIds" placeholder="留空代表启用时所有节点；多个 ID 用逗号分隔" aria-label="节点 ID 白名单" :disabled="configSaving" /></ElFormItem>
          <p class="muted-copy">关闭后保留已收集历史。采集只记录目的地字节；不会补全关闭期间的访问记录。参数范围由服务端进一步校验。</p>
        </ElForm>
      </div>
      <template #footer><ElButton :disabled="configSaving" @click="configVisible = false">取消</ElButton><ElButton type="primary" :disabled="!configLoaded || configLoading" :loading="configSaving" @click="saveConfig">保存配置</ElButton></template>
    </ElDialog>
  </div>
</template>

<style scoped>
.access-traffic-view { display: grid; gap: 20px; min-width: 0; }
.page-heading, .section-heading, .records-toolbar, .pagination, .filter-actions { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.page-heading h1 { font-size: 26px; letter-spacing: -.03em; }
.page-heading p, .muted-copy, .filter-actions span, small { color: var(--xboard-text-muted); font-size: 12px; line-height: 1.6; }
.page-heading p { margin-top: 8px; max-width: 820px; }
.filter-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 0 16px; }
.range-filter { grid-column: span 3; }
.filter-grid :deep(.el-select), .range-filter :deep(.el-date-editor) { width: 100%; min-width: 0; }
.filter-actions { justify-content: flex-start; flex-wrap: wrap; }
.summary-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; }
.summary-grid article { display: flex; flex-direction: column; gap: 8px; padding: 22px; border: 1px solid var(--xboard-border); border-radius: 20px; background: var(--xboard-surface); }
.summary-grid span { color: var(--xboard-text-secondary); font-size: 13px; }
.summary-grid strong { font-size: 27px; letter-spacing: -.04em; }
.section-heading h2 { font-size: 17px; }
.section-heading > span { font-size: 12px; color: var(--xboard-text-muted); }
.analysis-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 20px; }
.analysis-item { display: grid; gap: 10px; padding: 16px; border-radius: 16px; background: var(--xboard-surface-soft); }
.analysis-item .section-heading { flex-wrap: wrap; }
.records-toolbar { flex-wrap: wrap; }
.export-actions { display: flex; gap: 8px; }
.export-actions :deep(.el-button + .el-button) { margin-left: 0; }
.records-card .muted-copy { margin-top: 10px; }
.cell-stack { display: grid; gap: 5px; padding: 6px 0; overflow-wrap: anywhere; }
.text-link { color: var(--xboard-link); }
.access-table { width: 100%; }
.access-table :deep(th.el-table__cell) { background: var(--xboard-surface-soft); }
.pagination { padding-top: 20px; flex-wrap: wrap; }
.pagination > span { font-size: 12px; color: var(--xboard-text-muted); }
.config-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }
.config-grid :deep(.el-input-number) { width: 100%; }
@media (max-width: 1100px) { .filter-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 640px) {
  .page-heading, .section-heading { align-items: flex-start; flex-direction: column; }
  .page-heading h1 { font-size: 22px; }
  .filter-grid, .summary-grid, .config-grid { grid-template-columns: 1fr; }
  .range-filter { grid-column: span 1; }
  .summary-grid article { padding: 18px; }
  .pagination :deep(.el-pagination) { max-width: 100%; flex-wrap: wrap; gap: 6px; }
  .pagination :deep(.el-pagination__sizes) { margin-right: 0; }
  .export-actions { flex-wrap: wrap; }
}
</style>
