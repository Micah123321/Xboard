<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { Document, Setting } from '@element-plus/icons-vue'
import type { AdminPluginItem } from '@/types/api'
import {
  createPluginConfigDraft,
  getPluginConfigFields,
  getPluginStatusMeta,
  getPluginTypeLabel,
  hasPluginConfig,
  hasPluginReadme,
  renderPluginReadme,
  serializePluginConfigDraft,
  type PluginConfigDraft,
} from '@/utils/plugins'

const props = defineProps<{
  visible: boolean
  plugin: AdminPluginItem | null
  loading?: boolean
  saving?: boolean
  typeLabels?: Array<{ value: string; label: string }>
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  saveConfig: [value: Record<string, unknown>]
}>()

const activeTab = reactive<{ value: 'readme' | 'config' }>({ value: 'readme' })
const configDraft = reactive<PluginConfigDraft>({})

const statusMeta = computed(() => props.plugin ? getPluginStatusMeta(props.plugin) : null)
const pluginTypeLabel = computed(() => props.plugin
  ? getPluginTypeLabel(props.plugin.type, props.typeLabels || [])
  : '未知类型')
const configFields = computed(() => getPluginConfigFields(props.plugin))
const readmeHtml = computed(() => renderPluginReadme(props.plugin?.readme || ''))
const readmeAvailable = computed(() => hasPluginReadme(props.plugin))
const configAvailable = computed(() => hasPluginConfig(props.plugin))
const canEditConfig = computed(() => Boolean(props.plugin?.is_installed && configAvailable.value))

function resetDraft() {
  Object.keys(configDraft).forEach((key) => {
    delete configDraft[key]
  })

  const nextDraft = createPluginConfigDraft(props.plugin)
  Object.entries(nextDraft).forEach(([key, value]) => {
    configDraft[key] = value
  })
}

function handleSave() {
  if (!props.plugin || !configAvailable.value) {
    return
  }

  try {
    emit('saveConfig', serializePluginConfigDraft(props.plugin, configDraft))
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '插件配置保存失败')
  }
}

watch(
  () => [props.visible, props.plugin?.code, props.plugin?.is_installed, props.plugin?.config] as const,
  () => {
    activeTab.value = readmeAvailable.value ? 'readme' : 'config'
    resetDraft()
  },
  { immediate: true },
)
</script>

<template>
  <ElDrawer
    :model-value="props.visible"
    size="min(620px, 100vw)"
    append-to-body
    destroy-on-close
    class="plugin-detail-drawer"
    @close="emit('update:visible', false)"
    @update:model-value="emit('update:visible', $event)"
  >
    <template #header>
      <div class="drawer-header">
        <div class="drawer-copy">
          <p>Plugin Workspace</p>
          <h2>{{ props.plugin?.name || '插件详情' }}</h2>
          <span>{{ props.plugin?.description || '查看插件说明、状态与配置。' }}</span>
        </div>

        <div v-if="props.plugin" class="drawer-meta">
          <ElTag round effect="plain">{{ pluginTypeLabel }}</ElTag>
          <ElTag round :type="statusMeta?.tone || undefined">{{ statusMeta?.label }}</ElTag>
          <ElTag v-if="props.plugin.is_protected" round type="warning">核心插件</ElTag>
        </div>
      </div>
    </template>

    <div class="drawer-shell" v-loading="props.loading">
      <template v-if="props.plugin">
        <section class="overview-card">
          <article>
            <span>插件代号</span>
            <strong>{{ props.plugin.code }}</strong>
          </article>
          <article>
            <span>当前版本</span>
            <strong>v{{ props.plugin.version }}</strong>
          </article>
          <article>
            <span>作者</span>
            <strong>{{ props.plugin.author || '未知作者' }}</strong>
          </article>
          <article>
            <span>状态说明</span>
            <strong>{{ statusMeta?.helper }}</strong>
          </article>
        </section>

        <div class="tab-row">
          <button
            type="button"
            class="tab-pill"
            :class="{ active: activeTab.value === 'readme' }"
            @click="activeTab.value = 'readme'"
          >
            <ElIcon><Document /></ElIcon>
            说明文档
          </button>

          <button
            type="button"
            class="tab-pill"
            :class="{ active: activeTab.value === 'config' }"
            @click="activeTab.value = 'config'"
          >
            <ElIcon><Setting /></ElIcon>
            插件配置
          </button>
        </div>

        <section v-if="activeTab.value === 'readme'" class="panel-card">
          <div v-if="readmeAvailable" class="markdown-shell markdown-body" v-html="readmeHtml" />
          <ElEmpty v-else description="当前插件未提供 README 说明" />
        </section>

        <section v-else class="panel-card">
          <ElAlert
            v-if="!props.plugin.is_installed"
            type="info"
            show-icon
            :closable="false"
            title="安装后才可保存配置，当前先展示配置结构预览。"
            class="config-alert"
          />

          <ElForm v-if="configAvailable" label-position="top" class="config-form">
            <div class="config-grid">
              <div
                v-for="field in configFields"
                :key="field.key"
                class="config-field"
                :class="{ 'is-wide': field.type === 'text' || field.type === 'json' }"
              >
                <ElFormItem :label="field.label || field.key">
                  <ElSwitch
                    v-if="field.type === 'boolean'"
                    :model-value="Boolean(configDraft[field.key])"
                    :disabled="!canEditConfig || props.saving"
                    @update:model-value="configDraft[field.key] = $event"
                  />

                  <ElInputNumber
                    v-else-if="field.type === 'number'"
                    :model-value="Number(configDraft[field.key] ?? 0)"
                    :disabled="!canEditConfig || props.saving"
                    controls-position="right"
                    class="field-number"
                    @update:model-value="configDraft[field.key] = $event ?? 0"
                  />

                  <ElSelect
                    v-else-if="field.type === 'select'"
                    :model-value="configDraft[field.key]"
                    :disabled="!canEditConfig || props.saving"
                    class="field-select"
                    @update:model-value="configDraft[field.key] = $event as string | number | boolean"
                  >
                    <ElOption
                      v-for="option in field.options"
                      :key="`${field.key}-${option.value}`"
                      :label="option.label"
                      :value="option.value"
                    />
                  </ElSelect>

                  <ElInput
                    v-else-if="field.type === 'text' || field.type === 'json'"
                    :model-value="String(configDraft[field.key] ?? '')"
                    type="textarea"
                    :rows="field.type === 'json' ? 7 : 4"
                    :placeholder="field.placeholder"
                    :disabled="!canEditConfig || props.saving"
                    @update:model-value="configDraft[field.key] = $event"
                  />

                  <ElInput
                    v-else
                    :model-value="String(configDraft[field.key] ?? '')"
                    :placeholder="field.placeholder"
                    :disabled="!canEditConfig || props.saving"
                    clearable
                    @update:model-value="configDraft[field.key] = $event"
                  />

                  <p v-if="field.description" class="field-helper">
                    {{ field.description }}
                  </p>
                </ElFormItem>
              </div>
            </div>
          </ElForm>

          <ElEmpty v-else description="当前插件没有可编辑配置项" />
        </section>
      </template>
    </div>

    <template #footer>
      <div class="drawer-footer">
        <ElButton @click="emit('update:visible', false)">关闭</ElButton>
        <ElButton
          type="primary"
          :disabled="!canEditConfig"
          :loading="props.saving"
          @click="handleSave"
        >
          保存配置
        </ElButton>
      </div>
    </template>
  </ElDrawer>
</template>
<style scoped lang="scss" src="./PluginDetailDrawer.scss"></style>
