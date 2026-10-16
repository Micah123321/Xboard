<script setup lang="ts">
import { computed } from 'vue'
import type { AdminPlanListItem, AdminSubscriptionLabel } from '@/types/api'
import { formatSubscriptionPlan } from '@/utils/subscriptionLabels'

const props = defineProps<{
  modelValue?: number | null
  labels: AdminSubscriptionLabel[]
  plans: AdminPlanListItem[]
  labelLoadError?: string
  planLoadError?: string
  loading?: boolean
  existingLabel?: AdminSubscriptionLabel | null
}>()
const emit = defineEmits<{
  'update:modelValue': [value: number | null]
  retry: []
}>()
const selectedLabel = computed(() => props.labels.find((label) => label.id === props.modelValue)
  ?? ((props.loading || props.labelLoadError) && props.existingLabel?.id === props.modelValue ? props.existingLabel : null))
const absent = computed(() => props.modelValue != null && !props.labels.some((label) => label.id === props.modelValue))
</script>

<template>
  <div class="subscription-label-picker" data-testid="subscription-label-picker">
    <ElAlert v-if="props.labelLoadError || props.planLoadError" type="warning" :closable="false" show-icon>
      <template #title>{{ [props.labelLoadError, props.planLoadError].filter(Boolean).join('；') }}，当前选择已保留。</template>
      <ElButton size="small" :loading="props.loading" data-testid="retry-subscription-options" @click="emit('retry')">重试加载标签与套餐</ElButton>
    </ElAlert>
    <ElSelect
      :model-value="props.modelValue"
      filterable clearable
      :value-on-clear="null"
      :loading="props.loading"
      :disabled="props.loading || Boolean(props.labelLoadError)"
      placeholder="搜索订阅标签；清除后恢复原节点规则"
      no-data-text="暂无订阅标签，请先在订阅管理中创建"
      no-match-text="未找到匹配标签"
      aria-label="订阅标签"
      @update:model-value="emit('update:modelValue', typeof $event === 'number' ? $event : null)"
    >
      <ElOption v-for="label in props.labels" :key="label.id" :value="label.id" :label="label.name + ' (#' + label.id + ')'" />
      <ElOption v-if="absent" :value="props.modelValue!" :label="selectedLabel?.name || ('订阅标签 #' + props.modelValue + (props.labelLoadError || props.loading ? '（信息待重试）' : '（已删除）'))" disabled />
    </ElSelect>
    <template v-if="props.modelValue != null">
      <p v-if="!selectedLabel && !props.loading && !props.labelLoadError" class="label-warning" role="status">标签已不存在，此节点无人可用；请选择其他标签或清除标签恢复原节点规则。</p>
      <template v-else-if="selectedLabel">
        <div class="label-plans" data-testid="subscription-label-plans">
          <ElTag v-for="id in selectedLabel.plan_ids" :key="id" effect="plain">{{ formatSubscriptionPlan(id, props.plans, props.planLoadError || (props.loading ? '加载中' : '')) }}</ElTag>
          <span v-if="!selectedLabel.plan_ids.length" class="label-warning">标签未绑定套餐，无人可用</span>
        </div>
        <p>按该标签绑定套餐分配；原手动白名单保留，清除标签后恢复原规则。切换标签后按新标签执行。</p>
      </template>
    </template>
    <p v-else>未选择订阅标签，继续使用节点原有的手动套餐限制与权限组规则。</p>
  </div>
</template>

<style scoped>
.subscription-label-picker { display: grid; gap: 10px; width: 100%; min-width: 0; }
.subscription-label-picker p { margin: 0; color: var(--xboard-text-muted); font-size: 13px; line-height: 1.6; overflow-wrap: anywhere; }
.subscription-label-picker .label-warning { color: var(--el-color-danger); }
.label-plans { display: flex; gap: 6px; flex-wrap: wrap; }
.label-plans :deep(.el-tag) { height: auto; padding: 4px 8px; line-height: 1.5; white-space: normal; overflow-wrap: anywhere; }
</style>
