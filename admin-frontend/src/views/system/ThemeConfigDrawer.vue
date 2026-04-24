<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Brush, CircleCheckFilled, MagicStick } from '@element-plus/icons-vue'
import type {
  AdminThemeConfigRecord,
  AdminThemeConfigField,
  AdminThemeSummary,
} from '@/types/api'
import {
  createThemeConfigFormState,
  serializeThemeConfigForm,
  type ThemeConfigFormState,
} from '@/utils/themes'

const props = defineProps<{
  visible: boolean
  theme: AdminThemeSummary | null
  config: AdminThemeConfigRecord | null
  loading: boolean
  saving: boolean
  applying: boolean
  errorMessage?: string
  isActive: boolean
}>()

const emit = defineEmits<{
  (e: 'update:visible', value: boolean): void
  (e: 'save', payload: { name: string; config: AdminThemeConfigRecord }): void
  (e: 'apply', name: string): void
}>()

const form = ref<ThemeConfigFormState>({})

const fields = computed<AdminThemeConfigField[]>(() => props.theme?.configs ?? [])
const hasFields = computed(() => fields.value.length > 0)

function syncFormState() {
  form.value = createThemeConfigFormState(fields.value, props.config)
}

watch(
  () => [props.theme, props.config, props.visible] as const,
  () => {
    syncFormState()
  },
  { immediate: true, deep: true },
)

function handleSave() {
  if (!props.theme) return
  emit('save', {
    name: props.theme.name,
    config: serializeThemeConfigForm(form.value, fields.value),
  })
}

function handleApply() {
  if (!props.theme) return
  emit('apply', props.theme.name)
}
</script>

<template>
  <ElDrawer
    :model-value="visible"
    size="min(560px, calc(100vw - 32px))"
    class="theme-config-drawer"
    @update:model-value="emit('update:visible', $event)"
  >
    <template #header>
      <div class="drawer-header">
        <div class="drawer-title">
          <p>Theme Settings</p>
          <h2>{{ theme?.name || '主题设置' }}</h2>
        </div>

        <div class="drawer-badges">
          <ElTag v-if="theme?.is_system" effect="plain" round>系统主题</ElTag>
          <ElTag v-if="isActive" type="success" effect="light" round>当前主题</ElTag>
        </div>
      </div>
    </template>

    <div v-if="theme" class="drawer-body">
      <section class="drawer-hero">
        <div class="drawer-hero__icon">
          <ElIcon><Brush /></ElIcon>
        </div>

        <div class="drawer-hero__copy">
          <p>{{ theme.description || '当前主题支持独立配置字段，可按需保存并切换。' }}</p>
          <div class="drawer-hero__meta">
            <span>版本 {{ theme.version || '未标注' }}</span>
            <span>{{ fields.length }} 个配置项</span>
          </div>
        </div>
      </section>

      <ElAlert
        v-if="errorMessage"
        :title="errorMessage"
        type="error"
        show-icon
        :closable="false"
      />

      <section v-if="loading" class="drawer-loading">
        <ElSkeleton :rows="4" animated />
        <ElSkeleton :rows="6" animated />
      </section>

      <section v-else-if="hasFields" class="drawer-form-shell">
        <ElForm label-position="top" class="drawer-form">
          <div class="drawer-grid">
            <div
              v-for="field in fields"
              :key="field.field_name"
              class="drawer-field"
              :class="{ 'is-full': field.field_type === 'textarea' }"
            >
              <ElFormItem :label="field.label">
                <ElSelect
                  v-if="field.field_type === 'select'"
                  v-model="form[field.field_name]"
                  class="field-select"
                >
                  <ElOption
                    v-for="(label, value) in field.select_options || {}"
                    :key="`${field.field_name}-${value}`"
                    :label="label"
                    :value="value"
                  />
                </ElSelect>

                <ElInput
                  v-else-if="field.field_type === 'textarea'"
                  v-model="form[field.field_name]"
                  type="textarea"
                  :rows="6"
                  :placeholder="field.placeholder"
                  resize="vertical"
                />

                <ElInput
                  v-else
                  v-model="form[field.field_name]"
                  :placeholder="field.placeholder"
                />

                <div class="field-foot">
                  <ElIcon><MagicStick /></ElIcon>
                  <span class="mono">{{ field.field_name }}</span>
                </div>
              </ElFormItem>
            </div>
          </div>
        </ElForm>
      </section>

      <section v-else class="drawer-empty">
        <ElIcon><CircleCheckFilled /></ElIcon>
        <div>
          <h3>当前主题没有额外配置项</h3>
          <p>你仍然可以将它设为当前主题；若后续主题包新增 `config.json` 字段，这里会自动显示。</p>
        </div>
      </section>
    </div>

    <template #footer>
      <div class="drawer-footer">
        <ElButton @click="emit('update:visible', false)">关闭</ElButton>
        <ElButton
          v-if="!isActive"
          :loading="applying"
          @click="handleApply"
        >
          设为当前主题
        </ElButton>
        <ElButton v-else disabled>当前主题</ElButton>
        <ElButton
          type="primary"
          :loading="saving"
          :disabled="loading"
          @click="handleSave"
        >
          保存设置
        </ElButton>
      </div>
    </template>
  </ElDrawer>
</template>

<style scoped>
.drawer-header {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  align-items: flex-start;
}

.drawer-title {
  display: grid;
  gap: 6px;
}

.drawer-title p {
  margin: 0;
  color: var(--xboard-text-muted);
  font-size: 11px;
  letter-spacing: 0.18em;
  text-transform: uppercase;
}

.drawer-title h2 {
  margin: 0;
  color: var(--xboard-text-strong);
  font-size: 28px;
  line-height: 1.08;
  letter-spacing: -0.28px;
}

.drawer-badges {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.drawer-body {
  display: grid;
  gap: 18px;
}

.drawer-hero {
  display: flex;
  gap: 16px;
  padding: 18px 20px;
  border-radius: 20px;
  background: #f5f5f7;
}

.drawer-hero__icon {
  width: 48px;
  height: 48px;
  display: grid;
  place-items: center;
  border-radius: 16px;
  background: #1d1d1f;
  color: #ffffff;
  font-size: 18px;
}

.drawer-hero__copy {
  display: grid;
  gap: 10px;
}

.drawer-hero__copy p {
  margin: 0;
  color: var(--xboard-text-secondary);
  line-height: 1.6;
}

.drawer-hero__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  color: var(--xboard-text-muted);
  font-size: 12px;
}

.drawer-loading,
.drawer-form-shell {
  display: grid;
  gap: 16px;
}

.drawer-form {
  display: grid;
}

.drawer-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 18px 16px;
}

.drawer-field.is-full {
  grid-column: 1 / -1;
}

.field-select {
  width: 100%;
}

.field-foot {
  margin-top: 8px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: var(--xboard-text-muted);
  font-size: 12px;
}

.drawer-empty {
  display: flex;
  gap: 14px;
  padding: 18px 20px;
  border-radius: 20px;
  background: #f5f5f7;
  color: var(--xboard-text-secondary);
}

.drawer-empty :deep(.el-icon) {
  margin-top: 2px;
  color: #0071e3;
  font-size: 18px;
}

.drawer-empty h3 {
  margin: 0 0 8px;
  color: var(--xboard-text-strong);
  font-size: 18px;
}

.drawer-empty p {
  margin: 0;
  line-height: 1.6;
}

.drawer-footer {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
}

@media (max-width: 767px) {
  .drawer-grid {
    grid-template-columns: 1fr;
  }

  .drawer-footer {
    width: 100%;
    flex-wrap: wrap;
  }

  .drawer-footer :deep(.el-button) {
    flex: 1 1 140px;
  }
}
</style>
