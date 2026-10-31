<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import PageHeader from '@/components/PageHeader.vue'
import { ActivityConflict, fetchActivities, saveActivity, publishActivity, deleteActivity } from '@/api/activities'
import type { Activity, ActivityInput, ActivitySource, ActivityStatus } from '@/types/activities'

const sources: Record<ActivitySource, string> = { invite: '邀友', ai_credit: 'AI 福利', ai_topup: 'AI 充值', custom: '自定义' }
const states: Record<ActivityStatus, string> = { draft: '草稿', scheduled: '定时', active: '生效', ended: '结束', unavailable: '不可用' }
const rows = ref<Activity[]>([]), total = ref(0), current = ref(1)
const loading = ref(false), busy = ref(false), error = ref(''), formError = ref('')
const opened = ref(false), conflict = ref(false), latest = ref<Activity | null>(null), checking = ref(false)
const starts = ref(''), ends = ref(''), imageFailed = ref(false)
const controller = new AbortController()
let alive = true, requestId = 0
const blank = (): ActivityInput => ({ code: null, source: 'custom', title: '', badge: '', summary: '', benefits: [], image_url: '', target_url: '', button_text: '了解详情', theme: 'navy', sort: 0, published: false, starts_at: null, ends_at: null })
const form = reactive<ActivityInput>(blank())
const message = (e: unknown) => e instanceof Error ? e.message : '请求失败，请重试'
const time = (n: number | null) => n === null ? '不限' : new Date(n * 1000).toLocaleString('zh-CN')
function local(n: number | null) {
  if (n === null) return ''
  const d = new Date(n * 1000), pad = (v: number) => String(v).padStart(2, '0')
  return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds())
}
function seconds(value: string): number | null { return value === '' ? null : Math.floor(new Date(value).getTime() / 1000) }
function validUrl(value: string, image: boolean) {
  if (!value || /[\\\s\u0000-\u001f\u007f]/.test(value)) return false
  let decoded: string
  try { decoded = decodeURIComponent(value) } catch { return false }
  if (/[\\\u0000-\u0020\u007f]/.test(decoded) || decoded.includes('%') || /(^|\/)\.\.?(\/|$)/.test(decoded)) return false
  if (value.startsWith('/')) return value.startsWith(image ? '/activities/' : '/dashboard/') && !value.includes('//') && value === decoded
  try { const u = new URL(value); return u.protocol === 'https:' && !u.username && !u.password } catch { return false }
}
const previewImage = computed(() => {
  if (!validUrl(form.image_url, true)) return ''
  return form.image_url.startsWith('/activities/') ? import.meta.env.BASE_URL + form.image_url.slice(1) : form.image_url
})
const previewText = (value: string) => value.replaceAll('{invite_commission_percent}', '专属返佣比例').replaceAll('{invite_traffic_percent}', '邀友流量奖励比例').replaceAll('{ai_topup_bonus_percent}', '充值赠送比例')
watch(() => form.image_url, () => { imageFailed.value = false })
async function load() {
  const id = ++requestId; loading.value = true; error.value = ''
  try {
    const result = await fetchActivities(current.value, 12, controller.signal)
    if (!alive || id !== requestId) return
    if (!result.records.length && result.total > 0 && current.value > 1) { current.value = Math.ceil(result.total / 12); void load(); return }
    rows.value = result.records; total.value = result.total
  } catch (e) { if (alive && id === requestId) { error.value = message(e); rows.value = [] } }
  finally { if (alive && id === requestId) loading.value = false }
}
function edit(row?: Activity) {
  if (busy.value) return
  Object.keys(form).forEach(key => { delete (form as unknown as Record<string, unknown>)[key] })
  Object.assign(form, row ? { ...row, benefits: [...row.benefits] } : blank())
  starts.value = local(form.starts_at); ends.value = local(form.ends_at)
  formError.value = ''; conflict.value = false; latest.value = null; imageFailed.value = false; opened.value = true
}
function payload(): ActivityInput {
  return { id: form.id, version: form.version, code: form.code?.trim() || null, source: form.source, title: form.title.trim(), badge: form.badge.trim(), summary: form.summary.trim(), benefits: form.benefits.map(b => b.trim()).filter(Boolean), image_url: form.image_url.trim(), target_url: form.target_url.trim(), button_text: form.button_text.trim(), theme: form.theme, sort: form.sort, published: form.published, starts_at: seconds(starts.value), ends_at: seconds(ends.value) }
}
async function save() {
  if (busy.value || conflict.value) return
  formError.value = ''
  const input = payload()
  if (!input.title || !input.button_text) { formError.value = '请填写活动标题和按钮文字'; return }
  if (!validUrl(input.image_url, true) || !validUrl(input.target_url, false)) { formError.value = '请填写有效图片地址和跳转地址：图片使用 HTTPS 或 /activities/，跳转使用 HTTPS 或 /dashboard/ 内部路径'; return }
  if (!Number.isInteger(input.sort) || input.sort < 0 || input.sort > 10000) { formError.value = '排序须为 0 至 10000 的整数'; return }
  if ([input.starts_at, input.ends_at].some(n => n !== null && (!Number.isSafeInteger(n) || n < 0))) { formError.value = '请填写有效的本地时间（不早于 1970 年）'; return }
  if (input.starts_at !== null && input.ends_at !== null && input.ends_at <= input.starts_at) { formError.value = '结束时间必须晚于开始时间'; return }
  const tokens = [input.title, input.badge, input.summary, input.button_text, ...input.benefits].join(' ').match(/{[^{}]+}/g) || []
  if (tokens.some(t => !['{invite_commission_percent}', '{invite_traffic_percent}', '{ai_topup_bonus_percent}'].includes(t))) { formError.value = '包含未知占位符，请使用下方支持的三种占位符'; return }
  busy.value = true
  try { await saveActivity(input, controller.signal); if (!alive) return; opened.value = false; ElMessage.success('活动已保存'); await load() }
  catch (e) { if (alive) { formError.value = message(e); conflict.value = e instanceof ActivityConflict } }
  finally { if (alive) busy.value = false }
}
async function inspectLatest() {
  if (checking.value) return
  checking.value = true; formError.value = ''; latest.value = null
  try {
    // The contract has a paged fetch endpoint, not a single-record endpoint.
    for (let page = 1; alive; page++) {
      const result = await fetchActivities(page, 100, controller.signal)
      if (!alive) return
      const found = result.records.find(row => row.id === form.id)
      if (found) { latest.value = found; return }
      if (page * 100 >= result.total) break
    }
    if (alive) formError.value = '该活动已被删除。当前编辑仍保留，可另存为草稿。'
  } catch (e) { if (alive) formError.value = message(e) }
  finally { if (alive) checking.value = false }
}
function mergeVersion() {
  if (!latest.value) return
  form.version = latest.value.version; form.published = latest.value.published
  conflict.value = false; latest.value = null; formError.value = '已采用最新版本号并保留当前编辑，请核对后再次保存。发布状态沿用服务器最新值。'
}
function copyDraft() { delete form.id; delete form.version; form.code = null; form.published = false; conflict.value = false; latest.value = null; formError.value = '已转为新草稿，保存后再单独发布。' }
async function action(row: Activity, remove = false) {
  if (busy.value) return
  busy.value = true; error.value = ''
  try {
    await ElMessageBox.confirm(remove ? '删除后无法恢复。确认删除“' + row.title + '”？' : row.published ? '下架后将从用户首页隐藏，活动内容仍保留。' : '发布后将按开始、结束时间展示；AI 来源还需对应服务可用。', remove ? '删除活动' : row.published ? '下架活动' : '发布活动', { confirmButtonText: remove ? '确认删除' : row.published ? '确认下架' : '确认发布', cancelButtonText: '取消', type: 'warning' })
    if (!alive) return
    if (remove) await deleteActivity(row, controller.signal)
    else await publishActivity(row, !row.published, controller.signal)
    if (!alive) return
    ElMessage.success(remove ? '活动已删除' : row.published ? '活动已下架' : '活动已发布'); await load()
  } catch (e) {
    if (alive && e !== 'cancel' && e !== 'close') error.value = e instanceof ActivityConflict ? '活动已更新，本次操作未执行。请刷新列表，核对最新状态后重试。' : message(e)
  } finally { if (alive) busy.value = false }
}
function close(done: () => void) { if (!busy.value && !checking.value) done() }
onMounted(load)
onBeforeUnmount(() => { alive = false; ++requestId; controller.abort() })
</script>

<template>
  <div class="activities-page">
    <PageHeader title="活动发布"><template #actions><ElButton :loading="loading" :disabled="busy" @click="load">刷新列表</ElButton><ElButton type="primary" :disabled="busy" @click="edit()">创建活动</ElButton></template>让每一份福利清晰可见 · 管理首页活动与展示时间</PageHeader>
    <div class="activity-overview"><div><span class="eyebrow">首页活动</span><h2>精彩内容，有序发布</h2><p>先完善内容，再发布给用户。定时活动将在指定时间展示。</p></div><div class="activity-count"><strong>{{ total }}</strong><span>个活动</span></div></div>
    <ElAlert v-if="error" :title="error" type="error" :closable="false" />
    <div v-loading="loading" class="activity-list" aria-label="活动列表" :aria-busy="loading">
      <article v-for="row in rows" :key="row.id" class="activity-record">
        <div class="record-heading"><span class="source-label">{{ sources[row.source] }}</span><ElTag :type="row.status === 'active' ? 'success' : row.status === 'unavailable' ? 'danger' : row.status === 'scheduled' ? 'warning' : 'info'">{{ states[row.status] }}</ElTag></div>
        <h3>{{ row.title }}</h3><p class="record-summary">{{ row.summary || '暂无活动说明' }}</p>
        <div class="record-meta"><span>排序 {{ row.sort }} · {{ { navy: '深海蓝', indigo: '靛青', teal: '青绿' }[row.theme] }}</span><span>开始 {{ time(row.starts_at) }}</span><span>结束 {{ time(row.ends_at) }}</span></div>
        <p v-if="row.status === 'unavailable'" class="unavailable-note">对应服务未启用或尚未就绪，用户暂不可见。</p>
        <div class="record-actions"><ElButton :disabled="busy" @click="edit(row)">编辑与预览</ElButton><ElButton :type="row.published ? 'warning' : 'primary'" :disabled="busy" @click="action(row)">{{ row.published ? '下架' : '发布' }}</ElButton><ElButton link type="danger" :disabled="busy" @click="action(row, true)">删除</ElButton></div>
      </article>
      <ElEmpty v-if="!loading && !rows.length && !error" description="还没有活动，创建第一份精彩内容" />
    </div>
    <ElPagination v-if="total" v-model:current-page="current" :disabled="loading || busy" :page-size="12" :total="total" layout="prev, pager, next" :pager-count="5" @current-change="load" />
    <ElDialog v-model="opened" :title="form.id ? '编辑活动' : '创建活动'" width="1100px" class="activity-dialog" top="4vh" :close-on-click-modal="false" :before-close="close" destroy-on-close>
      <div class="activity-editor">
        <ElForm label-position="top" :disabled="busy || checking" @submit.prevent="save">
          <div class="field-pair"><ElFormItem label="活动来源"><ElSelect v-model="form.source" aria-label="活动来源"><ElOption v-for="(label, value) in sources" :key="value" :label="label" :value="value" /></ElSelect></ElFormItem><ElFormItem label="活动标识（可选）"><ElInput v-model="form.code" aria-label="活动标识" maxlength="80" /></ElFormItem></div>
          <ElFormItem label="活动标题"><ElInput v-model="form.title" aria-label="活动标题" maxlength="80" show-word-limit /></ElFormItem>
          <ElFormItem label="角标"><ElInput v-model="form.badge" aria-label="角标" maxlength="30" /></ElFormItem>
          <ElFormItem label="活动说明"><ElInput v-model="form.summary" aria-label="活动说明" type="textarea" :rows="3" maxlength="240" show-word-limit /></ElFormItem>
          <ElFormItem label="活动权益（最多 6 条）"><div class="benefit-fields"><div v-for="(_, index) in form.benefits" :key="index" class="benefit-row"><ElInput v-model="form.benefits[index]" :aria-label="'权益 ' + (index + 1)" maxlength="180" /><ElButton link type="danger" :aria-label="'删除权益 ' + (index + 1)" @click="form.benefits.splice(index, 1)">删除</ElButton></div><ElButton :disabled="form.benefits.length >= 6" @click="form.benefits.push('')">添加权益</ElButton></div></ElFormItem>
          <details class="placeholder-help"><summary>动态权益占位符使用说明</summary><p>邀友：{invite_commission_percent} 为用户实际返佣比例，{invite_traffic_percent} 为邀友流量奖励比例；AI 充值：{ai_topup_bonus_percent} 为当前充值赠送比例。数值后可自行加 %。</p><p>用户页面按实际权益替换；此处预览仅展示比例名称，不虚构数值。支持标题、角标、说明、权益及按钮文字。</p></details>
          <ElFormItem label="图片地址"><ElInput v-model="form.image_url" aria-label="图片地址" maxlength="2048" placeholder="https://… 或 /activities/…" /></ElFormItem>
          <ElFormItem label="跳转地址"><ElInput v-model="form.target_url" aria-label="跳转地址" maxlength="2048" placeholder="/dashboard/finance/invite" /><div class="field-hint">内部页面使用 /dashboard/ 开头的路径，也可填写 HTTPS 地址。</div></ElFormItem>
          <ElFormItem label="按钮文字"><ElInput v-model="form.button_text" aria-label="按钮文字" maxlength="30" /></ElFormItem>
          <div class="field-pair"><ElFormItem label="展示主题"><ElSelect v-model="form.theme" aria-label="展示主题"><ElOption label="深海蓝" value="navy" /><ElOption label="靛青" value="indigo" /><ElOption label="青绿" value="teal" /></ElSelect></ElFormItem><ElFormItem label="排序"><ElInputNumber v-model="form.sort" aria-label="排序" :min="0" :max="10000" :precision="0" /></ElFormItem></div>
          <div class="field-pair"><ElFormItem label="开始时间（本地）"><input v-model="starts" aria-label="开始时间" type="datetime-local" step="1" :disabled="busy || checking" /></ElFormItem><ElFormItem label="结束时间（本地）"><input v-model="ends" aria-label="结束时间" type="datetime-local" step="1" :disabled="busy || checking" /></ElFormItem></div>
          <p class="field-hint">留空代表不限时间；发布后，开始时刻起展示，结束时刻起隐藏。来源服务不可用时自动隐藏。</p>
        </ElForm>
        <aside class="preview-column"><span class="eyebrow">内容预览</span><div class="campaign-preview" :class="form.theme"><span v-if="form.badge" class="preview-badge">{{ previewText(form.badge) }}</span><h2>{{ previewText(form.title) || '活动标题' }}</h2><p>{{ previewText(form.summary) || '在这里介绍活动亮点，让用户发现更多福利。' }}</p><ul v-if="form.benefits.some(Boolean)"><li v-for="(benefit, i) in form.benefits.filter(Boolean)" :key="i">{{ previewText(benefit) }}</li></ul><span class="preview-cta">{{ previewText(form.button_text) || '了解详情' }} <span aria-hidden="true">↗</span></span><div class="preview-art"><img v-if="previewImage && !imageFailed" :src="previewImage" alt="活动配图预览" @error="imageFailed = true" /><div v-else class="image-placeholder"><span aria-hidden="true">◇</span><p>{{ imageFailed ? '图片暂未加载' : '等待添加活动配图' }}</p></div></div></div><p class="field-hint">保存内容后，可返回列表单独发布或下架。新建活动默认保存为草稿。</p></aside>
      </div>
      <ElAlert v-if="formError" :title="formError" type="warning" :closable="false" />
      <div v-if="conflict" class="conflict-panel"><strong>检测到版本冲突，未保存的编辑已保留。</strong><p>先查看服务器最新内容，再选择保留当前编辑合并保存，或另存为草稿。</p><ElButton :loading="checking" @click="inspectLatest">查看最新版本</ElButton><ElButton :disabled="checking" @click="copyDraft">另存为草稿</ElButton><div v-if="latest" class="latest-record"><p>最新版本 {{ latest.version }} · {{ latest.title }} · {{ states[latest.status] }}</p><pre>{{ JSON.stringify(latest, null, 2) }}</pre><ElButton type="primary" @click="mergeVersion">保留当前编辑并采用最新版本</ElButton></div></div>
      <template #footer><span class="draft-note">{{ form.published ? '已发布活动：保存后内容更新' : '草稿：保存不会自动发布' }}</span><ElButton :disabled="busy || checking" @click="opened = false">关闭</ElButton><ElButton type="primary" :loading="busy" :disabled="conflict || checking" @click="save">保存活动</ElButton></template>
    </ElDialog>
  </div>
</template>
<style scoped lang="scss" src="./ActivitiesView.scss"></style>
