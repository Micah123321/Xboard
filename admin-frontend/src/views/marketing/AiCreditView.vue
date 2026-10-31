<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import PageHeader from '@/components/PageHeader.vue'
import { getPlans } from '@/api/admin'
import { getAiCreditSettings, saveAiCreditSettings, getAiCreditRecords, reconcileAiCredit, revokeAiCredit, previewAiCredit, backfillAiCredit } from '@/api/aiCredit'
import type { AdminPlanListItem } from '@/types/api'
import type { AiCreditSettings, AiCreditGrant, AiCreditRecords, AiCreditPreview } from '@/types/aiCredit'

const settings = reactive<AiCreditSettings>({ enabled: false, title: 'AI 中转福利', description: '', site_url: '', pricing_url: '', transparency_url: '', rate_usd_per_gb: '0.05', min_paid_cents: 1500, include_balance: false, legacy_enabled: false, starts_at: null, ends_at: null, plan_ids: [], bridge_url: '', bridge_token_set: false, provider_ready: false, version: 0 })
const token = ref('')
const plans = ref<AdminPlanListItem[]>([])
const loaded = ref(false)
const savedLegacyEnabled = ref(false)
const loading = ref(false)
const saving = ref(false)
const settingsError = ref('')
const recordsError = ref('')
const tab = ref('settings')
const ledger = ref<AiCreditRecords | null>(null)
const filters = reactive({ current: 1, page_size: 20, status: '', user_id: '' })
const busy = ref(false)
const limit = ref(100)
const preview = ref<AiCreditPreview | null>(null)
const previewLimit = ref(0)
const backfillResult = ref('')
const detail = ref<AiCreditGrant | null>(null)
const statuses: Record<string, string> = { eligible: '待领取', generating: '生成中', issued: '已发放', redeemed: '已兑换', reconciling: '核对中', revoked: '已停用', failed: '失败' }
const time = (value: number | null) => value == null ? '—' : new Date(value * 1000).toLocaleString('zh-CN')
const amount = (value: string | undefined) => value == null ? '—' : '$' + value
const errorText = (e: unknown) => e instanceof Error ? e.message : '请求失败，请重试'
const stats = computed(() => [
  { label: '可领取总面额', value: amount(ledger.value?.summary.eligible_amount_usd), note: (ledger.value?.summary.eligible_count ?? '—') + ' 笔符合条件' },
  { label: '已发放总面额', value: amount(ledger.value?.summary.issued_amount_usd), note: (ledger.value?.summary.issued_count ?? '—') + ' 笔已发放' },
  { label: '已兑换', value: ledger.value?.summary.redeemed_count ?? '—', note: '以服务端兑换结果为准' },
  { label: '核对中', value: ledger.value?.summary.reconciling_count ?? '—', note: '沿原请求核对发放结果' },
])
const startDate = computed({ get: () => settings.starts_at == null ? null : new Date(settings.starts_at * 1000), set: (v: Date | null) => { settings.starts_at = v ? Math.floor(v.getTime() / 1000) : null } })
const endDate = computed({ get: () => settings.ends_at == null ? null : new Date(settings.ends_at * 1000), set: (v: Date | null) => { settings.ends_at = v ? Math.floor(v.getTime() / 1000) : null } })
watch(limit, () => { preview.value = null })
watch(settings, () => { preview.value = null }, { deep: true })
async function loadSettings() {
  loaded.value = false
  settingsError.value = ''
  try {
    const [config, list] = await Promise.all([getAiCreditSettings(), getPlans()])
    if (list.status && list.status !== 'success') throw new Error(list.message || '套餐加载失败')
    // Copy only declared settings; never retain unexpected secret fields.
    for (const key of Object.keys(settings) as (keyof AiCreditSettings)[]) {
      Object.assign(settings, { [key]: config[key] })
    }
    savedLegacyEnabled.value = config.legacy_enabled
    plans.value = list.data ?? []
    loaded.value = true
  } catch (e) { settingsError.value = errorText(e) }
}
let requestId = 0
async function loadRecords() {
  const id = ++requestId
  loading.value = true
  recordsError.value = ''
  try {
    const result = await getAiCreditRecords({ ...filters })
    if (id === requestId) ledger.value = result
  } catch (e) { if (id === requestId) { recordsError.value = errorText(e); ledger.value = null } }
  finally { if (id === requestId) loading.value = false }
}
async function search() {
  if (filters.user_id && !/^[1-9]\d*$/.test(filters.user_id)) return void ElMessage.warning('用户 ID 必须是正整数')
  filters.current = 1
  await loadRecords()
}
async function save() {
  if (!loaded.value || saving.value) return
  if (!settings.title.trim()) return void ElMessage.warning('请输入活动标题')
  if (!/^\d+(\.\d+)?$/.test(settings.rate_usd_per_gb) || Number(settings.rate_usd_per_gb) <= 0) return void ElMessage.warning('面额比例必须大于 0')
  if (!Number.isSafeInteger(settings.min_paid_cents) || settings.min_paid_cents < 1500) return void ElMessage.warning('实付门槛须至少为 1500 分')
  if (token.value.trim() && !new RegExp('^[A-Za-z0-9._~+/-]{32,}={0,2}$').test(token.value.trim())) return void ElMessage.warning('桥接 Token 须为至少 32 位有效字符')
  if (settings.starts_at != null && settings.ends_at != null && settings.starts_at >= settings.ends_at) return void ElMessage.warning('结束时间应晚于开始时间')
  for (const url of [settings.site_url, settings.pricing_url, settings.transparency_url, settings.bridge_url]) {
    if (!url) continue
    try { if (!['http:', 'https:'].includes(new URL(url).protocol)) throw new Error() } catch { return void ElMessage.warning('链接须为完整的 HTTP 或 HTTPS 地址') }
  }
  saving.value = true
  try {
    await saveAiCreditSettings({ ...settings, plan_ids: [...settings.plan_ids] }, token.value)
    token.value = ''
    preview.value = null
    ElMessage.success('活动配置已保存')
    await loadSettings()
  } catch (e) { ElMessage.error(errorText(e)) } finally { saving.value = false }
}
async function act(row: AiCreditGrant, revoke: boolean) {
  if (busy.value) return
  busy.value = true
  try {
    if (revoke) {
      const result = await ElMessageBox.prompt('停用后此笔福利将不可继续使用。请输入原因；已兑换记录以业务结果为准。', '停用福利 #' + row.id, { inputPlaceholder: '填写停用原因', inputValidator: value => !!value?.trim() || '请输入停用原因', confirmButtonText: '确认停用', cancelButtonText: '取消', type: 'warning' })
      detail.value = await revokeAiCredit(row.id, result.value.trim())
    } else {
      await ElMessageBox.confirm('将沿原发放请求核对结果，不创建新的发放请求。', '核对原请求 #' + row.id, { confirmButtonText: '开始核对', cancelButtonText: '取消' })
      detail.value = await reconcileAiCredit(row.id)
    }
    ElMessage.success(revoke ? '停用操作成功' : '原请求核对完成')
    await loadRecords()
  } catch (e) { if (e !== 'cancel' && e !== 'close') ElMessage.error(errorText(e)) } finally { busy.value = false }
}
async function makePreview() {
  if (!Number.isSafeInteger(limit.value) || limit.value < 1) return void ElMessage.warning('请输入正整数批次上限')
  busy.value = true
  preview.value = null
  backfillResult.value = ''
  try { preview.value = await previewAiCredit(limit.value); previewLimit.value = limit.value } catch (e) { ElMessage.error(errorText(e)) } finally { busy.value = false }
}
async function backfill() {
  if (!preview.value || busy.value || !savedLegacyEnabled.value || !loaded.value) return
  busy.value = true
  try {
    await ElMessageBox.confirm('本次预览符合条件 ' + preview.value.eligible_count + ' 笔，总面额 ' + amount(preview.value.total_amount_usd) + '；执行上限 ' + previewLimit.value + ' 笔。仅为指定日期起的合格订单及当前有效订阅创建记录，同一订单不会重复创建。', '确认执行历史补领', { confirmButtonText: '确认执行补领', cancelButtonText: '取消', type: 'warning' })
    const result = await backfillAiCredit(previewLimit.value)
    preview.value = null
    backfillResult.value = '已创建 ' + result.created + ' 笔，跳过 ' + result.skipped + ' 笔。' + (result.has_more ? '仍有更多记录，请重新预览后继续。' : '本批次处理完毕。')
    ElMessage.success('历史补领执行成功')
    await loadRecords()
  } catch (e) { if (e !== 'cancel' && e !== 'close') { preview.value = null; ElMessage.error(errorText(e)) } } finally { busy.value = false }
}
onMounted(() => { void loadSettings(); void loadRecords() })
</script>

<template>
  <div class="ai-credit-page">
    <PageHeader title="AI 中转福利"><template #actions><ElTag :type="loaded && settings.provider_ready ? 'success' : 'info'">{{ !loaded ? '配置待加载' : settings.provider_ready ? '服务已就绪' : '服务待配置' }}</ElTag></template>让每一份订阅，延伸为 AI 创作额度。统一管理活动规则、发放与历史补领。</PageHeader>
    <section class="credit-stats" aria-label="福利统计"><article v-for="item in stats" :key="item.label"><span>{{ item.label }}</span><strong>{{ item.value }}</strong><small>{{ item.note }}</small></article></section>
    <ElAlert v-if="recordsError" :title="recordsError" type="error" show-icon :closable="false"><ElButton link @click="loadRecords">重试台账统计</ElButton></ElAlert>
    <section class="credit-workspace">
      <ElTabs v-model="tab">
        <ElTabPane label="活动配置" name="settings">
          <ElAlert v-if="settingsError" :title="settingsError" type="error" :closable="false"><ElButton link @click="loadSettings">重新加载配置</ElButton></ElAlert>
          <ElForm label-position="top" :disabled="!loaded || saving" @submit.prevent="save">
            <div class="config-grid">
              <div>
                <div class="section-heading"><div><h3>活动与领取规则</h3><p>按订单流量计算，面额以美元计价</p></div><ElSwitch v-model="settings.enabled" active-text="开启活动" /></div>
                <ElFormItem label="活动标题"><ElInput v-model="settings.title" maxlength="80" /></ElFormItem>
                <ElFormItem label="活动说明"><ElInput v-model="settings.description" type="textarea" :rows="3" /></ElFormItem>
                <div class="field-grid"><ElFormItem label="面额比例（USD / GB）"><ElInput v-model="settings.rate_usd_per_gb" inputmode="decimal" /></ElFormItem><ElFormItem label="实付门槛（分，严格大于）"><ElInputNumber v-model="settings.min_paid_cents" :min="1500" :precision="0" controls-position="right" /></ElFormItem></div>
                <div class="rule-note">仅纳入北京时间 2026-10-20 00:00:00 起（含当天）、按支付时间计算的合格订阅订单；当前有效订阅即使来源订单早于该日期，也可补领一次。同一订单同时符合两项条件仅计一次。实际支付须 &gt; {{ settings.min_paid_cents }} 分（¥{{ (settings.min_paid_cents / 100).toFixed(2) }}）。续费、升档每单仅一次；年付不按月份倍增，升档按全额计算。</div>
                <ElFormItem label="参与套餐"><ElSelect v-model="settings.plan_ids" multiple filterable placeholder="选择参与套餐（仅保存套餐 ID）"><ElOption v-for="plan in plans" :key="plan.id" :value="plan.id" :label="plan.name" /><ElOption v-for="id in settings.plan_ids.filter(id => !plans.some(p => p.id === id))" :key="id" :value="id" :label="'已选套餐 #' + id" /></ElSelect></ElFormItem>
                <div class="field-grid"><ElFormItem label="开始时间（本地时间，可留空）"><ElDatePicker v-model="startDate" type="datetime" placeholder="不限制开始时间" /></ElFormItem><ElFormItem label="结束时间（本地时间，可留空）"><ElDatePicker v-model="endDate" type="datetime" placeholder="不限制结束时间" /></ElFormItem></div>
                <div class="switch-row"><ElSwitch v-model="settings.include_balance" active-text="余额支付计入实付" /><span>默认关闭，仅计算符合条件的实付款项</span></div>
                <div class="switch-row"><ElSwitch v-model="settings.legacy_enabled" active-text="允许历史补领" /><span>仅补齐上述日期起的合格订单及当前有效订阅；不覆盖全部历史订单。保存后生效，执行前先预览。</span></div>
              </div>
              <aside>
                <h3>服务桥接</h3><p class="muted">连接福利提供方，密钥仅在更新时提交。</p>
                <ElFormItem label="桥接服务 URL"><ElInput v-model="settings.bridge_url" placeholder="https://bridge.example.com" /></ElFormItem>
                <ElFormItem label="桥接 Token"><ElInput v-model="token" type="password" autocomplete="new-password" :placeholder="settings.bridge_token_set ? '已设置，留空保留现有密钥' : '输入桥接密钥'" /><span class="field-hint">{{ settings.bridge_token_set ? '现有密钥已保存，不回显。' : '尚未设置密钥。' }}</span></ElFormItem>
                <ElAlert :type="settings.provider_ready ? 'success' : 'warning'" :closable="false" :title="settings.provider_ready ? '提供方已就绪' : '提供方尚未就绪，请保存完整桥接配置'" />
                <h3 class="links-heading">用户侧链接</h3>
                <ElFormItem label="服务站点 URL"><ElInput v-model="settings.site_url" placeholder="https://" /></ElFormItem>
                <ElFormItem label="定价说明 URL"><ElInput v-model="settings.pricing_url" placeholder="https://" /></ElFormItem>
                <ElFormItem label="透明度说明 URL"><ElInput v-model="settings.transparency_url" placeholder="https://" /></ElFormItem>
              </aside>
            </div>
            <div class="save-bar"><span>配置版本 {{ settings.version }} · 所有变更保存后生效</span><ElButton type="primary" :loading="saving" :disabled="!loaded" native-type="submit">保存活动配置</ElButton></div>
          </ElForm>
        </ElTabPane>
        <ElTabPane label="领取台账" name="records">
          <div class="section-heading"><div><h3>每一笔福利，都有迹可循</h3><p>台账不展示兑换码；异常发放请沿原请求核对。</p></div><ElButton :loading="loading" @click="loadRecords">刷新台账</ElButton></div>
          <div class="ledger-filters"><ElSelect v-model="filters.status" aria-label="福利状态" placeholder="全部状态" clearable><ElOption v-for="(label, value) in statuses" :key="value" :label="label" :value="value" /></ElSelect><ElInput v-model="filters.user_id" aria-label="用户 ID" placeholder="用户 ID" clearable @keyup.enter="search" /><ElButton type="primary" @click="search">查询</ElButton></div>
          <ElTable v-loading="loading" :data="ledger?.records || []" empty-text="暂无领取记录" stripe>
            <ElTableColumn label="用户 / 订单" min-width="230"><template #default="{ row }"><strong>{{ row.email || '用户 #' + row.user_id }}</strong><div class="muted">#{{ row.user_id }} · {{ row.trade_no }}</div></template></ElTableColumn>
            <ElTableColumn label="套餐 / 流量" min-width="160"><template #default="{ row }">{{ row.plan_name }}<div class="muted">{{ row.traffic_gb }} GB · 实付 ¥{{ (row.paid_amount_cents / 100).toFixed(2) }}</div></template></ElTableColumn>
            <ElTableColumn label="面额" min-width="100"><template #default="{ row }"><b class="credit-amount">{{ amount(row.amount_usd) }}</b></template></ElTableColumn>
            <ElTableColumn label="状态 / 来源" min-width="130"><template #default="{ row }"><ElTag :type="row.status === 'redeemed' ? 'success' : row.status === 'failed' ? 'danger' : 'info'">{{ statuses[row.status] || row.status }}</ElTag><div class="muted">{{ row.source === 'legacy' ? '有效订阅补领' : '订单奖励' }}</div></template></ElTableColumn>
            <ElTableColumn label="创建时间" min-width="175"><template #default="{ row }">{{ time(row.created_at) }}</template></ElTableColumn>
            <ElTableColumn label="操作" min-width="210"><template #default="{ row }"><ElButton link type="primary" @click="detail = row">详情</ElButton><ElButton link type="primary" :disabled="busy" @click="act(row, false)">核对原请求</ElButton><ElButton link type="danger" :disabled="busy || row.status === 'revoked'" @click="act(row, true)">停用</ElButton></template></ElTableColumn>
          </ElTable>
          <div class="pager"><ElPagination v-model:current-page="filters.current" :page-size="filters.page_size" :total="ledger?.total || 0" layout="prev, pager, next" @current-change="loadRecords" /><span>共 {{ ledger?.total || 0 }} 笔</span></div>
        </ElTabPane>
        <ElTabPane label="历史补领" name="legacy">
          <div class="section-heading"><div><h3>为符合范围的订单与有效订阅补齐福利</h3><p>仅纳入北京时间 2026-10-20 00:00:00 起（含当天）支付的合格订阅订单，以及来源订单早于该日期的当前有效订阅。同一订单仅计一次，实付须大于门槛；预览只读。</p></div></div>
          <ElAlert title="以已保存的活动规则进行计算；修改规则后请重新预览。" type="info" :closable="false" />
          <div class="legacy-actions"><ElInputNumber v-model="limit" aria-label="补领批次上限" :min="1" :precision="0" :disabled="busy" /><ElButton :loading="busy" :disabled="!loaded" @click="makePreview">预览历史补领</ElButton><ElButton type="primary" :disabled="!preview || !preview.eligible_count || !savedLegacyEnabled || !loaded || busy" @click="backfill">执行本批补领</ElButton></div>
          <p v-if="!savedLegacyEnabled" class="muted">请先开启并保存「允许历史补领」，再执行补领。</p>
          <ElAlert v-if="backfillResult" :title="backfillResult" type="success" :closable="false" />
          <template v-if="preview"><div class="preview-summary"><span>符合条件 <b>{{ preview.eligible_count }}</b> 笔</span><span>总面额 <b>{{ amount(preview.total_amount_usd) }}</b></span><ElTag v-if="preview.truncated" type="warning">预览已截断，仍有更多记录</ElTag></div><ElTable :data="preview.records" empty-text="没有符合范围的订单或有效订阅"><ElTableColumn prop="email" label="用户" min-width="200" /><ElTableColumn prop="user_id" label="用户 ID" width="90" /><ElTableColumn prop="trade_no" label="订单号" min-width="180" /><ElTableColumn prop="plan_name" label="套餐" min-width="140" /><ElTableColumn prop="traffic_gb" label="流量 GB" width="100" /><ElTableColumn label="实付"><template #default="{ row }">¥{{ (row.paid_amount_cents / 100).toFixed(2) }}</template></ElTableColumn><ElTableColumn label="面额 USD"><template #default="{ row }">{{ amount(row.amount_usd) }}</template></ElTableColumn></ElTable></template>
          <ElEmpty v-else-if="!backfillResult" description="先预览指定日期起的合格订单与当前有效订阅，再确认执行" />
        </ElTabPane>
      </ElTabs>
    </section>
    <ElDialog :model-value="!!detail" title="福利记录详情" width="min(620px, 94vw)" @close="detail = null"><template v-if="detail"><dl class="detail-grid"><dt>福利 / 订单 ID</dt><dd>#{{ detail.id }} / #{{ detail.order_id }}</dd><dt>订单号</dt><dd>{{ detail.trade_no }}</dd><dt>状态</dt><dd>{{ statuses[detail.status] || detail.status }}</dd><dt>创建时间</dt><dd>{{ time(detail.created_at) }}</dd><dt>发放时间</dt><dd>{{ time(detail.issued_at) }}</dd><dt>兑换时间</dt><dd>{{ time(detail.redeemed_at) }}</dd><dt>最近错误</dt><dd>{{ detail.last_error || '无' }}</dd></dl><p class="muted">此处不展示兑换码。</p></template></ElDialog>
  </div>
</template>

<style scoped>
.ai-credit-page{display:grid;gap:20px;min-width:0}.credit-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}.credit-stats article{padding:22px;border:1px solid var(--xboard-border);border-radius:16px;background:var(--xboard-surface);display:grid;gap:10px}.credit-stats article:first-child{background:linear-gradient(125deg,#eef5ff,#f5f3ff);border-color:#dce6ff}.credit-stats span,.muted,.credit-stats small,.field-hint{color:var(--xboard-text-muted);font-size:13px;line-height:1.7}.credit-stats strong{font-size:28px;letter-spacing:-.8px;color:var(--xboard-text-strong);font-variant-numeric:tabular-nums}.credit-workspace{min-width:0;padding:8px 24px 24px;background:var(--xboard-surface);border:1px solid var(--xboard-border);border-radius:16px}.config-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(0,1fr);gap:32px;padding-top:12px}.config-grid aside{background:#f8fafc;border:1px solid var(--xboard-border);border-radius:12px;padding:22px;min-width:0}h3{font-size:16px;margin:0 0 8px;color:var(--xboard-text-strong)}.section-heading{display:flex;align-items:center;justify-content:space-between;gap:16px;margin:12px 0 24px}.section-heading p{font-size:13px;color:var(--xboard-text-muted);margin:0;line-height:1.7}.field-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.field-grid :deep(.el-input-number),.field-grid :deep(.el-date-editor){width:100%}.rule-note{background:#eff6ff;color:#486280;padding:13px 16px;border-radius:10px;font-size:13px;line-height:1.8;margin:0 0 22px}.switch-row{display:flex;align-items:center;flex-wrap:wrap;gap:8px 20px;margin:12px 0}.switch-row span,.save-bar span{font-size:12px;color:var(--xboard-text-muted)}.links-heading{margin-top:28px}.save-bar{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-top:26px;padding-top:20px;border-top:1px solid var(--xboard-border)}.ledger-filters,.legacy-actions{display:flex;gap:12px;margin:20px 0;flex-wrap:wrap}.ledger-filters :deep(.el-select),.ledger-filters :deep(.el-input){width:200px}.credit-amount{color:#456be7}.pager{display:flex;justify-content:flex-end;gap:16px;align-items:center;margin-top:20px;font-size:13px}.preview-summary{display:flex;gap:24px;flex-wrap:wrap;padding:24px 0}.preview-summary b{font-size:22px;color:#456be7}.detail-grid{display:grid;grid-template-columns:110px 1fr;gap:16px}.detail-grid dt{color:var(--xboard-text-muted)}.detail-grid dd{margin:0;overflow-wrap:anywhere}.ai-credit-page :deep(.el-tabs__item){height:54px}.ai-credit-page :deep(.el-table .cell){overflow-wrap:anywhere}.ai-credit-page :deep(.el-form-item__label){font-size:13px}.ai-credit-page :deep(.el-tab-pane){min-width:0}
@media(max-width:1100px){.config-grid{grid-template-columns:1fr}.credit-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:600px){.credit-workspace{padding:4px 14px 18px}.credit-stats{gap:10px}.credit-stats article{padding:16px 12px}.credit-stats strong{font-size:23px}.field-grid{grid-template-columns:1fr;gap:0}.section-heading{align-items:flex-start;flex-wrap:wrap}.config-grid aside{padding:16px}.save-bar{align-items:flex-start;flex-direction:column}.save-bar .el-button{width:100%}.ledger-filters :deep(.el-select),.ledger-filters :deep(.el-input){width:100%}.pager{justify-content:center;flex-wrap:wrap}.legacy-actions .el-button{margin-left:0}.detail-grid{grid-template-columns:90px 1fr}}
</style>
