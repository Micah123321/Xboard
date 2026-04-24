<script setup lang="ts">
import { computed } from 'vue'
import { Delete, EditPen, Plus, Search } from '@element-plus/icons-vue'
import type {
  AdminGiftCardTemplateItem,
  AdminGiftCardTemplateType,
  AdminPlanOption,
} from '@/types/api'
import type { GiftCardOption, GiftCardTemplateStatusFilter } from '@/utils/giftCards'
import {
  formatGiftCardDateTime,
  getGiftCardTemplateRewardSummary,
} from '@/utils/giftCards'

const props = defineProps<{
  loading: boolean
  error: string
  templates: AdminGiftCardTemplateItem[]
  keyword: string
  typeFilter: AdminGiftCardTemplateType | 'all'
  statusFilter: GiftCardTemplateStatusFilter
  current: number
  pageSize: number
  total: number
  typeOptions: Array<GiftCardOption<AdminGiftCardTemplateType>>
  plans: AdminPlanOption[]
}>()

const emit = defineEmits<{
  (e: 'update:keyword', value: string): void
  (e: 'update:type-filter', value: AdminGiftCardTemplateType | 'all'): void
  (e: 'update:status-filter', value: GiftCardTemplateStatusFilter): void
  (e: 'update:current', value: number): void
  (e: 'update:page-size', value: number): void
  (e: 'create'): void
  (e: 'reset'): void
  (e: 'edit', template: AdminGiftCardTemplateItem): void
  (e: 'delete', template: AdminGiftCardTemplateItem): void
  (e: 'toggle', template: AdminGiftCardTemplateItem, nextValue: string | number | boolean): void
}>()

const keywordModel = computed({
  get: () => props.keyword,
  set: (value: string) => emit('update:keyword', value),
})

const typeFilterModel = computed({
  get: () => props.typeFilter,
  set: (value: AdminGiftCardTemplateType | 'all') => emit('update:type-filter', value),
})

const statusFilterModel = computed({
  get: () => props.statusFilter,
  set: (value: GiftCardTemplateStatusFilter) => emit('update:status-filter', value),
})

const currentModel = computed({
  get: () => props.current,
  set: (value: number) => emit('update:current', value),
})

const pageSizeModel = computed({
  get: () => props.pageSize,
  set: (value: number) => emit('update:page-size', value),
})
</script>

<template>
  <div class="tab-panel">
    <div class="panel-copy">
      <h2>模板管理</h2>
      <p>管理礼品卡模板，包括创建、编辑和删除模板。</p>
    </div>

    <div class="toolbar">
      <div class="toolbar-left">
        <ElInput v-model="keywordModel" clearable placeholder="搜索礼品卡..." class="toolbar-search">
          <template #prefix><ElIcon><Search /></ElIcon></template>
        </ElInput>

        <ElSelect v-model="typeFilterModel" class="toolbar-filter">
          <ElOption label="类型" value="all" />
          <ElOption v-for="item in typeOptions" :key="item.value" :label="item.label" :value="item.value" />
        </ElSelect>

        <ElSelect v-model="statusFilterModel" class="toolbar-filter">
          <ElOption label="状态" value="all" />
          <ElOption label="启用中" value="enabled" />
          <ElOption label="已停用" value="disabled" />
        </ElSelect>
      </div>

      <div class="toolbar-right">
        <ElButton type="primary" @click="emit('create')">
          <ElIcon><Plus /></ElIcon>
          添加模板
        </ElButton>
        <ElButton @click="emit('reset')">重置</ElButton>
      </div>
    </div>

    <ElAlert v-if="error" type="error" :closable="false" show-icon :title="error" />

    <ElTable :data="templates" v-loading="loading" class="data-table" row-key="id" empty-text="当前筛选条件下暂无模板">
      <ElTableColumn prop="id" label="ID" width="88" />
      <ElTableColumn label="状态" width="110">
        <template #default="{ row }">
          <ElSwitch :model-value="Boolean(row.status)" @change="emit('toggle', row, $event)" />
        </template>
      </ElTableColumn>
      <ElTableColumn label="名称" min-width="220">
        <template #default="{ row }">
          <div class="name-cell">
            <strong>{{ row.name }}</strong>
            <span>{{ row.description || '暂无描述' }}</span>
          </div>
        </template>
      </ElTableColumn>
      <ElTableColumn label="类型" width="150">
        <template #default="{ row }">
          <span class="pill pill--soft">{{ row.type_name }}</span>
        </template>
      </ElTableColumn>
      <ElTableColumn label="奖励内容" min-width="260">
        <template #default="{ row }">
          <div class="reward-stack">
            <span v-for="item in getGiftCardTemplateRewardSummary(row, plans)" :key="item" class="reward-chip">{{ item }}</span>
          </div>
        </template>
      </ElTableColumn>
      <ElTableColumn prop="sort" label="排序" width="90" />
      <ElTableColumn label="创建时间" min-width="180">
        <template #default="{ row }">{{ formatGiftCardDateTime(row.created_at) }}</template>
      </ElTableColumn>
      <ElTableColumn label="操作" width="130" fixed="right">
        <template #default="{ row }">
          <div class="action-group">
            <ElButton text @click="emit('edit', row)"><ElIcon><EditPen /></ElIcon></ElButton>
            <ElButton text class="danger-btn" @click="emit('delete', row)"><ElIcon><Delete /></ElIcon></ElButton>
          </div>
        </template>
      </ElTableColumn>
    </ElTable>

    <footer class="table-footer">
      <span>已选择 0 项，共 {{ total }} 项</span>
      <ElPagination
        v-model:current-page="currentModel"
        v-model:page-size="pageSizeModel"
        :page-sizes="[20, 50, 100]"
        layout="sizes, prev, pager, next"
        :total="total"
        background
      />
    </footer>
  </div>
</template>
