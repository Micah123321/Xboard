<script setup lang="ts">
import { ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { fetchUserTrafficLogs } from '@/api/admin'
import type { AdminTrafficLogItem, TrafficAmount } from '@/types/api'
import { formatDateTime, formatTraffic } from '@/utils/dashboard'
import { formatServerRate, getTrafficTotal } from '@/utils/tickets'

const props = defineProps<{
  visible: boolean
  userId: number | null
  userEmail?: string
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
}>()

const loading = ref(false)
const records = ref<AdminTrafficLogItem[]>([])
const total = ref(0)
const current = ref(1)
const pageSize = ref(20)
const timeRange = ref<string[] | []>([])
const summary = ref<TrafficAmount>({
  upload: 0,
  download: 0,
  total: 0,
})

async function loadRecords() {
  if (!props.visible || !props.userId) {
    records.value = []
    total.value = 0
    summary.value = { upload: 0, download: 0, total: 0 }
    return
  }

  const hasRange = Array.isArray(timeRange.value) && timeRange.value.length === 2
  const startTime = hasRange ? Number(timeRange.value[0]) : undefined
  const endTime = hasRange ? Number(timeRange.value[1]) : undefined

  loading.value = true
  try {
    const response = await fetchUserTrafficLogs({
      userId: props.userId,
      pageSize: pageSize.value,
      page: current.value,
      minTotal: 500 * 1024,
      startTime,
      endTime,
    })
    records.value = response.data
    total.value = response.total
    summary.value = response.summary ?? { upload: 0, download: 0, total: 0 }
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '流量日志加载失败')
  } finally {
    loading.value = false
  }
}

watch(
  () => [props.visible, props.userId],
  ([visible, userId]) => {
    if (!visible || !userId) {
      return
    }

    current.value = 1
    void loadRecords()
  },
  { immediate: true },
)

watch([current, pageSize], () => {
  void loadRecords()
})

function handleRangeChange() {
  current.value = 1
  void loadRecords()
}
</script>

<template>
  <ElDialog
    :model-value="props.visible"
    width="760px"
    class="traffic-log-dialog"
    append-to-body
    destroy-on-close
    @close="emit('update:visible', false)"
    @update:model-value="emit('update:visible', $event)"
  >
    <template #header>
      <div class="dialog-header">
        <div>
          <p>Traffic Logs</p>
          <h2>流量使用记录</h2>
        </div>
        <span>{{ props.userEmail || '未知用户' }}</span>
      </div>
    </template>

    <div class="dialog-body">
      <div class="dialog-toolbar">
        <ElDatePicker
          v-model="timeRange"
          type="datetimerange"
          value-format="X"
          start-placeholder="开始时间（按日志显示时间）"
          end-placeholder="结束时间（按日志显示时间）"
          range-separator="至"
          clearable
          @change="handleRangeChange"
        />
      </div>

      <div class="summary-grid">
        <article>
          <span>当前筛选上行</span>
          <strong>{{ formatTraffic(summary.upload) }}</strong>
        </article>
        <article>
          <span>当前筛选下行</span>
          <strong>{{ formatTraffic(summary.download) }}</strong>
        </article>
        <article>
          <span>当前搜索流量综合</span>
          <strong>{{ formatTraffic(summary.total) }}</strong>
        </article>
      </div>

      <ElTable :data="records" v-loading="loading" class="traffic-table" row-key="id">
        <ElTableColumn label="时间" min-width="132">
          <template #default="{ row }">
            {{ formatDateTime(row.display_at || row.record_at) }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="上传流量" min-width="110">
          <template #default="{ row }">
            {{ formatTraffic(row.u) }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="下行流量" min-width="110">
          <template #default="{ row }">
            {{ formatTraffic(row.d) }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="倍率" width="86">
          <template #default="{ row }">
            {{ formatServerRate(row.server_rate) }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="节点" min-width="160">
          <template #default="{ row }">
            {{ row.node_name || row.server_name || 'Unknown' }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="总计" min-width="110">
          <template #default="{ row }">
            {{ formatTraffic(getTrafficTotal(row)) }}
          </template>
        </ElTableColumn>
      </ElTable>

      <footer class="dialog-footer">
        <span>共 {{ total }} 条记录</span>
        <ElPagination
          v-model:current-page="current"
          v-model:page-size="pageSize"
          :page-sizes="[20, 50, 100]"
          layout="total, sizes, prev, pager, next"
          :total="total"
          background
        />
      </footer>
    </div>
  </ElDialog>
</template>

<style scoped>
.dialog-header {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 12px;
}

.dialog-header p {
  font-size: 11px;
  letter-spacing: 0.22em;
  text-transform: uppercase;
  color: var(--xboard-text-muted);
}

.dialog-header h2 {
  font-size: 30px;
  line-height: 1.08;
  color: var(--xboard-text-strong);
}

.dialog-header span {
  color: var(--xboard-text-secondary);
}

.dialog-body {
  display: grid;
  gap: 16px;
}

.dialog-toolbar {
  display: flex;
  justify-content: space-between;
  gap: 12px;
}

.summary-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}

.summary-grid article {
  display: grid;
  gap: 6px;
  padding: 16px 18px;
  border-radius: 16px;
  background: #f5f5f7;
}

.summary-grid span {
  color: var(--xboard-text-muted);
  font-size: 12px;
}

.summary-grid strong {
  color: var(--xboard-text-strong);
  font-size: 22px;
}

.traffic-table :deep(th.el-table__cell) {
  color: var(--xboard-text-secondary);
  background: #fbfbfd;
}

.dialog-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.dialog-footer span {
  color: var(--xboard-text-muted);
}

@media (max-width: 767px) {
  .dialog-header,
  .dialog-footer,
  .dialog-toolbar {
    flex-direction: column;
    align-items: stretch;
  }

  .summary-grid {
    grid-template-columns: 1fr;
  }
}
</style>
