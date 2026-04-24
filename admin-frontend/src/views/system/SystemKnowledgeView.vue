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
  deleteKnowledge,
  getKnowledgeById,
  getKnowledgeCategories,
  getKnowledges,
  sortKnowledges,
  toggleKnowledgeVisibility,
} from '@/api/admin'
import type { AdminKnowledgeDetail, AdminKnowledgeListItem } from '@/types/api'
import { formatDateTime } from '@/utils/dashboard'
import {
  countVisibleKnowledges,
  filterKnowledges,
  getKnowledgeCategoryLabel,
  moveKnowledgeOrder,
  normalizeKnowledgeCategories,
  normalizeKnowledgeItem,
} from '@/utils/knowledge'
import KnowledgeEditorDialog from './KnowledgeEditorDialog.vue'

type EditorMode = 'create' | 'edit'

const loading = ref(false)
const sortSubmitting = ref(false)
const editorVisible = ref(false)
const editorMode = ref<EditorMode>('create')
const activeKnowledge = ref<AdminKnowledgeDetail | null>(null)
const sortDialogVisible = ref(false)
const errorMessage = ref('')

const keyword = ref('')
const categoryFilter = ref('')
const current = ref(1)
const pageSize = ref(10)

const knowledges = ref<AdminKnowledgeListItem[]>([])
const categories = ref<string[]>([])
const sortDraft = ref<AdminKnowledgeListItem[]>([])
const toggleLoadingMap = ref<Record<number, boolean>>({})
const detailLoadingId = ref<number | null>(null)

const filteredKnowledges = computed(() => filterKnowledges(
  knowledges.value,
  keyword.value,
  categoryFilter.value,
))
const visibleKnowledges = computed(() => {
  const start = (current.value - 1) * pageSize.value
  return filteredKnowledges.value.slice(start, start + pageSize.value)
})
const heroStats = computed(() => [
  { label: '知识总数', value: String(knowledges.value.length) },
  { label: '显示中', value: String(countVisibleKnowledges(knowledges.value)) },
  { label: '隐藏中', value: String(Math.max(knowledges.value.length - countVisibleKnowledges(knowledges.value), 0)) },
  { label: '分类数', value: String(categories.value.length) },
])

function normalizeKnowledgeDetail(detail: AdminKnowledgeDetail): AdminKnowledgeDetail {
  return {
    ...detail,
    category: typeof detail.category === 'string' ? detail.category.trim() : detail.category,
    show: Boolean(detail.show),
    body: detail.body || '',
    language: detail.language || 'zh-CN',
  }
}

function isToggleLoading(id: number): boolean {
  return Boolean(toggleLoadingMap.value[id])
}

function isDetailLoading(id: number): boolean {
  return detailLoadingId.value === id
}

async function loadData() {
  loading.value = true
  errorMessage.value = ''

  try {
    const knowledgeResponse = await getKnowledges()
    const items = (knowledgeResponse.data ?? []).map((item) => normalizeKnowledgeItem(item))
    knowledges.value = items

    try {
      const categoryResponse = await getKnowledgeCategories()
      categories.value = normalizeKnowledgeCategories(categoryResponse.data ?? [], items)
    } catch (error) {
      categories.value = normalizeKnowledgeCategories([], items)
      ElMessage.warning(error instanceof Error ? error.message : '知识分类加载失败，已回退列表分类')
    }
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '知识库管理页面初始化失败'
  } finally {
    loading.value = false
  }
}

function openCreateDialog() {
  editorMode.value = 'create'
  activeKnowledge.value = null
  editorVisible.value = true
}

async function openEditDialog(knowledge: AdminKnowledgeListItem) {
  detailLoadingId.value = knowledge.id
  try {
    const response = await getKnowledgeById(knowledge.id)
    activeKnowledge.value = normalizeKnowledgeDetail(response.data)
    editorMode.value = 'edit'
    editorVisible.value = true
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '知识详情读取失败')
  } finally {
    detailLoadingId.value = null
  }
}

async function handleToggle(knowledge: AdminKnowledgeListItem, nextValue: boolean | string | number) {
  const normalizedNextValue = Boolean(nextValue)
  if (Boolean(knowledge.show) === normalizedNextValue) {
    return
  }

  toggleLoadingMap.value[knowledge.id] = true
  try {
    await toggleKnowledgeVisibility(knowledge.id)
    knowledge.show = normalizedNextValue
    ElMessage.success('知识显示状态已更新')
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '知识显示状态更新失败')
  } finally {
    toggleLoadingMap.value[knowledge.id] = false
  }
}

async function handleDelete(knowledge: AdminKnowledgeListItem) {
  try {
    await ElMessageBox.confirm(`删除知识「${knowledge.title}」后无法恢复，确认继续吗？`, '删除知识', {
      type: 'warning',
    })
    await deleteKnowledge(knowledge.id)
    ElMessage.success('知识已删除')
    await loadData()
  } catch (error) {
    if (error === 'cancel' || error === 'close') {
      return
    }
    ElMessage.error(error instanceof Error ? error.message : '知识删除失败')
  }
}

function openSortEditor() {
  sortDraft.value = knowledges.value.map((item) => ({ ...item }))
  sortDialogVisible.value = true
}

function moveDraft(index: number, direction: -1 | 1) {
  sortDraft.value = moveKnowledgeOrder(sortDraft.value, index, direction)
}

async function submitSort() {
  sortSubmitting.value = true
  try {
    await sortKnowledges(sortDraft.value.map((item) => item.id))
    ElMessage.success('知识排序已保存')
    sortDialogVisible.value = false
    await loadData()
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '知识排序保存失败')
  } finally {
    sortSubmitting.value = false
  }
}

watch([keyword, categoryFilter], () => {
  current.value = 1
})

watch(filteredKnowledges, (list) => {
  const maxPage = Math.max(1, Math.ceil(list.length / pageSize.value))
  if (current.value > maxPage) {
    current.value = maxPage
  }
})

watch(pageSize, () => {
  current.value = 1
})

onMounted(() => {
  void loadData()
})
</script>

<template>
  <div class="knowledge-page">
    <section class="knowledge-hero">
      <div class="knowledge-copy">
        <h1>知识库管理</h1>
        <p>在这里可以配置知识库，包括添加、删除、编辑、显隐与排序等操作。</p>
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
            添加知识
          </ElButton>

          <ElInput
            v-model="keyword"
            clearable
            placeholder="搜索知识..."
            class="toolbar-search"
          >
            <template #prefix>
              <ElIcon><Search /></ElIcon>
            </template>
          </ElInput>

          <ElSelect
            v-model="categoryFilter"
            clearable
            placeholder="分类"
            class="toolbar-filter"
          >
            <ElOption
              v-for="item in categories"
              :key="item"
              :label="item"
              :value="item"
            />
          </ElSelect>
        </div>

        <div class="toolbar-right">
          <ElButton @click="openSortEditor">编辑排序</ElButton>
        </div>
      </header>

      <ElAlert
        v-if="errorMessage"
        type="error"
        show-icon
        :closable="false"
        :title="errorMessage"
        class="table-alert"
      >
        <template #default>
          <ElButton size="small" @click="loadData">重新加载</ElButton>
        </template>
      </ElAlert>

      <ElTable
        :data="visibleKnowledges"
        v-loading="loading"
        class="knowledge-table"
        row-key="id"
        empty-text="当前筛选条件下暂无知识"
      >
        <ElTableColumn prop="id" label="ID" width="88" />
        <ElTableColumn label="状态" width="110">
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
              <strong>{{ row.title }}</strong>
              <small>最近更新 {{ formatDateTime(row.updated_at) }}</small>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn label="分类" min-width="140">
          <template #default="{ row }">
            <ElTag effect="plain" round>{{ getKnowledgeCategoryLabel(row.category) }}</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="操作" width="120" fixed="right">
          <template #default="{ row }">
            <div class="action-group">
              <ElButton
                text
                class="action-btn"
                :loading="isDetailLoading(row.id)"
                @click="openEditDialog(row)"
              >
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
        <span>已选择 0 项，共 {{ filteredKnowledges.length }} 项</span>
        <ElPagination
          v-model:current-page="current"
          v-model:page-size="pageSize"
          :page-sizes="[10, 20, 50]"
          layout="sizes, prev, pager, next"
          :total="filteredKnowledges.length"
          background
        />
      </footer>
    </section>

    <KnowledgeEditorDialog
      v-model:visible="editorVisible"
      :mode="editorMode"
      :knowledge="activeKnowledge"
      :categories="categories"
      @success="() => loadData()"
    />

    <ElDialog
      v-model="sortDialogVisible"
      width="min(680px, calc(100vw - 32px))"
      title="编辑排序"
      class="sort-dialog"
    >
      <div class="sort-shell">
        <p class="sort-copy">按照当前展示顺序调整知识条目排序，保存后会同步到后台 `/knowledge/sort`。</p>

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
                <span>{{ getKnowledgeCategoryLabel(item.category) }}</span>
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

<style scoped lang="scss" src="./SystemKnowledgeView.scss"></style>
