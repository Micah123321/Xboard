<script setup lang="ts">
import { computed } from 'vue'
import { RefreshRight, Search } from '@element-plus/icons-vue'
import type { AdminGiftCardUsageItem } from '@/types/api'
import { formatGiftCardDateTime, formatGiftCardMultiplier } from '@/utils/giftCards'

const props = defineProps<{
  loading: boolean
  error: string
  usages: AdminGiftCardUsageItem[]
  keyword: string
  current: number
  pageSize: number
  total: number
}>()

const emit = defineEmits<{
  (e: 'update:keyword', value: string): void
  (e: 'update:current', value: number): void
  (e: 'update:page-size', value: number): void
  (e: 'reset'): void
  (e: 'refresh'): void
}>()

const keywordModel = computed({
  get: () => props.keyword,
  set: (value: string) => emit('update:keyword', value),
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
      <h2>使用记录</h2>
      <p>查看礼品卡的使用记录和详细信息。</p>
    </div>

    <div class="toolbar">
      <div class="toolbar-left">
        <ElInput v-model="keywordModel" clearable placeholder="搜索用户邮箱..." class="toolbar-search">
          <template #prefix><ElIcon><Search /></ElIcon></template>
        </ElInput>
      </div>
      <div class="toolbar-right">
        <ElButton @click="emit('reset')">重置</ElButton>
        <ElButton @click="emit('refresh')">
          <ElIcon><RefreshRight /></ElIcon>
          刷新
        </ElButton>
      </div>
    </div>

    <ElAlert v-if="error" type="error" :closable="false" show-icon :title="error" />

    <ElTable :data="usages" v-loading="loading" class="data-table" row-key="id" empty-text="暂无数据">
      <ElTableColumn prop="id" label="ID" width="88" />
      <ElTableColumn prop="code" label="兑换码" min-width="240">
        <template #default="{ row }"><span class="mono">{{ row.code }}</span></template>
      </ElTableColumn>
      <ElTableColumn prop="user_email" label="用户邮箱" min-width="220" />
      <ElTableColumn prop="template_name" label="模板名称" min-width="180" />
      <ElTableColumn label="使用倍率" width="120">
        <template #default="{ row }">{{ formatGiftCardMultiplier(row.multiplier_applied) }}</template>
      </ElTableColumn>
      <ElTableColumn label="使用时间" min-width="180">
        <template #default="{ row }">{{ formatGiftCardDateTime(row.created_at) }}</template>
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
