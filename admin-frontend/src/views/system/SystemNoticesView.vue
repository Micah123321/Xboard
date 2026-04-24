<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  ArrowDown,
  ArrowUp,
  Delete,
  EditPen,
  Plus,
  Search,
} from '@element-plus/icons-vue'
import {
  deleteNotice,
  fetchNotices,
  sortNotices,
  toggleNoticeVisibility,
} from '@/api/admin'
import type { AdminNoticeItem } from '@/types/api'
import { formatDateTime } from '@/utils/dashboard'
import {
  countEnabledNotices,
  filterNotices,
  moveNoticeOrder,
  normalizeNoticeItem,
  summarizeNoticeContent,
} from '@/utils/notices'
import SystemNoticeEditorDialog from './SystemNoticeEditorDialog.vue'

type EditorMode = 'create' | 'edit'

const loading = ref(false)
const sortSubmitting = ref(false)
const editorVisible = ref(false)
const editorMode = ref<EditorMode>('create')
const activeNotice = ref<AdminNoticeItem | null>(null)
const sortDialogVisible = ref(false)
const keyword = ref('')
const current = ref(1)
const pageSize = ref(50)

const notices = ref<AdminNoticeItem[]>([])
const sortDraft = ref<AdminNoticeItem[]>([])
const toggleLoadingMap = ref<Record<number, boolean>>({})

const filteredNotices = computed(() => filterNotices(notices.value, keyword.value))
const visibleNotices = computed(() => {
  const start = (current.value - 1) * pageSize.value
  return filteredNotices.value.slice(start, start + pageSize.value)
})

const heroStats = computed(() => [
  { label: '公告总数', value: String(notices.value.length) },
  { label: '显示中', value: String(countEnabledNotices(notices.value, 'show')) },
  { label: '弹窗公告', value: String(countEnabledNotices(notices.value, 'popup')) },
  { label: '带标签', value: String(notices.value.filter((notice) => notice.tags?.length).length) },
])

function isToggleLoading(id: number): boolean {
  return Boolean(toggleLoadingMap.value[id])
}

async function loadData() {
  loading.value = true
  try {
    const response = await fetchNotices()
    notices.value = (response.data ?? []).map((notice) => normalizeNoticeItem(notice))
  } finally {
    loading.value = false
  }
}

function openCreateDialog() {
  editorMode.value = 'create'
  activeNotice.value = null
  editorVisible.value = true
}

function openEditDialog(notice: AdminNoticeItem) {
  editorMode.value = 'edit'
  activeNotice.value = notice
  editorVisible.value = true
}

async function handleToggle(notice: AdminNoticeItem, nextValue: boolean | string | number) {
  const normalizedNextValue = Boolean(nextValue)
  if (Boolean(notice.show) === normalizedNextValue) {
    return
  }

  toggleLoadingMap.value[notice.id] = true
  try {
    await toggleNoticeVisibility(notice.id)
    notice.show = normalizedNextValue
    ElMessage.success('公告显示状态已更新')
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '公告显示状态更新失败')
  } finally {
    toggleLoadingMap.value[notice.id] = false
  }
}

async function handleDelete(notice: AdminNoticeItem) {
  try {
    await ElMessageBox.confirm(`删除公告「${notice.title}」后无法恢复，确认继续吗？`, '删除公告', {
      type: 'warning',
    })
    await deleteNotice(notice.id)
    ElMessage.success('公告已删除')
    await loadData()
  } catch (error) {
    if (error === 'cancel' || error === 'close') {
      return
    }
    ElMessage.error(error instanceof Error ? error.message : '公告删除失败')
  }
}

function openSortEditor() {
  sortDraft.value = notices.value.map((notice) => ({ ...notice }))
  sortDialogVisible.value = true
}

function moveDraft(index: number, direction: -1 | 1) {
  sortDraft.value = moveNoticeOrder(sortDraft.value, index, direction)
}

async function submitSort() {
  sortSubmitting.value = true
  try {
    await sortNotices(sortDraft.value.map((item) => item.id))
    ElMessage.success('公告排序已保存')
    sortDialogVisible.value = false
    await loadData()
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '公告排序保存失败')
  } finally {
    sortSubmitting.value = false
  }
}

watch(keyword, () => {
  current.value = 1
})

watch(filteredNotices, (list) => {
  const maxPage = Math.max(1, Math.ceil(list.length / pageSize.value))
  if (current.value > maxPage) {
    current.value = maxPage
  }
})

watch(pageSize, () => {
  current.value = 1
})

onMounted(() => {
  void loadData().catch((error) => {
    ElMessage.error(error instanceof Error ? error.message : '公告管理页面初始化失败')
  })
})
</script>

<template>
  <div class="notices-page">
    <section class="notices-hero">
      <div class="hero-copy">
        <p class="hero-kicker">System Management</p>
        <h1>公告管理</h1>
        <span>在这里可以配置公告，包括添加、删除、编辑、显隐切换与排序维护。</span>
      </div>

      <div class="hero-stats">
        <article v-for="item in heroStats" :key="item.label">
          <span>{{ item.label }}</span>
          <strong>{{ item.value }}</strong>
        </article>
      </div>
    </section>

    <section class="table-shell">
      <header class="table-toolbar">
        <div class="toolbar-left">
          <ElButton type="primary" @click="openCreateDialog">
            <ElIcon><Plus /></ElIcon>
            添加公告
          </ElButton>

          <ElInput
            v-model="keyword"
            clearable
            placeholder="搜索公告标题..."
            class="toolbar-search"
          >
            <template #prefix>
              <ElIcon><Search /></ElIcon>
            </template>
          </ElInput>
        </div>

        <ElButton @click="openSortEditor">编辑排序</ElButton>
      </header>

      <ElTable
        :data="visibleNotices"
        v-loading="loading"
        class="notices-table"
        row-key="id"
        empty-text="当前筛选条件下暂无公告"
      >
        <ElTableColumn prop="id" label="ID" width="88" />
        <ElTableColumn label="显示状态" width="112">
          <template #default="{ row }">
            <ElSwitch
              :model-value="Boolean(row.show)"
              :loading="isToggleLoading(row.id)"
              @change="handleToggle(row, $event)"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn label="标题" min-width="560">
          <template #default="{ row }">
            <div class="title-cell">
              <div class="title-main">
                <strong>{{ row.title }}</strong>
                <div class="title-tags">
                  <ElTag v-if="row.popup" type="warning" effect="plain" round>
                    弹窗公告
                  </ElTag>
                  <ElTag
                    v-for="tag in row.tags ?? []"
                    :key="`${row.id}-${tag}`"
                    effect="plain"
                    round
                  >
                    {{ tag }}
                  </ElTag>
                </div>
              </div>
              <span>{{ summarizeNoticeContent(row.content) }}</span>
              <small>最近更新 {{ formatDateTime(row.updated_at) }}</small>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn label="操作" width="110" fixed="right">
          <template #default="{ row }">
            <div class="action-group">
              <ElButton text class="action-btn" @click="openEditDialog(row)">
                <ElIcon><EditPen /></ElIcon>
              </ElButton>
              <ElButton text class="action-btn danger-btn" @click="handleDelete(row)">
                <ElIcon><Delete /></ElIcon>
              </ElButton>
            </div>
          </template>
        </ElTableColumn>
      </ElTable>

      <footer class="table-footer">
        <span>已选择 0 项，共 {{ filteredNotices.length }} 项</span>
        <ElPagination
          v-model:current-page="current"
          v-model:page-size="pageSize"
          :page-sizes="[10, 20, 50]"
          layout="sizes, prev, pager, next"
          :total="filteredNotices.length"
          background
        />
      </footer>
    </section>

    <SystemNoticeEditorDialog
      v-model:visible="editorVisible"
      :mode="editorMode"
      :notice="activeNotice"
      @success="() => loadData()"
    />

    <ElDialog
      v-model="sortDialogVisible"
      width="min(640px, calc(100vw - 32px))"
      title="编辑排序"
      class="sort-dialog"
    >
      <div class="sort-shell">
        <p class="sort-copy">按照当前展示顺序调整公告排序，保存后会同步到后台 `/notice/sort`。</p>

        <div class="sort-list">
          <article
            v-for="(item, index) in sortDraft"
            :key="item.id"
            class="sort-item"
          >
            <div class="sort-item__main">
              <span class="sort-index">{{ index + 1 }}</span>
              <div class="sort-meta">
                <strong>{{ item.title }}</strong>
                <span>{{ summarizeNoticeContent(item.content, 56) }}</span>
              </div>
            </div>

            <div class="sort-actions">
              <ElButton :disabled="index === 0" @click="moveDraft(index, -1)">
                <ElIcon><ArrowUp /></ElIcon>
                上移
              </ElButton>
              <ElButton :disabled="index === sortDraft.length - 1" @click="moveDraft(index, 1)">
                <ElIcon><ArrowDown /></ElIcon>
                下移
              </ElButton>
            </div>
          </article>
        </div>
      </div>

      <template #footer>
        <div class="sort-footer">
          <ElButton @click="sortDialogVisible = false">取消</ElButton>
          <ElButton type="primary" :loading="sortSubmitting" @click="submitSort">
            保存排序
          </ElButton>
        </div>
      </template>
    </ElDialog>
  </div>
</template>

<style scoped lang="scss" src="./SystemNoticesView.scss"></style>
