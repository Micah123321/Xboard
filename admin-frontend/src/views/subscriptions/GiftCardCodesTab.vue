<script setup lang="ts">
import { computed } from 'vue'
import {
  CopyDocument,
  Delete,
  Download,
  EditPen,
  Plus,
  Search,
} from '@element-plus/icons-vue'
import type {
  AdminGiftCardCodeItem,
  AdminGiftCardTemplateItem,
} from '@/types/api'
import type { GiftCardCodeStatusFilter } from '@/utils/giftCards'
import {
  formatGiftCardDateTime,
  getGiftCardAvailableUsage,
  getGiftCardCodeStatusMeta,
} from '@/utils/giftCards'

const props = defineProps<{
  loading: boolean
  error: string
  codes: AdminGiftCardCodeItem[]
  keyword: string
  templateFilter: number | 'all'
  statusFilter: GiftCardCodeStatusFilter
  current: number
  pageSize: number
  total: number
  templates: AdminGiftCardTemplateItem[]
  resolvedBatchId: string
}>()

const emit = defineEmits<{
  (e: 'update:keyword', value: string): void
  (e: 'update:template-filter', value: number | 'all'): void
  (e: 'update:status-filter', value: GiftCardCodeStatusFilter): void
  (e: 'update:current', value: number): void
  (e: 'update:page-size', value: number): void
  (e: 'create'): void
  (e: 'export'): void
  (e: 'reset'): void
  (e: 'copy', code: string): void
  (e: 'select-batch', batchId: string): void
  (e: 'edit', code: AdminGiftCardCodeItem): void
  (e: 'delete', code: AdminGiftCardCodeItem): void
  (e: 'toggle', code: AdminGiftCardCodeItem, nextValue: string | number | boolean): void
}>()

const keywordModel = computed({
  get: () => props.keyword,
  set: (value: string) => emit('update:keyword', value),
})

const templateFilterModel = computed({
  get: () => props.templateFilter,
  set: (value: number | 'all') => emit('update:template-filter', value),
})

const statusFilterModel = computed({
  get: () => props.statusFilter,
  set: (value: GiftCardCodeStatusFilter) => emit('update:status-filter', value),
})

const currentModel = computed({
  get: () => props.current,
  set: (value: number) => emit('update:current', value),
})

const pageSizeModel = computed({
  get: () => props.pageSize,
  set: (value: number) => emit('update:page-size', value),
})

function isCodeEnabled(code: AdminGiftCardCodeItem): boolean {
  return code.status !== 3
}

function isCodeToggleDisabled(code: AdminGiftCardCodeItem): boolean {
  return code.status === 1 || code.status === 2
}
</script>

<template>
  <div class="tab-panel">
    <div class="panel-copy">
      <h2>兑换码管理</h2>
      <p>管理礼品卡兑换码，包括生成、查看和导出兑换码。</p>
    </div>

    <div class="toolbar">
      <div class="toolbar-left">
        <ElInput v-model="keywordModel" clearable placeholder="搜索礼品卡..." class="toolbar-search">
          <template #prefix><ElIcon><Search /></ElIcon></template>
        </ElInput>

        <ElSelect v-model="templateFilterModel" class="toolbar-filter">
          <ElOption label="模板" value="all" />
          <ElOption v-for="item in templates" :key="item.id" :label="item.name" :value="item.id" />
        </ElSelect>

        <ElSelect v-model="statusFilterModel" class="toolbar-filter">
          <ElOption label="状态" value="all" />
          <ElOption
            v-for="item in [
              { label: '未使用', value: 0 },
              { label: '已使用', value: 1 },
              { label: '已过期', value: 2 },
              { label: '已禁用', value: 3 },
            ]"
            :key="item.value"
            :label="item.label"
            :value="item.value"
          />
        </ElSelect>
      </div>

      <div class="toolbar-right">
        <ElButton type="primary" @click="emit('create')">
          <ElIcon><Plus /></ElIcon>
          生成兑换码
        </ElButton>
        <ElButton :disabled="!resolvedBatchId" @click="emit('export')">
          <ElIcon><Download /></ElIcon>
          导出
        </ElButton>
        <ElButton @click="emit('reset')">重置</ElButton>
      </div>
    </div>

    <div v-if="resolvedBatchId" class="batch-tip">
      当前批次：
      <button type="button" class="batch-pill batch-pill--active" @click="emit('select-batch', resolvedBatchId)">
        {{ resolvedBatchId }}
      </button>
    </div>

    <ElAlert v-if="error" type="error" :closable="false" show-icon :title="error" />

    <ElTable :data="codes" v-loading="loading" class="data-table" row-key="id" empty-text="当前筛选条件下暂无兑换码">
      <ElTableColumn prop="id" label="ID" width="88" />
      <ElTableColumn label="兑换码" min-width="260">
        <template #default="{ row }">
          <div class="code-cell">
            <div class="code-line">
              <strong class="mono">{{ row.code }}</strong>
              <ElButton text @click="emit('copy', row.code)"><ElIcon><CopyDocument /></ElIcon></ElButton>
            </div>
            <button type="button" class="batch-pill" @click="emit('select-batch', row.batch_id || '')">
              {{ row.batch_id || '无批次' }}
            </button>
          </div>
        </template>
      </ElTableColumn>
      <ElTableColumn prop="template_name" label="模板名称" min-width="160" />
      <ElTableColumn label="状态" min-width="150">
        <template #default="{ row }">
          <div class="status-with-switch">
            <span class="pill" :class="`pill--${getGiftCardCodeStatusMeta(row.status).tone}`">
              {{ getGiftCardCodeStatusMeta(row.status).label }}
            </span>
            <ElSwitch
              :model-value="isCodeEnabled(row)"
              :disabled="isCodeToggleDisabled(row)"
              @change="emit('toggle', row, $event)"
            />
          </div>
        </template>
      </ElTableColumn>
      <ElTableColumn label="过期时间" min-width="180">
        <template #default="{ row }">{{ formatGiftCardDateTime(row.expires_at) }}</template>
      </ElTableColumn>
      <ElTableColumn label="已用/总次数" width="140">
        <template #default="{ row }">{{ row.usage_count }} / {{ row.max_usage }}</template>
      </ElTableColumn>
      <ElTableColumn label="可用次数" width="110">
        <template #default="{ row }">{{ getGiftCardAvailableUsage(row) }}</template>
      </ElTableColumn>
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
