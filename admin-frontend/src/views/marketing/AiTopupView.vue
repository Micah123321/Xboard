<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import PageHeader from '@/components/PageHeader.vue'
import { getAiTopupSettings, saveAiTopupSettings, getAiTopupRecords, reconcileAiTopup } from '@/api/aiTopup'
import type { AiTopupSettings, AiTopupFilters, AiTopupRecords, AiTopupOrder } from '@/types/aiTopup'

const settings = reactive<AiTopupSettings>({ enabled: false, provider_ready: false, tiers: [
  { min_usd: 0, unit_price_cny: '0.150' }, { min_usd: 1000, unit_price_cny: '0.145' }, { min_usd: 3000, unit_price_cny: '0.135' },
  { min_usd: 5000, unit_price_cny: '0.130' }, { min_usd: 6000, unit_price_cny: '0.127' }, { min_usd: 8000, unit_price_cny: '0.123' },
  { min_usd: 9000, unit_price_cny: '0.119' }, { min_usd: 10000, unit_price_cny: '0.115' }, { min_usd: 15000, unit_price_cny: '0.105' },
], min_cny_cents: 500, max_cny_cents: 200000, effective_min_usd: null, effective_max_usd: null, min_usd: 1, max_usd: 1000000, bonus_rate_bps: 150, traffic_gb_per_cny: 10, order_ttl_minutes: 30, site_url: '', pricing_url: '', transparency_url: '', version: 0 })
const loaded = ref(false), loadingSettings = ref(false), saving = ref(false), loading = ref(false)
const settingsError = ref(''), saveError = ref(''), recordsError = ref(''), actionError = ref('')
const tab = ref('settings'), busy = ref<number | null>(null)
const ledger = ref<AiTopupRecords | null>(null)
const detail = ref<AiTopupOrder | null>(null)
const filters = reactive<AiTopupFilters>({ current: 1, page_size: 20, user_id: '', trade_no: '', status: '', issue_status: '' })
let applied = { ...filters }, requestId = 0
const statuses = { pending: '待支付', cancelled: '已取消', paid: '已支付' }
const issues = { pending: '待发放', generating: '生成中', reconciling: '核对中', issued: '已发放', redeemed: '已兑换', revoked: '已停用' }
const bonus = computed(() => String(settings.bonus_rate_bps / 100))
const errorText = (e: unknown) => e instanceof Error ? e.message : '请求失败，请重试'
const money = (cents: number) => '¥' + (cents / 100).toFixed(2)
const traffic = (bytes: number) => (bytes / 1073741824).toLocaleString('zh-CN', { maximumFractionDigits: 3 }) + ' GB'
const time = (value: number | null) => value == null ? '—' : new Date(value * 1000).toLocaleString('zh-CN')
function applySettings(value: AiTopupSettings) {
  for (const key of Object.keys(settings) as (keyof AiTopupSettings)[]) Object.assign(settings, { [key]: value[key] })
  settings.tiers = value.tiers.map(t => ({ min_usd: t.min_usd, unit_price_cny: t.unit_price_cny }))
}
async function loadSettings() {
  if (loadingSettings.value) return
  loadingSettings.value = true; settingsError.value = ''
  try { applySettings(await getAiTopupSettings()); loaded.value = true }
  catch (e) { settingsError.value = errorText(e) }
  finally { loadingSettings.value = false }
}
async function save() {
  if (!loaded.value || saving.value) return
  saveError.value = ''
  if (!Number.isSafeInteger(settings.min_usd) || !Number.isSafeInteger(settings.max_usd) || settings.min_usd < 1 || settings.max_usd > 1000000 || settings.min_usd > settings.max_usd) return void (saveError.value = 'USD 最小与最大面额须为 1 至 1000000 的整数，且最小值不大于最大值')
  if (!Number.isSafeInteger(settings.bonus_rate_bps) || settings.bonus_rate_bps < 0 || settings.bonus_rate_bps > 10000) return void (saveError.value = '赠送比例须为 0 至 10000 的整数基点')
  if (!settings.tiers.length || settings.tiers.length > 100 || settings.tiers[0]?.min_usd !== 0 || settings.tiers.some((t, i) => !Number.isSafeInteger(t.min_usd) || t.min_usd < 0 || t.min_usd > 1000000 || (i > 0 && t.min_usd <= settings.tiers[i - 1]!.min_usd) || !/^(?:0|[1-9]\d{0,3})(\.\d{1,3})?$/.test(t.unit_price_cny) || Number(t.unit_price_cny) <= 0)) return void (saveError.value = '阶梯须为 1 至 100 行、从 0 开始且门槛递增；单价须大于 0，整数最多 4 位、小数最多 3 位')
  saving.value = true
  try {
    applySettings(await saveAiTopupSettings({ enabled: settings.enabled, min_usd: settings.min_usd, max_usd: settings.max_usd, bonus_rate_bps: settings.bonus_rate_bps, order_ttl_minutes: 30, traffic_gb_per_cny: 10, tiers: settings.tiers.map(t => ({ ...t })) }))
    ElMessage.success('充值配置已保存')
  } catch (e) { saveError.value = errorText(e) } finally { saving.value = false }
}
async function loadRecords() {
  const id = ++requestId; loading.value = true; recordsError.value = ''
  try { const result = await getAiTopupRecords({ ...applied, current: filters.current }); if (id === requestId) ledger.value = result }
  catch (e) { if (id === requestId) { recordsError.value = errorText(e); ledger.value = null } }
  finally { if (id === requestId) loading.value = false }
}
function search() {
  if (filters.user_id && !/^[1-9]\d*$/.test(filters.user_id)) return void ElMessage.warning('用户 ID 须为正整数')
  filters.current = 1; applied = { ...filters, trade_no: filters.trade_no.trim() }; void loadRecords()
}
function reset() { Object.assign(filters, { current: 1, user_id: '', trade_no: '', status: '', issue_status: '' }); search() }
async function reconcile(row: AiTopupOrder) {
  if (busy.value != null) return
  busy.value = row.id; actionError.value = ''
  try {
    await ElMessageBox.confirm('沿原订单核对提供方发放结果；不修改支付状态，也不生成新的支付记录。', '核对订单 ' + row.trade_no, { confirmButtonText: '开始核对', cancelButtonText: '取消', type: 'warning' })
    detail.value = await reconcileAiTopup(row.id); await loadRecords(); ElMessage.success('订单核对完成')
  } catch (e) { if (e !== 'cancel' && e !== 'close') actionError.value = errorText(e) }
  finally { busy.value = null }
}
onMounted(() => { void loadSettings(); void loadRecords() })
</script>

<template>
  <div class="topup-page">
    <PageHeader title="AI 充值"><template #actions><ElTag :type="loaded && settings.provider_ready ? 'success' : 'warning'">{{ !loaded ? '配置待加载' : settings.provider_ready ? '提供方已就绪' : '提供方待配置' }}</ElTag><RouterLink to="/marketing/ai-credit">AI 福利桥接配置</RouterLink></template>人民币支付 · 美元额度 · 订单与赠送流量独立管理</PageHeader>
    <section class="workspace">
      <ElTabs v-model="tab">
        <ElTabPane label="充值配置" name="settings">
          <ElAlert v-if="settingsError" :title="settingsError" type="error" :closable="false"><ElButton link :loading="loadingSettings" @click="loadSettings">重新加载配置</ElButton></ElAlert>
          <ElForm v-loading="loadingSettings" label-position="top" :disabled="!loaded || saving" @submit.prevent="save">
            <div v-if="loaded" class="config-grid">
              <div><h3>充值规则</h3><p class="note">充值本金范围：人民币 ¥5–¥2000，手续费另计。USD 面额按当前阶梯单价换算，服务端逐单校验。<span v-if="settings.effective_min_usd != null && settings.effective_max_usd != null">当前已保存规则下可售 {{ settings.effective_min_usd.toLocaleString() }}–{{ settings.effective_max_usd.toLocaleString() }} USD，具体以单笔报价为准。</span><span v-else>当前规则没有符合本金范围的可售面额。</span></p><ElFormItem label="新订单开关"><ElSwitch v-model="settings.enabled" active-text="开启充值" /></ElFormItem>
                <div class="fields"><ElFormItem label="最小面额（整数 USD）"><ElInputNumber v-model="settings.min_usd" aria-label="最小面额" :min="1" :max="1000000" :precision="0" controls-position="right" /></ElFormItem><ElFormItem label="最大面额（整数 USD）"><ElInputNumber v-model="settings.max_usd" aria-label="最大面额" :min="1" :max="1000000" :precision="0" controls-position="right" /></ElFormItem></div>
                <ElFormItem :label="'赠送比例（基点）· ' + bonus + '%'"><ElInputNumber v-model="settings.bonus_rate_bps" aria-label="赠送比例基点" :min="0" :max="10000" :precision="0" controls-position="right" /></ElFormItem>
                <div class="note">150 基点 = 1.5%。按实付本金 × 赠送比例 × 10 GB/CNY 计算赠流量（1 GB = 1024³ 字节），不含手续费。默认本金 ¥30 赠 4.5 GB。未启用赠流量无期限；用户须有有效订阅才能启用，启用后按现有下次流量重置规则清除。</div>
                <div class="policy"><span>待支付期限</span><strong>30 分钟</strong><span>流量换算</span><strong>10 GB/CNY</strong></div>
                <p class="muted">关闭仅影响新充值；已支付订单继续履约。价格与赠流量在下单时快照保存。</p>
                <aside><h3>复用 AI 福利服务</h3><p>提供方配置与免费福利开关独立。凭据统一在原配置维护，此处无需重新输入。</p><RouterLink to="/marketing/ai-credit">前往 AI 福利配置 →</RouterLink><p v-if="loaded && !settings.provider_ready" class="warning">提供方尚未就绪，新充值暂不可用。</p><p class="muted">站点、定价及透明度链接沿用原配置。</p></aside>
              </div>
              <div><h3>整单阶梯单价</h3><p class="muted">按 USD 面额匹配门槛，整单使用对应 CNY/USD 单价。</p>
                <div class="tier-head"><span>最低面额 USD</span><span>单价 CNY/USD</span><span></span></div>
                <div v-for="(tier, index) in settings.tiers" :key="index" class="tier-row"><ElInputNumber v-model="tier.min_usd" :aria-label="'阶梯门槛 ' + (index + 1)" :min="0" :max="1000000" :precision="0" :controls="false" /><ElInput v-model="tier.unit_price_cny" :aria-label="'阶梯单价 ' + (index + 1)" inputmode="decimal" /><ElButton link type="danger" :aria-label="'删除阶梯 ' + (index + 1)" @click="settings.tiers.splice(index, 1)">删除</ElButton></div>
                <ElButton class="add-tier" @click="settings.tiers.push({ min_usd: (settings.tiers.at(-1)?.min_usd ?? 0) + 1000, unit_price_cny: '0.100' })">添加阶梯</ElButton>
              </div>
            </div>
            <ElAlert v-if="saveError" :title="saveError" type="error" :closable="false" />
            <div class="save-bar"><span class="muted">配置版本 {{ settings.version }} · 保存后生效</span><ElButton type="primary" native-type="submit" :loading="saving" :disabled="!loaded">保存充值配置</ElButton></div>
          </ElForm>
        </ElTabPane>
        <ElTabPane label="充值订单" name="records">
          <div class="heading"><div><h3>订单与发放记录</h3><p class="muted">仅核对原请求，不展示兑换码。横向滚动表格可查看完整订单。</p></div><ElButton :loading="loading" @click="loadRecords">刷新订单</ElButton></div>
          <div class="filters"><ElInput v-model="filters.user_id" aria-label="用户 ID" placeholder="用户 ID" clearable @keyup.enter="search" /><ElInput v-model="filters.trade_no" aria-label="订单号" placeholder="AIT 订单号" clearable @keyup.enter="search" /><ElSelect v-model="filters.status" aria-label="支付状态" placeholder="全部支付状态" clearable><ElOption v-for="(label, value) in statuses" :key="value" :label="label" :value="value" /></ElSelect><ElSelect v-model="filters.issue_status" aria-label="发放状态" placeholder="全部发放状态" clearable><ElOption v-for="(label, value) in issues" :key="value" :label="label" :value="value" /></ElSelect><ElButton type="primary" @click="search">查询</ElButton><ElButton @click="reset">重置</ElButton></div>
          <ElAlert v-if="recordsError" :title="recordsError" type="error" :closable="false"><ElButton link @click="loadRecords">重试订单</ElButton></ElAlert><ElAlert v-if="actionError" :title="actionError" type="error" :closable="false" />
          <ElTable v-loading="loading" :data="ledger?.records || []" empty-text="暂无充值订单" stripe>
            <ElTableColumn label="用户 / 订单" min-width="230"><template #default="{ row }"><strong>{{ row.email || '用户 #' + row.user_id }}</strong><div class="muted">#{{ row.user_id }} · {{ row.trade_no }}</div></template></ElTableColumn>
            <ElTableColumn label="面额 / 本金 / 手续费" min-width="180"><template #default="{ row }"><strong>{{ row.amount_usd }} USD</strong><div>本金 {{ money(row.total_amount) }}</div><div class="muted">手续费 {{ money(row.handling_amount) }}</div></template></ElTableColumn>
            <ElTableColumn label="支付 / 发放" min-width="130"><template #default="{ row }"><ElTag :type="row.status === 'paid' ? 'success' : 'info'">{{ statuses[row.status as keyof typeof statuses] || row.status }}</ElTag><div class="muted">{{ issues[row.issue_status as keyof typeof issues] || row.issue_status }}</div></template></ElTableColumn>
            <ElTableColumn label="赠送流量" min-width="150"><template #default="{ row }">{{ traffic(row.gift_traffic_bytes) }}<div class="muted">{{ row.traffic_activated_at ? '已启用 · ' + time(row.traffic_activated_at) : row.status === 'paid' ? '待用户启用' : '支付后可用' }}</div></template></ElTableColumn>
            <ElTableColumn label="创建 / 待付截止" min-width="185"><template #default="{ row }">{{ time(row.created_at) }}<div class="muted">{{ time(row.expires_at) }}</div></template></ElTableColumn>
            <ElTableColumn label="操作" min-width="165"><template #default="{ row }"><ElButton link type="primary" @click="detail = row">详情</ElButton><ElButton link type="primary" :disabled="busy != null || row.status !== 'paid'" @click="reconcile(row)">核对原请求</ElButton></template></ElTableColumn>
          </ElTable>
          <div class="pager"><span class="muted">共 {{ ledger?.total || 0 }} 笔 · 每页 20 笔</span><ElPagination v-model:current-page="filters.current" :page-size="20" :total="ledger?.total || 0" :pager-count="5" layout="prev, pager, next" @current-change="loadRecords" /></div>
        </ElTabPane>
      </ElTabs>
    </section>
    <ElDialog :model-value="!!detail" title="充值订单详情" width="min(620px, 94vw)" @close="detail = null"><dl v-if="detail" class="details"><dt>订单号</dt><dd>{{ detail.trade_no }}</dd><dt>单价</dt><dd>{{ detail.unit_price_cny }} CNY/USD</dd><dt>支付时间</dt><dd>{{ time(detail.paid_at) }}</dd><dt>发放时间</dt><dd>{{ time(detail.issued_at) }}</dd><dt>兑换时间</dt><dd>{{ time(detail.redeemed_at) }}</dd><dt>流量启用</dt><dd>{{ time(detail.traffic_activated_at) }}</dd><dt>最近错误</dt><dd>{{ detail.last_error || '无' }}</dd></dl></ElDialog>
  </div>
</template>

<style scoped>
.topup-page{display:grid;gap:20px;min-width:0}.workspace{min-width:0;padding:8px 24px 24px;border:1px solid var(--xboard-border);border-radius:16px;background:var(--xboard-surface)}.config-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:36px;padding:20px 0}.config-grid>div{min-width:0}h3{font-size:16px;margin:0 0 14px;color:var(--xboard-text-strong)}.muted,aside p{color:var(--xboard-text-muted);font-size:13px;line-height:1.7}.fields{display:grid;grid-template-columns:1fr 1fr;gap:16px}.fields :deep(.el-input-number){width:100%}.note{padding:16px;background:#eff6ff;border-radius:10px;color:#486280;font-size:13px;line-height:1.9}.policy{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:20px;font-size:14px}.policy strong{text-align:right}aside{padding:20px;margin-top:22px;border:1px solid var(--xboard-border);border-radius:12px;background:#f8fafc}a{color:var(--el-color-primary);text-decoration:none;font-size:14px}.warning{color:#b7791f}.tier-head,.tier-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) 40px;gap:10px;align-items:center;margin-bottom:12px}.tier-head{font-size:12px;color:var(--xboard-text-muted)}.tier-row :deep(.el-input-number){width:100%}.add-tier{width:100%;margin-top:4px}.save-bar,.heading,.pager{display:flex;align-items:center;justify-content:space-between;gap:16px}.save-bar{border-top:1px solid var(--xboard-border);padding-top:20px;margin-top:20px}.heading{margin:20px 0}.heading p{margin:0}.filters{display:flex;flex-wrap:wrap;gap:12px;margin:20px 0}.filters :deep(.el-input),.filters :deep(.el-select){width:180px}.filters .el-button+.el-button{margin-left:0}.pager{justify-content:flex-end;flex-wrap:wrap;margin-top:20px}.details{display:grid;grid-template-columns:90px 1fr;gap:16px}.details dt{color:var(--xboard-text-muted)}.details dd{margin:0;overflow-wrap:anywhere}.topup-page :deep(.el-tabs__item){height:54px}.topup-page :deep(.el-table .cell){overflow-wrap:anywhere}.topup-page :deep(.el-alert){margin-bottom:14px}@media(max-width:1100px){.config-grid{grid-template-columns:1fr}}@media(max-width:600px){.workspace{padding:4px 14px 18px}.fields{grid-template-columns:1fr;gap:0}.heading{flex-wrap:wrap}.filters :deep(.el-input),.filters :deep(.el-select){width:100%}.save-bar{align-items:stretch;flex-direction:column}.pager{justify-content:center}.config-grid{gap:24px}aside{padding:16px}.tier-row,.tier-head{gap:6px}.topup-page :deep(.el-pagination){padding:0}}
</style>
