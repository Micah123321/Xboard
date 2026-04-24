<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { getHorizonFailedJobs } from '@/api/admin'
import type { AdminQueueFailedJob } from '@/types/api'
import { formatCompactNumber, formatDateTime } from '@/utils/dashboard'

const props = defineProps<{
  visible: boolean
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
}>()

type LooseRecord = Record<string, unknown>

const loading = ref(false)
const records = ref<AdminQueueFailedJob[]>([])
const total = ref(0)
const current = ref(1)
const pageSize = ref(10)

const latestFailedJob = computed(() => records.value[0] ?? null)
const summaryCards = computed(() => [
  {
    label: '失败总数',
    value: formatCompactNumber(total.value),
    detail: `当前页 ${records.value.length} 条`,
  },
  {
    label: '最近失败时间',
    value: latestFailedJob.value ? formatFailedAt(latestFailedJob.value) : 'N/A',
    detail: '按最新失败时间倒序展示',
  },
  {
    label: '最近失败队列',
    value: latestFailedJob.value ? getQueueName(latestFailedJob.value) : 'N/A',
    detail: latestFailedJob.value ? getJobName(latestFailedJob.value) : '暂无失败作业',
  },
])

function isLooseRecord(value: unknown): value is LooseRecord {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function getByPath(source: LooseRecord | null, path: string): unknown {
  if (!source) return undefined

  return path.split('.').reduce<unknown>((current, segment) => {
    if (!isLooseRecord(current)) return undefined
    return current[segment]
  }, source)
}

function firstText(...values: unknown[]): string | null {
  for (const value of values) {
    if (typeof value === 'string' && value.trim()) {
      return value.trim()
    }

    if (typeof value === 'number' && Number.isFinite(value)) {
      return String(value)
    }
  }

  return null
}

function getPayload(record: AdminQueueFailedJob): LooseRecord | null {
  if (isLooseRecord(record.payload)) {
    return record.payload
  }

  if (typeof record.payload === 'string' && record.payload.trim()) {
    try {
      const parsed = JSON.parse(record.payload)
      return isLooseRecord(parsed) ? parsed : null
    } catch {
      return null
    }
  }

  return null
}

function getIdentifier(record: AdminQueueFailedJob): string {
  return firstText(record.id, record.uuid) ?? 'unknown'
}

function getJobName(record: AdminQueueFailedJob): string {
  const payload = getPayload(record)

  return firstText(
    record.name,
    getByPath(payload, 'displayName'),
    getByPath(payload, 'data.commandName'),
    getByPath(payload, 'job'),
    record.uuid,
    record.id,
  ) ?? '未知任务'
}

function getQueueName(record: AdminQueueFailedJob): string {
  const payload = getPayload(record)

  return firstText(
    record.queue,
    getByPath(payload, 'queue'),
    record.connection,
  ) ?? 'N/A'
}

function getFailureTime(record: AdminQueueFailedJob): number | string | null {
  const payload = getPayload(record)

  return (
    firstText(
      record.failed_at,
      record['failedAt'],
      getByPath(payload, 'failed_at'),
      record['completed_at'],
      record['completedAt'],
    ) ?? null
  )
}

function formatFailedAt(record: AdminQueueFailedJob): string {
  return formatDateTime(getFailureTime(record))
}

function getErrorMessage(record: AdminQueueFailedJob): string {
  const payload = getPayload(record)

  return firstText(
    record.exception,
    record['message'],
    getByPath(payload, 'exception'),
    getByPath(payload, 'message'),
  ) ?? '暂无错误详情'
}

function getErrorSummary(record: AdminQueueFailedJob): string {
  const rawMessage = getErrorMessage(record)
  const lines = rawMessage
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean)
  const firstLine = lines[0] ?? rawMessage

  return firstLine.length > 180
    ? `${firstLine.slice(0, 177)}…`
    : firstLine
}

async function loadRecords() {
  if (!props.visible) {
    records.value = []
    total.value = 0
    return
  }

  loading.value = true
  try {
    const response = await getHorizonFailedJobs({
      current: current.value,
      pageSize: pageSize.value,
    })

    records.value = response.data ?? []
    total.value = response.total ?? 0
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '失败作业列表加载失败')
  } finally {
    loading.value = false
  }
}

function handleRefresh() {
  if (current.value !== 1) {
    current.value = 1
    return
  }

  void loadRecords()
}

function closeDialog() {
  emit('update:visible', false)
}

watch(
  () => props.visible,
  async (visible) => {
    if (!visible) {
      records.value = []
      total.value = 0
      return
    }

    current.value = 1
    await loadRecords()
  },
  { immediate: true },
)

watch([current, pageSize], () => {
  if (!props.visible) {
    return
  }

  void loadRecords()
})
</script>

<template>
  <ElDialog
    :model-value="props.visible"
    width="860px"
    class="queue-failed-jobs-dialog"
    append-to-body
    destroy-on-close
    @close="closeDialog"
    @update:model-value="emit('update:visible', $event)"
  >
    <template #header>
      <div class="dialog-header">
        <div>
          <p>Queue Failures</p>
          <h2>失败作业报错详情</h2>
        </div>
        <span>共 {{ total }} 条失败作业</span>
      </div>
    </template>

    <div class="dialog-body">
      <div class="dialog-toolbar">
        <p>集中查看 Horizon 失败作业的报错摘要、失败时间与队列信息。</p>
        <ElButton text class="ghost-action" :loading="loading" @click="handleRefresh">
          重新加载
        </ElButton>
      </div>

      <div class="summary-grid">
        <article v-for="item in summaryCards" :key="item.label">
          <span>{{ item.label }}</span>
          <strong>{{ item.value }}</strong>
          <p>{{ item.detail }}</p>
        </article>
      </div>

      <div v-if="!loading && !records.length" class="empty-shell">
        <ElEmpty description="当前没有失败作业" />
      </div>

      <div v-else class="error-list" v-loading="loading">
        <article
          v-for="(record, index) in records"
          :key="`${getIdentifier(record)}-${getFailureTime(record) ?? index}`"
          class="error-card"
        >
          <div class="error-card__header">
            <div>
              <p>{{ getJobName(record) }}</p>
              <span>#{{ getIdentifier(record) }}</span>
            </div>
            <strong>{{ getQueueName(record) }}</strong>
          </div>

          <div class="error-card__meta">
            <span>失败时间</span>
            <strong>{{ formatFailedAt(record) }}</strong>
            <span>报错摘要</span>
            <strong class="error-card__summary" :title="getErrorMessage(record)">
              {{ getErrorSummary(record) }}
            </strong>
          </div>
        </article>
      </div>

      <footer class="dialog-footer">
        <span>当前第 {{ current }} 页，每页 {{ pageSize }} 条</span>
        <ElPagination
          v-model:current-page="current"
          v-model:page-size="pageSize"
          :page-sizes="[10, 20, 50]"
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
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.dialog-toolbar p {
  margin: 0;
  color: var(--xboard-text-secondary);
}

.ghost-action {
  color: #0071e3;
  padding-inline: 0;
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

.summary-grid span,
.summary-grid p {
  margin: 0;
  color: var(--xboard-text-muted);
}

.summary-grid strong {
  color: var(--xboard-text-strong);
  font-size: 22px;
  line-height: 1.14;
}

.error-list {
  display: grid;
  gap: 12px;
  min-height: 120px;
}

.error-card {
  display: grid;
  gap: 12px;
  padding: 18px 20px;
  border-radius: 18px;
  background: #fbfbfd;
  border: 1px solid rgba(0, 0, 0, 0.04);
}

.error-card__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.error-card__header p,
.error-card__header span,
.error-card__meta span {
  margin: 0;
}

.error-card__header p {
  color: var(--xboard-text-strong);
  font-size: 18px;
  line-height: 1.24;
}

.error-card__header span {
  color: var(--xboard-text-muted);
  font-size: 12px;
}

.error-card__header strong,
.error-card__meta strong {
  color: var(--xboard-text-strong);
}

.error-card__meta {
  display: grid;
  grid-template-columns: max-content minmax(0, 1fr);
  gap: 8px 14px;
}

.error-card__meta span {
  color: var(--xboard-text-muted);
  font-size: 12px;
}

.error-card__summary {
  line-height: 1.5;
  color: #b42318;
  word-break: break-word;
}

.empty-shell {
  padding: 24px 0;
  border-radius: 18px;
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
  .dialog-toolbar,
  .error-card__header,
  .dialog-footer {
    flex-direction: column;
    align-items: flex-start;
  }

  .summary-grid {
    grid-template-columns: 1fr;
  }

  .error-card__meta {
    grid-template-columns: 1fr;
  }
}
</style>
