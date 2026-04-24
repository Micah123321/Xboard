<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import type { ComponentPublicInstance } from 'vue'
import { ElMessage } from 'element-plus'
import { CircleCheckFilled, Message, RefreshRight, Setting, WarningFilled } from '@element-plus/icons-vue'
import { fetchAdminConfig, getPlans, saveAdminConfig, setTelegramWebhook, testAdminMail } from '@/api/admin'
import type { AdminPlanListItem } from '@/types/api'
import { formatDateTime } from '@/utils/dashboard'
import {
  createSystemConfigFormState,
  getSystemConfigFieldOptions,
  normalizeSystemConfigMappings,
  serializeSystemConfigForm,
  systemConfigSections,
  type SystemConfigFieldSchema,
  type SystemConfigFieldValue,
  type SystemConfigSectionKey,
} from '@/utils/systemConfig'

const loading = ref(true)
const reloading = ref(false)
const saving = ref(false)
const errorMessage = ref('')
const activeSection = ref<SystemConfigSectionKey>('site')
const auxiliaryAction = ref<'mail' | 'telegram' | null>(null)
const plans = ref<AdminPlanListItem[]>([])
const lastLoadedAt = ref<string | null>(null)

const form = reactive(createSystemConfigFormState())
const sectionRefs = new Map<SystemConfigSectionKey, HTMLElement>()
const originalSnapshot = ref(JSON.stringify(serializeSystemConfigForm(form)))

const resolvedSections = computed(() => systemConfigSections.map((section) => ({
  ...section,
  fields: section.fields.map((field) => ({
    ...field,
    options: getSystemConfigFieldOptions(field, plans.value),
  })),
})))

const currentSnapshot = computed(() => JSON.stringify(serializeSystemConfigForm(form)))
const isDirty = computed(() => currentSnapshot.value !== originalSnapshot.value)
const saveStatusText = computed(() => {
  if (saving.value) return '配置保存中'
  if (isDirty.value) return '存在未保存改动'
  return '已与服务端同步'
})

const summaryCards = computed(() => [
  {
    label: '站点名称',
    value: String(form.app_name || '未命名站点'),
  },
  {
    label: '后台路径',
    value: form.secure_path ? `/${form.secure_path}` : '未设置',
  },
  {
    label: '注册状态',
    value: Boolean(form.stop_register) ? '暂停注册' : '开放注册',
  },
])

function applyFormState() {
  originalSnapshot.value = JSON.stringify(serializeSystemConfigForm(form))
  lastLoadedAt.value = new Date().toISOString()
}

function assignFormState(nextState: Record<string, SystemConfigFieldValue>) {
  Object.keys(nextState).forEach((key) => {
    form[key] = nextState[key]
  })
  applyFormState()
}

async function loadPage(mode: 'initial' | 'reload' = 'initial') {
  if (mode === 'initial') {
    loading.value = true
  } else {
    reloading.value = true
  }

  errorMessage.value = ''

  try {
    const [configResult, plansResult] = await Promise.allSettled([
      fetchAdminConfig(),
      getPlans(),
    ])

    if (configResult.status === 'rejected') {
      throw configResult.reason
    }

    const nextState = normalizeSystemConfigMappings(configResult.value.data)
    assignFormState(nextState)

    if (plansResult.status === 'fulfilled') {
      plans.value = plansResult.value.data ?? []
    } else {
      plans.value = []
      ElMessage.warning('试用套餐列表加载失败，注册试用下拉选项将暂时不可用')
    }
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '系统配置加载失败'
  } finally {
    loading.value = false
    reloading.value = false
  }
}

function getFieldValue(key: string): SystemConfigFieldValue {
  return form[key]
}

function updateField(key: string, value: SystemConfigFieldValue) {
  form[key] = value
}

function resolveNumberValue(field: SystemConfigFieldSchema): number | undefined {
  const value = getFieldValue(field.key)
  if (typeof value === 'number') return value
  if (value === null || value === '') return undefined
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : undefined
}

function resolveTextValue(field: SystemConfigFieldSchema): string {
  const value = getFieldValue(field.key)
  if (value === null || value === undefined) return ''
  if (Array.isArray(value)) return value.join(', ')
  return String(value)
}

function registerSection(key: SystemConfigSectionKey) {
  return (element: Element | ComponentPublicInstance | null) => {
    if (element instanceof HTMLElement) {
      sectionRefs.set(key, element)
      return
    }

    if (element && '$el' in element && element.$el instanceof HTMLElement) {
      sectionRefs.set(key, element.$el)
    }
  }
}

function jumpToSection(key: SystemConfigSectionKey) {
  activeSection.value = key
  sectionRefs.get(key)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

async function handleSave() {
  if (saving.value) return
  saving.value = true

  try {
    const payload = serializeSystemConfigForm(form)
    await saveAdminConfig(payload)
    originalSnapshot.value = JSON.stringify(payload)
    ElMessage.success('系统配置已保存')
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '系统配置保存失败')
  } finally {
    saving.value = false
  }
}

function ensureSavedBeforeAuxiliaryAction(): boolean {
  if (isDirty.value) {
    ElMessage.warning('请先保存当前配置，再执行辅助操作')
    return false
  }
  return true
}

async function handleTestMail() {
  if (!ensureSavedBeforeAuxiliaryAction()) return

  auxiliaryAction.value = 'mail'
  try {
    await testAdminMail()
    ElMessage.success('测试邮件已触发，请检查当前管理员邮箱')
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '测试邮件发送失败')
  } finally {
    auxiliaryAction.value = null
  }
}

async function handleSetWebhook() {
  if (!ensureSavedBeforeAuxiliaryAction()) return

  auxiliaryAction.value = 'telegram'
  try {
    await setTelegramWebhook({
      telegram_bot_token: String(form.telegram_bot_token || ''),
    })
    ElMessage.success('Telegram Webhook 设置成功')
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : 'Telegram Webhook 设置失败')
  } finally {
    auxiliaryAction.value = null
  }
}

onMounted(() => {
  void loadPage()
})
</script>

<template>
  <div class="config-page">
    <section class="config-hero">
      <div class="config-copy">
        <p class="config-kicker">System Settings</p>
        <h1>系统设置。</h1>
        <p>
          管理系统核心配置，包括站点、安全、订阅、邀请佣金、节点、邮件与通知相关设置。
        </p>

        <div class="config-status">
          <ElIcon :class="{ danger: isDirty }">
            <WarningFilled v-if="isDirty" />
            <CircleCheckFilled v-else />
          </ElIcon>
          <span>{{ saveStatusText }}</span>
          <small v-if="lastLoadedAt">最近加载于 {{ formatDateTime(lastLoadedAt) }}</small>
        </div>
      </div>

      <div class="hero-side">
        <div class="hero-actions">
          <ElButton :loading="reloading" @click="loadPage('reload')">
            <ElIcon><RefreshRight /></ElIcon>
            重新拉取
          </ElButton>
          <ElButton type="primary" :loading="saving" :disabled="!isDirty" @click="handleSave">
            保存配置
          </ElButton>
        </div>

        <div class="hero-summary">
          <article v-for="item in summaryCards" :key="item.label">
            <span>{{ item.label }}</span>
            <strong>{{ item.value }}</strong>
          </article>
        </div>
      </div>
    </section>

    <section v-if="loading" class="loading-shell">
      <ElSkeleton :rows="4" animated />
      <ElSkeleton :rows="6" animated />
    </section>

    <section v-else class="config-shell">
      <aside class="config-nav">
        <button
          v-for="section in resolvedSections"
          :key="section.key"
          type="button"
          class="nav-item"
          :class="{ active: section.key === activeSection }"
          @click="jumpToSection(section.key)"
        >
          <span>{{ section.navLabel }}</span>
          <small>{{ section.fields.length }} 项</small>
        </button>
      </aside>

      <div class="config-content">
        <ElAlert
          v-if="errorMessage"
          type="error"
          show-icon
          :closable="false"
          class="config-error"
          :title="errorMessage"
        >
          <template #default>
            <ElButton size="small" @click="loadPage('reload')">重新加载</ElButton>
          </template>
        </ElAlert>

        <article
          v-for="section in resolvedSections"
          :key="section.key"
          :ref="registerSection(section.key)"
          class="config-section"
        >
          <header class="section-header">
            <div class="section-copy">
              <p>{{ section.navLabel }}</p>
              <h2>{{ section.title }}</h2>
              <span>{{ section.description }}</span>
            </div>

            <div v-if="section.key === 'email' || section.key === 'telegram'" class="section-actions">
              <ElButton
                v-if="section.key === 'email'"
                :loading="auxiliaryAction === 'mail'"
                @click="handleTestMail"
              >
                <ElIcon><Message /></ElIcon>
                发送测试邮件
              </ElButton>

              <ElButton
                v-if="section.key === 'telegram'"
                :loading="auxiliaryAction === 'telegram'"
                @click="handleSetWebhook"
              >
                <ElIcon><Setting /></ElIcon>
                设置 Webhook
              </ElButton>
            </div>
          </header>

          <ElForm label-position="top" class="config-form">
            <div class="config-grid">
              <div
                v-for="field in section.fields"
                :key="field.key"
                class="config-field"
                :class="{ 'is-full': field.fullWidth }"
              >
                <ElFormItem :label="field.label">
                  <ElSwitch
                    v-if="field.type === 'switch'"
                    :model-value="Boolean(getFieldValue(field.key))"
                    @update:model-value="updateField(field.key, $event)"
                  />

                  <ElInputNumber
                    v-else-if="field.type === 'number'"
                    :model-value="resolveNumberValue(field)"
                    :min="field.min"
                    :max="field.max"
                    :step="field.step ?? 1"
                    controls-position="right"
                    class="field-number"
                    @update:model-value="updateField(field.key, $event ?? (field.nullable ? null : field.defaultValue ?? 0))"
                  />

                  <ElSelect
                    v-else-if="field.type === 'select'"
                    :model-value="getFieldValue(field.key)"
                    :multiple="field.multiple"
                    :allow-create="field.allowCreate"
                    :filterable="field.multiple || field.allowCreate"
                    :clearable="!field.multiple"
                    collapse-tags
                    collapse-tags-tooltip
                    class="field-select"
                    @update:model-value="updateField(field.key, $event as SystemConfigFieldValue)"
                  >
                    <ElOption
                      v-for="option in field.options"
                      :key="`${field.key}-${option.value}`"
                      :label="option.label"
                      :value="option.value"
                    />
                  </ElSelect>

                  <ElInput
                    v-else-if="field.type === 'textarea'"
                    :model-value="resolveTextValue(field)"
                    type="textarea"
                    :rows="field.rows ?? 4"
                    :placeholder="field.placeholder"
                    :autosize="field.rows ? undefined : { minRows: 4, maxRows: 10 }"
                    @update:model-value="updateField(field.key, $event)"
                  />

                  <ElInput
                    v-else
                    :model-value="resolveTextValue(field)"
                    :type="field.type === 'password' ? 'password' : 'text'"
                    :show-password="field.type === 'password'"
                    :placeholder="field.placeholder"
                    clearable
                    @update:model-value="updateField(field.key, $event)"
                  />

                  <p v-if="field.helper" class="field-helper">
                    {{ field.helper }}
                  </p>
                </ElFormItem>
              </div>
            </div>
          </ElForm>
        </article>
      </div>
    </section>
  </div>
</template>

<style scoped>
.config-page {
  display: grid;
  gap: 24px;
}

.config-hero {
  display: flex;
  justify-content: space-between;
  gap: 24px;
  padding: 34px;
  border-radius: 28px;
  background: #000000;
}

.config-copy {
  display: grid;
  gap: 12px;
  max-width: 620px;
}

.config-kicker {
  margin: 0;
  color: rgba(255, 255, 255, 0.68);
  font-size: 11px;
  letter-spacing: 0.24em;
  text-transform: uppercase;
}

.config-copy h1 {
  margin: 0;
  color: #ffffff;
  font-size: clamp(34px, 5vw, 52px);
  line-height: 1.08;
  letter-spacing: -0.28px;
}

.config-copy > p:last-of-type {
  margin: 0;
  color: rgba(255, 255, 255, 0.72);
  line-height: 1.6;
}

.config-status {
  display: inline-flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 8px;
  color: rgba(255, 255, 255, 0.72);
}

.config-status :deep(.el-icon) {
  color: #2997ff;
}

.config-status :deep(.el-icon.danger) {
  color: #f59e0b;
}

.config-status small {
  color: rgba(255, 255, 255, 0.52);
}

.hero-side {
  display: grid;
  gap: 16px;
  min-width: 360px;
}

.hero-actions {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
}

.hero-summary {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}

.hero-summary article {
  display: grid;
  gap: 6px;
  padding: 18px;
  border-radius: 18px;
  background: rgba(255, 255, 255, 0.08);
}

.hero-summary span {
  color: rgba(255, 255, 255, 0.64);
  font-size: 12px;
}

.hero-summary strong {
  color: #ffffff;
  font-size: 20px;
  line-height: 1.2;
}

.loading-shell {
  display: grid;
  gap: 16px;
  padding: 28px;
  border-radius: 24px;
  background: #ffffff;
  box-shadow: var(--xboard-shadow);
}

.config-shell {
  display: grid;
  grid-template-columns: 240px minmax(0, 1fr);
  gap: 20px;
  align-items: start;
}

.config-nav,
.config-content {
  display: grid;
  gap: 18px;
}

.config-nav {
  position: sticky;
  top: 0;
  padding: 18px;
  border-radius: 24px;
  background: #ffffff;
  box-shadow: var(--xboard-shadow);
}

.nav-item {
  display: grid;
  gap: 4px;
  width: 100%;
  padding: 14px 16px;
  border: 1px solid transparent;
  border-radius: 18px;
  background: transparent;
  color: var(--xboard-text-secondary);
  text-align: left;
  cursor: pointer;
  transition: background-color 0.18s ease, border-color 0.18s ease, color 0.18s ease;
}

.nav-item span {
  color: inherit;
  font-weight: 600;
}

.nav-item small {
  color: var(--xboard-text-muted);
  font-size: 12px;
}

.nav-item.active {
  border-color: rgba(0, 113, 227, 0.14);
  background: rgba(0, 113, 227, 0.08);
  color: #0071e3;
}

.config-error {
  margin-bottom: 2px;
}

.config-section {
  display: grid;
  gap: 22px;
  padding: 28px;
  border-radius: 24px;
  background: #ffffff;
  box-shadow: var(--xboard-shadow);
}

.section-header {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  align-items: flex-start;
}

.section-copy {
  display: grid;
  gap: 8px;
}

.section-copy p {
  margin: 0;
  color: var(--xboard-text-muted);
  font-size: 12px;
  letter-spacing: 0.18em;
  text-transform: uppercase;
}

.section-copy h2 {
  margin: 0;
  color: var(--xboard-text-strong);
  font-size: 30px;
  line-height: 1.1;
  letter-spacing: -0.28px;
}

.section-copy span {
  color: var(--xboard-text-secondary);
  line-height: 1.6;
}

.section-actions {
  display: flex;
  gap: 12px;
}

.config-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 18px 16px;
}

.config-field.is-full {
  grid-column: 1 / -1;
}

.field-number,
.field-select {
  width: 100%;
}

.field-helper {
  margin-top: 8px;
  color: var(--xboard-text-muted);
  font-size: 12px;
  line-height: 1.5;
}

@media (max-width: 1180px) {
  .config-hero,
  .config-shell,
  .section-header {
    grid-template-columns: 1fr;
    flex-direction: column;
  }

  .hero-side {
    min-width: 0;
  }

  .hero-actions,
  .section-actions {
    justify-content: flex-start;
  }

  .config-shell {
    display: grid;
  }

  .config-nav {
    position: static;
    grid-auto-flow: column;
    grid-auto-columns: minmax(180px, 1fr);
    overflow-x: auto;
  }
}

@media (max-width: 767px) {
  .config-grid,
  .hero-summary {
    grid-template-columns: 1fr;
  }

  .config-hero {
    padding: 28px 24px;
  }

  .config-section {
    padding: 22px;
  }
}
</style>
