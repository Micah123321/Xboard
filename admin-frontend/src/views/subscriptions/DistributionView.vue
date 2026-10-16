<script setup lang="ts">
import PageHeader from '@/components/PageHeader.vue'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Refresh, Check } from '@element-plus/icons-vue'
import { fetchSubscribePolicy, saveSubscribePolicy } from '@/api/admin'
import type { AdminSubscribePolicyConfig, AdminSubscribePolicyRule } from '@/types/api'
import { getApiBaseUrl } from '@/utils/runtime'
import {
  buildSubscribePolicyExamples,
  parseSubscribePolicy,
  serializeSubscribePolicy,
  validateSubscribePolicy,
} from '@/utils/subscribePolicy'

const rules = ref<AdminSubscribePolicyRule[]>([])
const reservedParameters = ref<string[]>([])
const baseline = ref<string | null>(null)
const loaded = ref(false)
const loading = ref(false)
const saving = ref(false)
const confirming = ref(false)
const loadError = ref('')
const saveError = ref('')
const busy = computed(() => loading.value || saving.value || confirming.value)
const dirty = computed(() => baseline.value !== null
  && JSON.stringify(serializeSubscribePolicy(rules.value)) !== baseline.value)
const errors = computed(() => validateSubscribePolicy(rules.value, reservedParameters.value))
const hasErrors = computed(() => Object.keys(errors.value).length > 0)
const canSave = computed(() => loaded.value && !busy.value && dirty.value && !hasErrors.value)
const examples = computed(() => buildSubscribePolicyExamples(
  rules.value, reservedParameters.value,
  new URL(getApiBaseUrl().replace(/[/]v2[/]?$/, '/v1').replace(/[/]$/, '') + '/client/subscribe', window.location.origin).toString(),
))
const previewItems = computed(() => [
  { label: '默认订阅（按默认下发设置）', url: examples.value.plainUrl },
  ...(examples.value.snellUrl ? [{ label: '显式包含 Snell（仍需客户端兼容）', url: examples.value.snellUrl }] : []),
  { label: '按当前默认值显式传参', url: examples.value.defaultUrl },
  { label: '包含所有允许覆盖的协议', url: examples.value.includeUrl },
  { label: '排除所有允许覆盖的协议', url: examples.value.excludeUrl },
])

function applyConfig(config: AdminSubscribePolicyConfig) {
  const parsed = parseSubscribePolicy(config)
  rules.value = parsed.rules
  reservedParameters.value = parsed.reserved_parameters
  baseline.value = JSON.stringify(serializeSubscribePolicy(parsed.rules))
  loaded.value = true
}

async function confirmDiscard(): Promise<boolean> {
  if (!dirty.value) return true
  if (confirming.value) return false
  confirming.value = true
  try {
    await ElMessageBox.confirm('存在未保存的修改，继续将丢弃这些修改。', '丢弃未保存修改？', {
      type: 'warning', confirmButtonText: '丢弃修改并继续', cancelButtonText: '继续编辑',
    })
    return true
  } catch {
    return false
  } finally {
    confirming.value = false
  }
}

async function loadPolicy() {
  loading.value = true
  loaded.value = false
  loadError.value = ''
  saveError.value = ''
  try {
    const response = await fetchSubscribePolicy()
    if (response.status === 'fail' || response.error) throw new Error(response.message || response.error || '加载失败')
    applyConfig(response.data)
    return true
  } catch (error) {
    loadError.value = error instanceof Error ? error.message : '订阅下发策略加载失败，请重试'
    return false
  } finally {
    loading.value = false
  }
}

async function refreshPolicy() {
  if (busy.value || !(await confirmDiscard())) return
  if (await loadPolicy()) ElMessage.success('订阅下发策略已刷新')
}

async function savePolicy() {
  if (!canSave.value) return
  saving.value = true
  saveError.value = ''
  try {
    const response = await saveSubscribePolicy(serializeSubscribePolicy(rules.value))
    if (response.status === 'fail' || response.error) throw new Error(response.message || response.error || '保存失败')
    applyConfig(response.data)
    ElMessage.success('订阅下发策略已保存')
  } catch (error) {
    saveError.value = error instanceof Error ? error.message : '订阅下发策略保存失败，请重试'
    ElMessage.error(saveError.value)
  } finally {
    saving.value = false
  }
}

function beforeUnload(event: BeforeUnloadEvent) {
  if (!dirty.value && !saving.value) return
  event.preventDefault()
  event.returnValue = ''
}

onBeforeRouteLeave(() => saving.value ? false : confirmDiscard())
onMounted(() => {
  window.addEventListener('beforeunload', beforeUnload)
  void loadPolicy()
})
onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload))
</script>

<template>
  <div class="distribution-page">
    <PageHeader title="订阅下发策略" />

    <section class="policy-shell" aria-labelledby="rules-title" :aria-busy="loading || saving">
      <header class="policy-toolbar">
        <div class="policy-heading">
          <h2 id="rules-title">协议规则</h2>
          <ElTag v-if="dirty" type="warning" effect="plain" role="status">未保存</ElTag>
          <span v-else class="muted" role="status">{{ loaded ? '已与服务器同步' : '等待加载配置' }}</span>
        </div>
        <div class="policy-actions">
          <ElButton :icon="Refresh" :loading="loading" :disabled="busy" @click="refreshPolicy">刷新</ElButton>
          <ElButton type="primary" :icon="Check" :loading="saving" :disabled="!canSave" @click="savePolicy">保存策略</ElButton>
        </div>
      </header>

      <ElAlert v-if="loadError" type="error" title="加载失败，保存已禁用" :description="loadError" show-icon :closable="false" />
      <ElAlert v-if="saveError" type="error" title="保存失败，修改仍保留" :description="saveError" show-icon :closable="false" />
      <ElAlert type="info" :closable="false" show-icon>
        <template #title>参数值 1 表示包含该协议，0 表示排除该协议；关闭参数覆盖时仅按默认下发设置。</template>
        <p>参数只影响用户有权限的节点，仍执行客户端兼容规则。Snell 需 Mihomo 1.19.26+；Surge 继续按现有兼容规则下发。</p>
      </ElAlert>
      <p class="parameter-help">参数名须以小写英文字母开头，仅含小写字母、数字、下划线，长度 1–32 字符；所有协议之间须唯一，且避开系统保留名。</p>
      <p v-if="reservedParameters.length" class="reserved-parameters">系统保留名：<code>{{ reservedParameters.join('、') }}</code></p>
      <ElSkeleton v-if="loading && !rules.length" :rows="8" animated />
      <ElEmpty v-else-if="!rules.length" description="尚未取得完整策略，请点击刷新重试" />

      <ElForm v-else label-position="top" :disabled="!loaded || busy" class="rules-form">
        <article v-for="rule in rules" :key="rule.protocol" class="policy-rule" :data-protocol="rule.protocol">
          <div class="protocol-info">
            <strong>{{ rule.label }}</strong>
            <code>{{ rule.protocol }}</code>
            <span v-if="rule.requirement">{{ rule.requirement }}</span>
          </div>
          <ElFormItem label="默认下发" class="toggle-field">
            <ElSwitch v-model="rule.default_enabled" :aria-label="rule.label + ' 默认下发'" />
            <span class="switch-caption">{{ rule.default_enabled ? '包含' : '排除' }}</span>
          </ElFormItem>
          <ElFormItem label="允许参数覆盖" class="toggle-field">
            <ElSwitch v-model="rule.allow_override" :aria-label="rule.label + ' 允许参数覆盖'" />
            <span class="switch-caption">{{ rule.allow_override ? '允许' : '仅按默认' }}</span>
          </ElFormItem>
          <div class="parameter-field">
            <ElFormItem label="URL 参数名" :for="'parameter-' + rule.protocol" :error="errors[rule.protocol]">
              <ElInput
                :id="'parameter-' + rule.protocol"
                v-model="rule.parameter"
                :aria-label="rule.label + ' URL 参数名'"
                :aria-invalid="Boolean(errors[rule.protocol])"
                :aria-describedby="errors[rule.protocol] ? 'error-' + rule.protocol : undefined"
                autocomplete="off"
                spellcheck="false"
              />
            </ElFormItem>
            <p v-if="errors[rule.protocol]" :id="'error-' + rule.protocol" class="sr-only">{{ errors[rule.protocol] }}</p>
            <p v-if="!rule.allow_override" class="fragment-hint">参数覆盖已关闭，仅按默认{{ rule.default_enabled ? '包含' : '排除' }}</p>
            <p v-else-if="examples.fragments[rule.protocol]" class="fragment-hint">
              <code>{{ examples.fragments[rule.protocol]?.include }}</code> 包含 ·
              <code>{{ examples.fragments[rule.protocol]?.exclude }}</code> 排除
            </p>
          </div>
        </article>
      </ElForm>
      <p v-if="loaded && hasErrors" class="validation-summary" role="alert">请修正标红的参数名后保存；无效参数已从 URL 示例中省略。</p>
    </section>

    <section v-if="rules.length" class="policy-shell examples-shell" aria-labelledby="examples-title">
      <div>
        <h2 id="examples-title">订阅 URL 示例</h2>
        <p class="muted">随当前编辑内容实时变化，保存后生效。TOKEN 为用户订阅令牌占位，请在实际订阅地址上追加参数。</p>
      </div>
      <article v-for="item in previewItems" :key="item.label" class="url-example">
        <span>{{ item.label }}</span>
        <code>{{ item.url }}</code>
      </article>
    </section>
  </div>
</template>

<style scoped lang="scss">
.distribution-page { display: grid; gap: 16px; min-width: 0; }

.policy-shell { display: grid; gap: 18px; padding: 24px; min-width: 0; border-radius: 26px; background: #fff; box-shadow: var(--xboard-shadow); }
h2 { margin: 0; font-size: 19px; color: var(--xboard-text-strong); }
.policy-toolbar, .policy-heading, .policy-actions { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.policy-toolbar { justify-content: space-between; }
.muted, .parameter-help, .reserved-parameters { color: var(--xboard-text-secondary); font-size: 13px; line-height: 1.7; margin: 0; }
.reserved-parameters { overflow-wrap: anywhere; }
.policy-shell :deep(.el-alert__description) p { margin: 0; }
.rules-form { min-width: 0; }
.policy-rule { display: grid; grid-template-columns: minmax(180px, 1.2fr) 112px 140px minmax(200px, 1fr); gap: 24px; align-items: start; padding: 22px 0; border-bottom: 1px solid var(--el-border-color-lighter); }
.policy-rule:first-child { padding-top: 4px; }
.policy-rule:last-child { border-bottom: 0; padding-bottom: 0; }
.protocol-info { display: grid; gap: 6px; min-width: 0; }
.protocol-info strong { font-size: 16px; color: var(--xboard-text-strong); }
.protocol-info code, .protocol-info span { font-size: 12px; color: var(--xboard-text-secondary); line-height: 1.6; overflow-wrap: anywhere; }
.protocol-info .snell-requirement { color: var(--el-color-primary); }
.policy-rule :deep(.el-form-item) { margin-bottom: 0; min-width: 0; }
.toggle-field :deep(.el-form-item__content) { gap: 8px; }
.switch-caption { font-size: 12px; color: var(--xboard-text-secondary); }
.parameter-field { min-width: 0; }
.parameter-field :deep(.el-form-item__error) { position: static; width: 100%; padding-top: 6px; line-height: 1.5; }
.fragment-hint { margin: 8px 0 0; font-size: 12px; color: var(--xboard-text-secondary); line-height: 1.7; overflow-wrap: anywhere; }
.validation-summary { margin: 0; font-size: 13px; color: var(--el-color-danger); }
.url-example { display: grid; gap: 8px; min-width: 0; }
.url-example span { font-size: 13px; color: var(--xboard-text-secondary); }
.url-example code { display: block; padding: 14px 16px; background: #f5f5f7; border-radius: 12px; font-size: 12px; line-height: 1.8; overflow-wrap: anywhere; user-select: all; }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
@media (max-width: 1100px) {
  .policy-rule { grid-template-columns: minmax(160px, 1fr) minmax(200px, 1fr); gap: 18px 24px; }
  .protocol-info { grid-column: 1 / -1; }
  .parameter-field { grid-column: 1 / -1; }
}
@media (max-width: 600px) {
  .distribution-page { gap: 16px; }
  .policy-shell { padding: 20px 16px; border-radius: 20px; }
  .policy-toolbar { align-items: stretch; flex-direction: column; }
  .policy-actions { display: grid; grid-template-columns: 1fr 1fr; }
  .policy-actions :deep(.el-button) { margin-left: 0; min-width: 0; }
  .policy-rule { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 16px; }
}
</style>
