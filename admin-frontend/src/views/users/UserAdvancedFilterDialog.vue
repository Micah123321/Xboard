<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Delete, Plus } from '@element-plus/icons-vue'
import type { AdminPlanOption } from '@/types/api'
import {
  USER_ACTIVITY_STATUS_OPTIONS,
  cloneUserAdvancedFilters,
  createEmptyUserAdvancedFilter,
  getUserAdvancedFieldDefinition,
  USER_ADVANCED_FIELD_DEFINITIONS,
  USER_STATUS_VALUE_OPTIONS,
  type UserAdvancedFilterItem,
  type UserAdvancedOperator,
} from '@/utils/users'

const props = defineProps<{
  visible: boolean
  filters: UserAdvancedFilterItem[]
  plans: AdminPlanOption[]
}>()

const emit = defineEmits<{
  (e: 'update:visible', value: boolean): void
  (e: 'apply', value: UserAdvancedFilterItem[]): void
}>()

const draftFilters = ref<UserAdvancedFilterItem[]>([])

const fieldOptions = computed(() => USER_ADVANCED_FIELD_DEFINITIONS.map((item) => ({
  label: item.label,
  value: item.field,
})))

function resetDraft() {
  draftFilters.value = props.filters.length > 0
    ? cloneUserAdvancedFilters(props.filters)
    : [createEmptyUserAdvancedFilter()]
}

function closeDialog() {
  emit('update:visible', false)
}

function addCondition() {
  draftFilters.value.push(createEmptyUserAdvancedFilter())
}

function removeCondition(index: number) {
  if (draftFilters.value.length === 1) {
    draftFilters.value = [createEmptyUserAdvancedFilter()]
    return
  }

  draftFilters.value.splice(index, 1)
}

function clearAll() {
  draftFilters.value = [createEmptyUserAdvancedFilter()]
}

function getDefinition(field: UserAdvancedFilterItem['field']) {
  return getUserAdvancedFieldDefinition(field)
}

function needsValue(operator: UserAdvancedOperator) {
  return operator !== 'null' && operator !== 'notnull'
}

function handleFieldChange(filter: UserAdvancedFilterItem) {
  const definition = getDefinition(filter.field)
  filter.operator = definition.operators[0]?.value ?? 'eq'
  filter.value = definition.input === 'number' ? null : ''
}

function handleOperatorChange(filter: UserAdvancedFilterItem) {
  if (!needsValue(filter.operator)) {
    filter.value = ''
  }
}

function applyFilters() {
  const cleaned = draftFilters.value
    .map((item) => ({
      ...item,
      value: typeof item.value === 'string' ? item.value.trim() : item.value,
    }))
    .filter((item) => {
      if (!needsValue(item.operator)) {
        return true
      }

      return item.value !== '' && item.value !== null && item.value !== undefined
    })

  emit('apply', cleaned)
}

watch(() => props.visible, (visible) => {
  if (visible) {
    resetDraft()
  }
}, { immediate: true })
</script>

<template>
  <ElDialog
    :model-value="visible"
    width="820px"
    destroy-on-close
    class="advanced-filter-dialog"
    @close="closeDialog"
  >
    <template #header>
      <div class="dialog-header">
        <div>
          <h2>高级筛选</h2>
          <p>添加一个或多个筛选条件来精准查找用户。</p>
        </div>
        <ElButton class="header-button" @click="addCondition">
          <ElIcon><Plus /></ElIcon>
          添加条件
        </ElButton>
      </div>
    </template>

    <div class="dialog-body">
      <article v-for="(filter, index) in draftFilters" :key="filter.key" class="condition-card">
        <header class="condition-header">
          <div class="condition-title">
            <strong>条件 {{ index + 1 }}</strong>
            <ElSelect
              v-if="index > 0"
              v-model="filter.logic"
              size="small"
              class="logic-select"
            >
              <ElOption label="并且" value="and" />
              <ElOption label="或者" value="or" />
            </ElSelect>
          </div>

          <ElButton text class="remove-button" @click="removeCondition(index)">
            <ElIcon><Delete /></ElIcon>
          </ElButton>
        </header>

        <div class="condition-grid">
          <div class="field-block">
            <span>字段</span>
            <ElSelect v-model="filter.field" @change="handleFieldChange(filter)">
              <ElOption
                v-for="option in fieldOptions"
                :key="option.value"
                :label="option.label"
                :value="option.value"
              />
            </ElSelect>
          </div>

          <div class="field-block">
            <span>条件</span>
            <ElSelect v-model="filter.operator" @change="handleOperatorChange(filter)">
              <ElOption
                v-for="option in getDefinition(filter.field).operators"
                :key="option.value"
                :label="option.label"
                :value="option.value"
              />
            </ElSelect>
          </div>

          <div class="field-block field-block--value">
            <span>值</span>

            <template v-if="!needsValue(filter.operator)">
              <div class="value-placeholder">当前条件无需填写值</div>
            </template>

            <ElInput
              v-else-if="getDefinition(filter.field).input === 'text'"
              v-model="filter.value"
              :placeholder="getDefinition(filter.field).placeholder || '请输入筛选值'"
            />

            <ElInputNumber
              v-else-if="getDefinition(filter.field).input === 'number'"
              :model-value="typeof filter.value === 'number' ? filter.value : null"
              :min="0"
              :step="getDefinition(filter.field).step || 1"
              controls-position="right"
              class="full-width"
              @update:model-value="filter.value = $event ?? null"
            />

            <ElSelect
              v-else-if="getDefinition(filter.field).input === 'plan'"
              v-model="filter.value"
              :disabled="!needsValue(filter.operator)"
              placeholder="选择订阅计划"
            >
              <ElOption
                v-for="plan in plans"
                :key="plan.id"
                :label="plan.name"
                :value="plan.id"
              />
            </ElSelect>

            <ElSelect
              v-else-if="getDefinition(filter.field).input === 'status'"
              v-model="filter.value"
              placeholder="选择账号状态"
            >
              <ElOption
                v-for="option in USER_STATUS_VALUE_OPTIONS"
                :key="option.value"
                :label="option.label"
                :value="option.value"
              />
            </ElSelect>

            <ElSelect
              v-else-if="getDefinition(filter.field).input === 'activity'"
              v-model="filter.value"
              placeholder="选择活跃状态"
            >
              <ElOption
                v-for="option in USER_ACTIVITY_STATUS_OPTIONS"
                :key="option.value"
                :label="option.label"
                :value="option.value"
              />
            </ElSelect>

            <ElDatePicker
              v-else
              v-model="filter.value"
              type="datetime"
              value-format="x"
              format="YYYY-MM-DD HH:mm"
              placeholder="选择时间"
              class="full-width"
            />

            <small v-if="getDefinition(filter.field).unit && needsValue(filter.operator)">
              单位：{{ getDefinition(filter.field).unit }}
            </small>
          </div>
        </div>
      </article>
    </div>

    <template #footer>
      <div class="dialog-footer">
        <ElButton @click="closeDialog">取消</ElButton>
        <ElButton @click="clearAll">清空</ElButton>
        <ElButton type="primary" @click="applyFilters">应用筛选</ElButton>
      </div>
    </template>
  </ElDialog>
</template>

<style scoped lang="scss">
.dialog-header,
.condition-header,
.condition-title,
.dialog-footer {
  display: flex;
  align-items: center;
  gap: 12px;
}

.dialog-header,
.condition-header,
.dialog-footer {
  justify-content: space-between;
}

.dialog-header h2 {
  margin: 0;
  font-size: 24px;
  color: var(--xboard-text-strong);
}

.dialog-header p {
  margin: 8px 0 0;
  color: var(--xboard-text-secondary);
}

.header-button {
  border-radius: 999px;
}

.dialog-body {
  display: grid;
  gap: 16px;
}

.condition-card {
  display: grid;
  gap: 14px;
  padding: 18px;
  border-radius: 20px;
  border: 1px solid rgba(15, 23, 42, 0.08);
  background: #fbfbfd;
}

.condition-title strong {
  color: var(--xboard-text-strong);
}

.logic-select {
  width: 96px;
}

.remove-button {
  color: var(--xboard-text-muted);
}

.condition-grid {
  display: grid;
  grid-template-columns: 1.2fr 1fr 1.6fr;
  gap: 14px;
}

.field-block {
  display: grid;
  gap: 8px;
}

.field-block span {
  color: var(--xboard-text-secondary);
  font-size: 13px;
}

.field-block small {
  color: var(--xboard-text-muted);
  font-size: 12px;
}

.field-block--value {
  align-self: start;
}

.value-placeholder {
  display: flex;
  align-items: center;
  min-height: 40px;
  padding: 0 14px;
  border-radius: 12px;
  background: #f1f3f7;
  color: var(--xboard-text-muted);
  font-size: 13px;
}

.full-width {
  width: 100%;
}

@media (max-width: 860px) {
  .dialog-header,
  .condition-header,
  .dialog-footer {
    flex-direction: column;
    align-items: stretch;
  }

  .condition-grid {
    grid-template-columns: 1fr;
  }
}
</style>
