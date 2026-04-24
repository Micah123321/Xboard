<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  Connection,
  Delete,
  EditPen,
  Plus,
  RefreshRight,
  Search,
} from '@element-plus/icons-vue'
import {
  deleteNodeRoute,
  fetchNodeRoutes,
  fetchNodes,
} from '@/api/admin'
import type { AdminNodeItem, AdminNodeRouteItem } from '@/types/api'
import NodeRouteEditorDialog from './NodeRouteEditorDialog.vue'
import {
  buildNodeRouteReferenceMap,
  countReferencedNodeRoutes,
  filterNodeRoutes,
  formatNodeRouteActionValue,
  getNodeRouteActionMeta,
  normalizeNodeRoute,
} from '@/utils/routes'

type DialogMode = 'create' | 'edit'

const loading = ref(false)
const errorMessage = ref('')
const editorVisible = ref(false)
const editorMode = ref<DialogMode>('create')
const activeRoute = ref<AdminNodeRouteItem | null>(null)
const deletingId = ref<number | null>(null)
const keyword = ref('')
const current = ref(1)
const pageSize = ref(10)

const routes = ref<AdminNodeRouteItem[]>([])
const nodes = ref<AdminNodeItem[]>([])

const referenceMap = computed(() => buildNodeRouteReferenceMap(nodes.value))
const filteredRoutes = computed(() => filterNodeRoutes(routes.value, keyword.value, referenceMap.value))
const visibleRoutes = computed(() => {
  const start = (current.value - 1) * pageSize.value
  return filteredRoutes.value.slice(start, start + pageSize.value)
})

const heroStats = computed(() => [
  { label: '路由总数', value: String(routes.value.length) },
  { label: '禁止访问', value: String(routes.value.filter((item) => item.action === 'block').length) },
  { label: 'DNS 解析', value: String(routes.value.filter((item) => item.action === 'dns').length) },
  { label: '已被引用', value: String(countReferencedNodeRoutes(routes.value, referenceMap.value)) },
])

const hasActiveFilters = computed(() => keyword.value.trim() !== '')

function isDeleting(id: number): boolean {
  return deletingId.value === id
}

async function loadData() {
  loading.value = true
  errorMessage.value = ''

  try {
    const [routeResult, nodeResult] = await Promise.all([
      fetchNodeRoutes(),
      fetchNodes(),
    ])

    routes.value = (routeResult.data ?? [])
      .map((route) => normalizeNodeRoute(route))
      .sort((a, b) => a.id - b.id)
    nodes.value = nodeResult.data ?? []
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '路由数据加载失败'
  } finally {
    loading.value = false
  }
}

function openCreateDialog() {
  editorMode.value = 'create'
  activeRoute.value = null
  editorVisible.value = true
}

function openEditDialog(route: AdminNodeRouteItem) {
  editorMode.value = 'edit'
  activeRoute.value = route
  editorVisible.value = true
}

function handleReset() {
  keyword.value = ''
}

async function handleDelete(route: AdminNodeRouteItem) {
  deletingId.value = route.id
  try {
    await ElMessageBox.confirm(`删除路由「${route.remarks}」后无法恢复，确认继续吗？`, '删除路由', {
      type: 'warning',
    })
    await deleteNodeRoute(route.id)
    ElMessage.success('路由已删除')
    await loadData()
  } catch (error) {
    if (error === 'cancel' || error === 'close') {
      return
    }
    ElMessage.error(error instanceof Error ? error.message : '路由删除失败')
  } finally {
    deletingId.value = null
  }
}

watch([keyword, pageSize], () => {
  current.value = 1
})

watch(filteredRoutes, (list) => {
  const maxPage = Math.max(1, Math.ceil(list.length / pageSize.value))
  if (current.value > maxPage) {
    current.value = maxPage
  }
})

onMounted(() => {
  void loadData().catch((error) => {
    ElMessage.error(error instanceof Error ? error.message : '路由管理页面初始化失败')
  })
})
</script>

<template>
  <div class="node-routes-page">
    <section class="node-routes-hero">
      <div class="hero-copy">
        <p class="hero-kicker">Node Routes</p>
        <h1>路由管理</h1>
        <span>管理所有路由规则，包括添加、删除、编辑与节点引用摘要查看。</span>
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
            添加路由
          </ElButton>

          <ElInput
            v-model="keyword"
            clearable
            placeholder="搜索路由..."
            class="toolbar-search"
          >
            <template #prefix>
              <ElIcon><Search /></ElIcon>
            </template>
          </ElInput>
        </div>
      </header>

      <ElAlert
        v-if="errorMessage"
        type="error"
        show-icon
        :closable="false"
        class="table-alert"
        :title="errorMessage"
      >
        <template #default>
          <ElButton text @click="loadData">重新加载</ElButton>
        </template>
      </ElAlert>

      <ElTable
        :data="visibleRoutes"
        v-loading="loading"
        class="routes-table"
        row-key="id"
        empty-text="当前筛选条件下暂无路由"
      >
        <ElTableColumn label="组ID" width="108">
          <template #default="{ row }">
            <ElTag round effect="plain" class="id-tag">
              {{ row.id }}
            </ElTag>
          </template>
        </ElTableColumn>

        <ElTableColumn label="备注" min-width="320">
          <template #default="{ row }">
            <div class="remark-cell">
              <strong>{{ row.remarks }}</strong>
              <span v-if="referenceMap[row.id]?.count">
                引用 {{ referenceMap[row.id].count }} 个节点 · {{ referenceMap[row.id].preview }}
              </span>
              <span v-else>未被节点引用</span>
            </div>
          </template>
        </ElTableColumn>

        <ElTableColumn label="动作值" min-width="260">
          <template #default="{ row }">
            <div class="value-cell">
              <strong>{{ formatNodeRouteActionValue(row) }}</strong>
              <span>匹配 {{ row.match.length }} 条规则</span>
            </div>
          </template>
        </ElTableColumn>

        <ElTableColumn label="动作" width="190">
          <template #default="{ row }">
            <ElTag
              round
              effect="plain"
              :type="getNodeRouteActionMeta(row.action).tagType"
              class="action-tag"
              :class="`action-tag--${row.action}`"
            >
              {{ getNodeRouteActionMeta(row.action).label }}
            </ElTag>
          </template>
        </ElTableColumn>

        <ElTableColumn label="操作" width="110" fixed="right">
          <template #default="{ row }">
            <div class="action-group">
              <ElButton text class="action-btn" @click="openEditDialog(row)">
                <ElIcon><EditPen /></ElIcon>
              </ElButton>
              <ElButton
                text
                class="action-btn danger-btn"
                :loading="isDeleting(row.id)"
                @click="handleDelete(row)"
              >
                <ElIcon><Delete /></ElIcon>
              </ElButton>
            </div>
          </template>
        </ElTableColumn>

        <template #empty>
          <div class="table-empty">
            <ElEmpty
              :description="hasActiveFilters ? '当前筛选条件下暂无路由。' : '暂无路由数据。'"
            >
              <ElButton v-if="hasActiveFilters" @click="handleReset">清空筛选</ElButton>
              <ElButton v-else @click="loadData">
                <ElIcon><RefreshRight /></ElIcon>
                重新加载
              </ElButton>
            </ElEmpty>
          </div>
        </template>
      </ElTable>

      <footer class="table-footer">
        <span>已选择 0 项，共 {{ filteredRoutes.length }} 项</span>
        <div class="footer-right">
          <div class="footer-hint">
            <ElIcon><Connection /></ElIcon>
            <span>节点引用摘要基于当前节点 `route_ids` 实时推导。</span>
          </div>
          <ElPagination
            v-model:current-page="current"
            v-model:page-size="pageSize"
            :page-sizes="[10, 20, 50]"
            layout="sizes, prev, pager, next"
            :total="filteredRoutes.length"
            background
          />
        </div>
      </footer>
    </section>

    <NodeRouteEditorDialog
      v-model:visible="editorVisible"
      :mode="editorMode"
      :route="activeRoute"
      @success="() => loadData()"
    />
  </div>
</template>

<style scoped lang="scss" src="./NodeRoutesView.scss"></style>
