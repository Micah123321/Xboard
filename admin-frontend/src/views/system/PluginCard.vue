<script setup lang="ts">
import { computed } from 'vue'
import { Plus, Setting } from '@element-plus/icons-vue'
import type { AdminPluginItem } from '@/types/api'
import { getPluginStatusMeta, getPluginTypeLabel, hasPluginConfig, hasPluginReadme } from '@/utils/plugins'

type PluginAction = 'install' | 'enable' | 'disable' | 'upgrade' | 'uninstall'

const props = defineProps<{
  plugin: AdminPluginItem
  typeLabels: Array<{ value: string; label: string }>
  actionLoadingMap: Record<string, boolean>
}>()

const emit = defineEmits<{
  detail: [plugin: AdminPluginItem]
  action: [payload: { plugin: AdminPluginItem; action: PluginAction }]
}>()

const statusMeta = computed(() => getPluginStatusMeta(props.plugin))
const typeLabel = computed(() => getPluginTypeLabel(props.plugin.type, props.typeLabels))

function isActionLoading(action: PluginAction): boolean {
  return Boolean(props.actionLoadingMap[`${props.plugin.code}:${action}`])
}

function triggerAction(action: PluginAction) {
  emit('action', { plugin: props.plugin, action })
}
</script>

<template>
  <article class="plugin-card">
    <div class="plugin-card__header">
      <div class="plugin-card__title">
        <div class="title-row">
          <h2>{{ props.plugin.name }}</h2>
          <div class="title-tags">
            <ElTag round effect="plain">{{ typeLabel }}</ElTag>
            <ElTag round :type="statusMeta.tone || undefined">
              {{ statusMeta.label }}
            </ElTag>
            <ElTag v-if="props.plugin.is_protected" round type="warning">核心插件</ElTag>
          </div>
        </div>

        <div class="meta-row">
          <code>{{ props.plugin.code }}</code>
          <span>v{{ props.plugin.version }}</span>
          <span>{{ props.plugin.author || '未知作者' }}</span>
        </div>
      </div>

      <button type="button" class="detail-button" @click="emit('detail', props.plugin)">
        <ElIcon><Setting /></ElIcon>
        详情
      </button>
    </div>

    <p class="plugin-card__description">
      {{ props.plugin.description || '当前插件未提供描述信息。' }}
    </p>

    <div class="plugin-card__summary">
      <span>{{ statusMeta.helper }}</span>
      <span v-if="hasPluginConfig(props.plugin)">含配置项</span>
      <span v-if="hasPluginReadme(props.plugin)">含 README</span>
    </div>

    <div class="plugin-card__actions">
      <ElButton
        v-if="!props.plugin.is_installed"
        type="primary"
        :loading="isActionLoading('install')"
        @click="triggerAction('install')"
      >
        <ElIcon><Plus /></ElIcon>
        安装
      </ElButton>

      <ElButton
        v-if="props.plugin.is_installed && !props.plugin.is_enabled"
        :loading="isActionLoading('enable')"
        @click="triggerAction('enable')"
      >
        启用
      </ElButton>

      <ElButton
        v-if="props.plugin.is_installed && props.plugin.is_enabled"
        type="danger"
        plain
        :loading="isActionLoading('disable')"
        @click="triggerAction('disable')"
      >
        禁用
      </ElButton>

      <ElButton
        v-if="props.plugin.need_upgrade"
        type="warning"
        plain
        :loading="isActionLoading('upgrade')"
        @click="triggerAction('upgrade')"
      >
        升级
      </ElButton>

      <ElButton
        v-if="props.plugin.is_installed && !props.plugin.is_enabled"
        plain
        :disabled="props.plugin.is_protected"
        :loading="isActionLoading('uninstall')"
        @click="triggerAction('uninstall')"
      >
        卸载
      </ElButton>
    </div>
  </article>
</template>

<style scoped lang="scss" src="./PluginCard.scss"></style>
