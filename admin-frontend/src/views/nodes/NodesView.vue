<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { TableInstance } from 'element-plus'
import {
  Connection,
  Delete,
  MoreFilled,
  Plus,
  RefreshRight,
  Search,
  User,
} from '@element-plus/icons-vue'
import {
  batchDeleteNodes,
  batchUpdateNodes,
  checkNodeGfw,
  copyNode,
  deleteNode,
  fetchNodes,
  fetchNodeRoutes,
  getServerGroups,
  sortNodes,
  updateNode,
} from '@/api/admin'
import type {
  AdminNodeBatchUpdatePayload,
  AdminNodeItem,
  AdminNodeRouteItem,
  AdminServerGroupItem,
} from '@/types/api'
import NodeBatchEditDialog from './NodeBatchEditDialog.vue'
import NodeEditorDialog from './NodeEditorDialog.vue'
import NodeSortDialog from './NodeSortDialog.vue'
import {
  buildNodeTypeOptions,
  countAutoGfwCheckNodes,
  countAutoOnlineNodes,
  countOnlineNodes,
  countVisibleNodes,
  filterNodes,
  formatNodeRate,
  getNodeGfwMeta,
  getNodeGfwTooltip,
  getNodeAddress,
  getNodeGroupNames,
  getNodeIdLabel,
  getNodeStatusMeta,
  getNodeTrafficLimitDetail,
  getNodeTrafficDetails,
  getNodeTypeLabel,
  type NodeRelationFilter,
  type NodeGfwFilter,
  type NodeStatusFilter,
  type NodeVisibilityFilter,
} from '@/utils/nodes'
import { sortNodesByOrder } from '@/utils/nodeEditor'

type NodeAction = 'edit' | 'copy' | 'pin-top' | 'delete' | 'check-gfw'
type NodeDialogMode = 'create' | 'edit'
type NodeBatchEditPayload = Omit<AdminNodeBatchUpdatePayload, 'ids'>

const route = useRoute()
const router = useRouter()
const tableRef = ref<TableInstance>()
const loading = ref(false)
const errorMessage = ref('')
const nodes = ref<AdminNodeItem[]>([])
const groups = ref<AdminServerGroupItem[]>([])
const routes = ref<AdminNodeRouteItem[]>([])
const keyword = ref('')
const typeFilter = ref('all')
const groupFilter = ref('all')
const statusFilter = ref<NodeStatusFilter>('all')
const visibilityFilter = ref<NodeVisibilityFilter>('all')
const relationFilter = ref<NodeRelationFilter>('all')
const gfwFilter = ref<NodeGfwFilter>('all')
const currentPage = ref(1)
const pageSize = ref(20)
const selectedNodeIds = ref<number[]>([])
const syncingSelection = ref(false)
const switchingIds = ref<number[]>([])
const autoSwitchingIds = ref<number[]>([])
const gfwSwitchingIds = ref<number[]>([])
const workingIds = ref<number[]>([])
const editorVisible = ref(false)
const editorMode = ref<NodeDialogMode>('create')
const activeNode = ref<AdminNodeItem | null>(null)
const sortDialogVisible = ref(false)
const batchEditVisible = ref(false)
const batchSubmitting = ref(false)
const batchDeleting = ref(false)
const batchGfwChecking = ref(false)
const currentTimestamp = ref(Math.floor(Date.now() / 1000))
let autoCheckCountdownTimer: number | undefined

const GFW_AUTO_CHECK_INTERVAL_MINUTES = 30

const filteredNodes = computed(() => sortNodesByOrder(filterNodes(
  nodes.value,
  keyword.value,
  typeFilter.value,
  groupFilter.value,
  statusFilter.value,
  visibilityFilter.value,
  relationFilter.value,
  gfwFilter.value,
)))

const paginatedNodes = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  return filteredNodes.value.slice(start, start + pageSize.value)
})

const selectedNodes = computed(() => nodes.value.filter((node) => selectedNodeIds.value.includes(node.id)))
const typeOptions = computed(() => buildNodeTypeOptions(nodes.value))
const hasSelectedNodes = computed(() => selectedNodes.value.length > 0)
const hasActiveFilters = computed(() => (
  keyword.value !== ''
  || typeFilter.value !== 'all'
  || groupFilter.value !== 'all'
  || statusFilter.value !== 'all'
  || visibilityFilter.value !== 'all'
  || relationFilter.value !== 'all'
  || gfwFilter.value !== 'all'
))

const summaryCards = computed(() => [
  { label: '节点总数', value: String(nodes.value.length) },
  { label: '在线节点', value: String(countOnlineNodes(nodes.value)) },
  { label: '显示中', value: String(countVisibleNodes(nodes.value)) },
  { label: '自动上线', value: String(countAutoOnlineNodes(nodes.value)) },
  { label: '自动墙检', value: String(countAutoGfwCheckNodes(nodes.value)) },
  { label: '已勾选', value: String(selectedNodes.value.length) },
])

const batchTargetLabel = computed(() => (hasSelectedNodes.value ? `当前已选 ${selectedNodes.value.length} 个节点` : ''))
const autoGfwParentNodes = computed(() => nodes.value.filter((node) => !node.parent_id && node.gfw_check_enabled !== false))
const hasRunningAutoGfwTask = computed(() => autoGfwParentNodes.value.some((node) => {
  const status = String(node.gfw_check?.status ?? '').toLowerCase()
  return status === 'pending' || status === 'checking'
}))

const nextAutoGfwCheckHint = computed(() => {
  if (autoGfwParentNodes.value.length === 0) {
    return '未开启父节点自动墙检'
  }

  if (hasRunningAutoGfwTask.value) {
    return '本轮自动墙检进行中'
  }

  const nextTimestamp = getNextAutoGfwCheckTimestamp(currentTimestamp.value)
  return `下次自动墙检：${formatCountdown(nextTimestamp - currentTimestamp.value)}（${formatClockTime(nextTimestamp)}）`
})

function getRouteGroupQuery(): string {
  const rawValue = route.query.group
  if (Array.isArray(rawValue)) {
    return String(rawValue[0] ?? '')
  }
  return String(rawValue ?? '')
}

function applyRouteGroupFilter() {
  const groupValue = getRouteGroupQuery().trim()
  if (!groupValue) {
    return
  }

  const exists = groups.value.some((group) => String(group.id) === groupValue)
  groupFilter.value = exists ? groupValue : 'all'
  currentPage.value = 1
}

function markPending(list: typeof switchingIds, id: number, pending: boolean) {
  if (pending) {
    if (!list.value.includes(id)) {
      list.value = [...list.value, id]
    }
    return
  }

  list.value = list.value.filter((item) => item !== id)
}

function isSwitching(id: number): boolean {
  return switchingIds.value.includes(id)
}

function isAutoSwitching(id: number): boolean {
  return autoSwitchingIds.value.includes(id)
}

function isGfwSwitching(id: number): boolean {
  return gfwSwitchingIds.value.includes(id)
}

function isWorking(id: number): boolean {
  return workingIds.value.includes(id)
}

function getNextAutoGfwCheckTimestamp(timestamp: number): number {
  const nextRun = new Date(timestamp * 1000)
  const minutes = nextRun.getMinutes()
  nextRun.setSeconds(0, 0)

  if (minutes < GFW_AUTO_CHECK_INTERVAL_MINUTES) {
    nextRun.setMinutes(GFW_AUTO_CHECK_INTERVAL_MINUTES)
  } else {
    nextRun.setHours(nextRun.getHours() + 1)
    nextRun.setMinutes(0)
  }

  return Math.floor(nextRun.getTime() / 1000)
}

function formatCountdown(seconds: number): string {
  const minutes = Math.max(1, Math.ceil(seconds / 60))
  return `${minutes} 分钟后`
}

function formatClockTime(timestamp: number): string {
  return new Date(timestamp * 1000).toLocaleTimeString([], {
    hour: '2-digit',
    minute: '2-digit',
  })
}

function openCreateEditor() {
  editorMode.value = 'create'
  activeNode.value = null
  editorVisible.value = true
}

function openEditEditor(node: AdminNodeItem) {
  editorMode.value = 'edit'
  activeNode.value = node
  editorVisible.value = true
}

function openSortEditor() {
  sortDialogVisible.value = true
}

function setCurrentPageInRange() {
  const totalPages = Math.max(1, Math.ceil(filteredNodes.value.length / pageSize.value))
  if (currentPage.value > totalPages) {
    currentPage.value = totalPages
  }
}

function pruneSelection() {
  const validIds = new Set(nodes.value.map((node) => node.id))
  selectedNodeIds.value = selectedNodeIds.value.filter((id) => validIds.has(id))
}

function syncTableSelection() {
  nextTick(() => {
    const table = tableRef.value
    if (!table) {
      return
    }

    syncingSelection.value = true

    try {
      table.clearSelection()
      paginatedNodes.value.forEach((node) => {
        if (selectedNodeIds.value.includes(node.id)) {
          table.toggleRowSelection(node, true)
        }
      })
    } finally {
      nextTick(() => {
        syncingSelection.value = false
      })
    }
  })
}

async function loadNodeBoard() {
  loading.value = true
  errorMessage.value = ''

  try {
    const [nodesResponse, groupsResponse, routesResponse] = await Promise.all([
      fetchNodes(),
      getServerGroups(),
      fetchNodeRoutes(),
    ])

    nodes.value = sortNodesByOrder(nodesResponse.data ?? [])
    groups.value = groupsResponse.data ?? []
    routes.value = routesResponse.data ?? []
    pruneSelection()
    applyRouteGroupFilter()
    setCurrentPageInRange()
    syncTableSelection()
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '节点数据加载失败'
  } finally {
    loading.value = false
  }
}

function handleReset() {
  keyword.value = ''
  typeFilter.value = 'all'
  groupFilter.value = 'all'
  statusFilter.value = 'all'
  visibilityFilter.value = 'all'
  relationFilter.value = 'all'
  gfwFilter.value = 'all'
  currentPage.value = 1
}

function openNodeGroupManagement() {
  void router.push('/node-groups')
}

function handleSelectionChange(selection: AdminNodeItem[]) {
  if (syncingSelection.value) {
    return
  }

  const currentPageIds = paginatedNodes.value.map((item) => item.id)
  const selectionIds = selection.map((item) => item.id)
  const persistedIds = selectedNodeIds.value.filter((id) => !currentPageIds.includes(id))
  selectedNodeIds.value = [...new Set([...persistedIds, ...selectionIds])]
}

function clearSelection() {
  selectedNodeIds.value = []
  syncTableSelection()
}

function openBatchEditor() {
  if (!hasSelectedNodes.value) {
    ElMessage.warning('请先勾选需要批量修改的节点')
    return
  }

  batchEditVisible.value = true
}

async function handleBatchSubmit(payload: NodeBatchEditPayload) {
  const updatePayload: AdminNodeBatchUpdatePayload = {
    ids: [...selectedNodeIds.value],
    host: payload.host,
    rate: payload.rate,
    group_ids: payload.group_ids,
    auto_online: payload.auto_online,
    gfw_check_enabled: payload.gfw_check_enabled,
  }

  try {
    await ElMessageBox.confirm(
      `确认批量修改 ${selectedNodeIds.value.length} 个节点吗？本次只会更新已启用的字段。`,
      '批量修改节点',
      { type: 'warning' },
    )

    batchSubmitting.value = true
    await batchUpdateNodes(updatePayload)
    let started = 0
    let skipped = 0
    if (payload.gfw_check_enabled === true) {
      const response = await checkNodeGfw(updatePayload.ids)
      started = response.data?.started?.length ?? 0
      skipped = response.data?.skipped?.length ?? 0
    }
    batchEditVisible.value = false
    clearSelection()
    if (started > 0) {
      ElMessage.success(`已批量更新 ${updatePayload.ids.length} 个节点，并发起 ${started} 个父节点墙检测`)
    } else if (payload.gfw_check_enabled === true && skipped > 0) {
      ElMessage.info('已批量开启墙检测托管，所选父节点已有任务或所选节点为子节点')
    } else {
      ElMessage.success(`已批量更新 ${updatePayload.ids.length} 个节点`)
    }
    await loadNodeBoard()
  } catch (error) {
    if (error === 'cancel' || error === 'close') {
      return
    }

    ElMessage.error(error instanceof Error ? error.message : '批量修改失败')
  } finally {
    batchSubmitting.value = false
  }
}

async function handleBatchDelete() {
  if (!hasSelectedNodes.value) {
    ElMessage.warning('请先勾选需要批量删除的节点')
    return
  }

  const deleteCount = selectedNodes.value.length

  try {
    await ElMessageBox.confirm(
      `确认批量删除 ${deleteCount} 个节点吗？此操作不可恢复。`,
      '批量删除节点',
      {
        type: 'warning',
        confirmButtonText: '确认删除',
        cancelButtonText: '取消',
      },
    )

    batchDeleting.value = true
    await batchDeleteNodes([...selectedNodeIds.value])
    clearSelection()
    ElMessage.success(`已批量删除 ${deleteCount} 个节点`)
    await loadNodeBoard()
  } catch (error) {
    if (error === 'cancel' || error === 'close') {
      return
    }

    ElMessage.error(error instanceof Error ? error.message : '批量删除失败')
  } finally {
    batchDeleting.value = false
  }
}

async function handleCheckGfw(ids: number[], label: string) {
  if (ids.length === 0) {
    ElMessage.warning('请先选择需要检测的节点')
    return
  }

  try {
    const response = await checkNodeGfw(ids)
    const started = response.data?.started?.length ?? 0
    const skipped = response.data?.skipped?.length ?? 0

    if (started > 0) {
      ElMessage.success(`${label}已发起墙状态检测，${started} 个父节点等待上报`)
    } else if (skipped > 0) {
      const reason = response.data?.skipped?.[0]?.reason
      ElMessage.info(reason || '所选节点暂未发起新的墙状态检测')
    } else {
      ElMessage.info('没有可检测的节点')
    }

    await loadNodeBoard()
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '墙状态检测发起失败')
  }
}

async function handleBatchCheckGfw() {
  if (!hasSelectedNodes.value) {
    ElMessage.warning('请先勾选需要检测的节点')
    return
  }

  batchGfwChecking.value = true
  try {
    await handleCheckGfw([...selectedNodeIds.value], '批量')
  } finally {
    batchGfwChecking.value = false
  }
}

async function handleNodeCheckGfw(node: AdminNodeItem) {
  if (node.parent_id) {
    ElMessage.info('子节点不单独检测，墙状态随父节点显示')
    return
  }

  markPending(workingIds, node.id, true)
  try {
    await handleCheckGfw([node.id], '')
  } finally {
    markPending(workingIds, node.id, false)
  }
}

async function handleToggleShow(node: AdminNodeItem, nextValue: boolean) {
  const previous = Boolean(node.show)
  if (previous === nextValue) {
    return
  }

  node.show = nextValue
  markPending(switchingIds, node.id, true)

  try {
    await updateNode({
      id: node.id,
      show: nextValue ? 1 : 0,
    })
    ElMessage.success(nextValue ? '节点已显示' : '节点已隐藏')
  } catch (error) {
    node.show = previous
    ElMessage.error(error instanceof Error ? error.message : '显隐状态更新失败')
  } finally {
    markPending(switchingIds, node.id, false)
  }
}

async function handleToggleAutoOnline(node: AdminNodeItem, nextValue: boolean) {
  const previous = Boolean(node.auto_online)
  if (previous === nextValue) {
    return
  }

  node.auto_online = nextValue
  markPending(autoSwitchingIds, node.id, true)

  try {
    await updateNode({
      id: node.id,
      auto_online: nextValue,
    })
    ElMessage.success(nextValue ? '已开启自动上线' : '已关闭自动上线')
  } catch (error) {
    node.auto_online = previous
    ElMessage.error(error instanceof Error ? error.message : '自动上线状态更新失败')
  } finally {
    markPending(autoSwitchingIds, node.id, false)
  }
}

async function handleToggleGfwCheck(node: AdminNodeItem, nextValue: boolean) {
  const previous = node.gfw_check_enabled !== false
  if (previous === nextValue) {
    return
  }

  node.gfw_check_enabled = nextValue
  markPending(gfwSwitchingIds, node.id, true)

  try {
    await updateNode({
      id: node.id,
      gfw_check_enabled: nextValue,
    })
    if (nextValue && !node.parent_id) {
      const response = await checkNodeGfw([node.id])
      const started = response.data?.started?.length ?? 0
      if (started > 0) {
        ElMessage.success('已开启墙检测托管，并发起墙状态检测')
      } else {
        const reason = response.data?.skipped?.[0]?.reason
        ElMessage.info(reason || '已开启墙检测托管，已有检测任务等待节点领取或上报')
      }
    } else {
      ElMessage.success(nextValue ? '已开启墙检测托管' : '已关闭墙检测托管')
    }
    await loadNodeBoard()
  } catch (error) {
    node.gfw_check_enabled = previous
    ElMessage.error(error instanceof Error ? error.message : '墙检测托管状态更新失败')
  } finally {
    markPending(gfwSwitchingIds, node.id, false)
  }
}

async function handlePinTop(node: AdminNodeItem) {
  const orderedNodes = sortNodesByOrder(nodes.value)
  if (orderedNodes[0]?.id === node.id) {
    ElMessage.info('当前节点已经在列表顶部')
    return
  }

  markPending(workingIds, node.id, true)

  try {
    const nextOrder = [node, ...orderedNodes.filter((item) => item.id !== node.id)]
    await sortNodes(nextOrder.map((item, index) => ({
      id: item.id,
      order: index + 1,
    })))
    currentPage.value = 1
    ElMessage.success(`已将“${node.name}”置顶`)
    await loadNodeBoard()
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '节点置顶失败')
  } finally {
    markPending(workingIds, node.id, false)
  }
}

async function handleAction(action: NodeAction, node: AdminNodeItem) {
  if (action === 'edit') {
    openEditEditor(node)
    return
  }

  if (action === 'pin-top') {
    await handlePinTop(node)
    return
  }

  if (action === 'check-gfw') {
    await handleNodeCheckGfw(node)
    return
  }

  markPending(workingIds, node.id, true)

  try {
    if (action === 'copy') {
      await copyNode(node.id)
      ElMessage.success('节点已复制')
      await loadNodeBoard()
      return
    }

    await ElMessageBox.confirm(
      `删除节点 “${node.name}” 后无法恢复，确认继续吗？`,
      '删除节点',
      { type: 'warning' },
    )

    await deleteNode(node.id)
    ElMessage.success('节点已删除')
    await loadNodeBoard()
  } catch (error) {
    if (action === 'delete' && (error === 'cancel' || error === 'close')) {
      return
    }

    ElMessage.error(error instanceof Error ? error.message : '节点操作失败')
  } finally {
    markPending(workingIds, node.id, false)
  }
}

onMounted(() => {
  currentTimestamp.value = Math.floor(Date.now() / 1000)
  autoCheckCountdownTimer = window.setInterval(() => {
    currentTimestamp.value = Math.floor(Date.now() / 1000)
  }, 30 * 1000)
  void loadNodeBoard()
})

onBeforeUnmount(() => {
  if (autoCheckCountdownTimer !== undefined) {
    window.clearInterval(autoCheckCountdownTimer)
  }
})

watch(
  () => route.query.group,
  () => {
    applyRouteGroupFilter()
  },
)

watch([keyword, typeFilter, groupFilter, statusFilter, visibilityFilter, relationFilter, gfwFilter], () => {
  currentPage.value = 1
})

watch(pageSize, () => {
  currentPage.value = 1
})

watch(
  () => filteredNodes.value.length,
  () => {
    setCurrentPageInRange()
  },
)

watch(
  () => paginatedNodes.value.map((item) => item.id).join(','),
  () => {
    syncTableSelection()
  },
  { flush: 'post' },
)
</script>

<template>
  <div class="nodes-page">
    <section class="nodes-hero">
      <div class="nodes-copy">
        <p class="nodes-kicker">Nodes</p>
        <h1>节点管理</h1>
        <span>
          现在可以在同一页完成节点筛选、在线 / 离线排查、分页浏览、单行置顶、批量修改与批量删除，以及新增、编辑、显隐和删除等运营动作。
        </span>
      </div>

      <div class="hero-stats">
        <article v-for="item in summaryCards" :key="item.label">
          <span>{{ item.label }}</span>
          <strong>{{ item.value }}</strong>
        </article>
      </div>
    </section>

    <section class="nodes-board">
      <header class="board-toolbar">
        <div class="toolbar-fields">
          <ElButton type="primary" @click="openCreateEditor">
            <ElIcon><Plus /></ElIcon>
            添加节点
          </ElButton>

          <ElInput
            v-model="keyword"
            clearable
            placeholder="搜索节点..."
            class="toolbar-input"
          >
            <template #prefix>
              <ElIcon><Search /></ElIcon>
            </template>
          </ElInput>

          <ElSelect v-model="typeFilter" class="toolbar-select" placeholder="类型">
            <ElOption label="全部类型" value="all" />
            <ElOption
              v-for="option in typeOptions"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            />
          </ElSelect>

          <ElSelect v-model="groupFilter" class="toolbar-select" placeholder="权限组">
            <ElOption label="全部权限组" value="all" />
            <ElOption
              v-for="group in groups"
              :key="group.id"
              :label="group.name"
              :value="String(group.id)"
            />
          </ElSelect>

          <ElSelect v-model="statusFilter" class="toolbar-select" placeholder="状态">
            <ElOption label="全部节点" value="all" />
            <ElOption label="在线节点" value="online" />
            <ElOption label="离线节点" value="offline" />
          </ElSelect>

          <ElSelect v-model="visibilityFilter" class="toolbar-select" placeholder="显隐">
            <ElOption label="全部显隐" value="all" />
            <ElOption label="显示中" value="visible" />
            <ElOption label="已隐藏" value="hidden" />
          </ElSelect>

          <ElSelect v-model="relationFilter" class="toolbar-select" placeholder="节点关系">
            <ElOption label="全部节点" value="all" />
            <ElOption label="父节点" value="parent" />
            <ElOption label="子节点" value="child" />
          </ElSelect>

          <ElSelect v-model="gfwFilter" class="toolbar-select" placeholder="墙状态">
            <ElOption label="全部墙状态" value="all" />
            <ElOption label="正常" value="normal" />
            <ElOption label="疑似被墙" value="blocked" />
            <ElOption label="部分异常" value="partial" />
            <ElOption label="检测失败" value="failed" />
            <ElOption label="等待/检测中" value="checking" />
            <ElOption label="未检测" value="unchecked" />
            <ElOption label="随父节点" value="inherited" />
          </ElSelect>
        </div>

        <div class="toolbar-actions">
          <span v-if="batchTargetLabel" class="scope-hint">{{ batchTargetLabel }}</span>
          <ElButton :disabled="!hasSelectedNodes || batchDeleting" @click="openBatchEditor">批量修改</ElButton>
          <ElButton
            :disabled="!hasSelectedNodes || batchGfwChecking"
            :loading="batchGfwChecking"
            @click="handleBatchCheckGfw"
          >
            <ElIcon><Connection /></ElIcon>
            检测墙状态
          </ElButton>
          <ElButton
            :loading="loading"
            @click="loadNodeBoard"
          >
            <ElIcon><RefreshRight /></ElIcon>
            刷新数据
          </ElButton>
          <ElButton
            type="danger"
            plain
            :disabled="!hasSelectedNodes"
            :loading="batchDeleting"
            @click="handleBatchDelete"
          >
            <ElIcon><Delete /></ElIcon>
            批量删除
          </ElButton>
          <ElButton @click="openNodeGroupManagement">管理权限组</ElButton>
          <ElButton @click="handleReset" :disabled="!hasActiveFilters">
            <ElIcon><RefreshRight /></ElIcon>
            重置筛选
          </ElButton>
          <ElButton @click="openSortEditor">编辑排序</ElButton>
        </div>
      </header>

      <div v-if="hasSelectedNodes" class="selection-summary">
        <span class="selection-summary__label">已勾选 {{ selectedNodes.length }} 个节点</span>
        <ElButton text @click="clearSelection">清空勾选</ElButton>
      </div>

      <ElAlert
        v-if="errorMessage"
        type="error"
        show-icon
        :closable="false"
        class="board-alert"
        :title="errorMessage"
      >
        <template #default>
          <ElButton text @click="loadNodeBoard">重新加载</ElButton>
        </template>
      </ElAlert>

      <ElTable
        ref="tableRef"
        :data="paginatedNodes"
        v-loading="loading"
        row-key="id"
        class="nodes-table"
        @selection-change="handleSelectionChange"
      >
        <ElTableColumn type="selection" width="52" reserve-selection />
        <ElTableColumn label="节点ID" width="132">
          <template #default="{ row }">
            <ElTag
              round
              effect="plain"
              :type="row.parent_id ? 'warning' : 'success'"
              class="id-tag"
            >
              {{ getNodeIdLabel(row) }}
            </ElTag>
          </template>
        </ElTableColumn>

        <ElTableColumn label="显隐" width="96">
          <template #default="{ row }">
            <div
              class="switch-shell"
              :style="{ '--node-switch-color': row.parent_id ? '#7c5cff' : '#22c55e' }"
            >
              <ElSwitch
                :model-value="Boolean(row.show)"
                :loading="isSwitching(row.id)"
                :disabled="Boolean(row.auto_online) || (Boolean(row.gfw_auto_hidden) && row.gfw_check_enabled !== false)"
                @change="(value) => handleToggleShow(row, Boolean(value))"
              />
            </div>
          </template>
        </ElTableColumn>

        <ElTableColumn label="墙检测" width="118">
          <template #default="{ row }">
            <ElTooltip
              :content="row.parent_id ? '子节点不单独检测；此开关只控制是否随父节点自动隐藏或恢复。' : '关闭后不参与自动墙检测和墙状态自动显隐。'"
              placement="top"
            >
              <div class="switch-shell switch-shell--gfw">
                <ElSwitch
                  :model-value="row.gfw_check_enabled !== false"
                  :loading="isGfwSwitching(row.id)"
                  @change="(value) => handleToggleGfwCheck(row, Boolean(value))"
                />
              </div>
            </ElTooltip>
          </template>
        </ElTableColumn>

        <ElTableColumn label="自动上线" width="118">
          <template #default="{ row }">
            <div class="switch-shell switch-shell--auto">
              <ElSwitch
                :model-value="Boolean(row.auto_online)"
                :loading="isAutoSwitching(row.id)"
                @change="(value) => handleToggleAutoOnline(row, Boolean(value))"
              />
            </div>
          </template>
        </ElTableColumn>

        <ElTableColumn label="节点" min-width="280">
          <template #default="{ row }">
            <div class="node-cell">
              <ElPopover
                placement="right-start"
                trigger="hover"
                popper-class="node-traffic-popover"
                :width="360"
              >
                <template #reference>
                  <button class="node-cell__main node-name-trigger" type="button">
                    <span class="node-dot" :class="getNodeStatusMeta(row).dotClass" />
                    <strong>{{ row.name }}</strong>
                  </button>
                </template>
                <div class="node-traffic-card">
                  <header class="node-traffic-card__header">
                    <span>流量统计</span>
                    <strong>{{ row.name }}</strong>
                  </header>
                  <article
                    v-for="traffic in getNodeTrafficDetails(row)"
                    :key="`${row.id}-${traffic.key}`"
                    class="node-traffic-row"
                  >
                    <div class="node-traffic-row__summary">
                      <span>{{ traffic.label }}</span>
                      <strong>{{ traffic.total }}</strong>
                    </div>
                    <div class="node-traffic-row__split">
                      <span>上行 {{ traffic.upload }}</span>
                      <span>下行 {{ traffic.download }}</span>
                    </div>
                  </article>
                  <article
                    v-if="getNodeTrafficLimitDetail(row).enabled"
                    class="node-traffic-row node-traffic-row--limit"
                  >
                    <div class="node-traffic-row__summary">
                      <span>月额度</span>
                      <strong>{{ getNodeTrafficLimitDetail(row).used }} / {{ getNodeTrafficLimitDetail(row).limit }}</strong>
                    </div>
                    <div class="node-traffic-limit-bar">
                      <span :style="{ width: `${getNodeTrafficLimitDetail(row).percent}%` }" />
                    </div>
                    <div class="node-traffic-row__split">
                      <span>{{ getNodeTrafficLimitDetail(row).statusLabel }}</span>
                      <span>{{ getNodeTrafficLimitDetail(row).nextReset }}</span>
                    </div>
                  </article>
                </div>
              </ElPopover>
              <div class="node-cell__sub">
                <ElTag round effect="plain" :type="getNodeStatusMeta(row).tagType">
                  {{ getNodeStatusMeta(row).label }}
                </ElTag>
                <ElTag
                  v-if="getNodeTrafficLimitDetail(row).enabled"
                  round
                  effect="plain"
                  :type="getNodeTrafficLimitDetail(row).tagType"
                >
                  {{ getNodeTrafficLimitDetail(row).statusLabel }}
                </ElTag>
                <ElTag
                  v-if="row.auto_online"
                  round
                  effect="plain"
                  type="primary"
                  class="auto-online-tag"
                >
                  自动上线
                </ElTag>
                <ElTag
                  v-if="row.gfw_check_enabled !== false"
                  round
                  effect="plain"
                  type="primary"
                  class="auto-online-tag"
                >
                  墙检测
                </ElTag>
                <ElTag
                  v-if="row.gfw_auto_hidden"
                  round
                  effect="plain"
                  type="danger"
                  class="auto-online-tag"
                >
                  自动隐藏
                </ElTag>
                <ElTooltip :content="getNodeGfwTooltip(row)" placement="top">
                  <ElTag
                    round
                    effect="plain"
                    :type="getNodeGfwMeta(row).tagType"
                    class="gfw-tag"
                    :class="`gfw-tag--${getNodeGfwMeta(row).tone}`"
                  >
                    {{ getNodeGfwMeta(row).label }}
                  </ElTag>
                </ElTooltip>
                <span>{{ getNodeTypeLabel(row.type) }}</span>
              </div>
            </div>
          </template>
        </ElTableColumn>

        <ElTableColumn label="地址" min-width="260">
          <template #default="{ row }">
            <div class="stack-cell">
              <strong>{{ getNodeAddress(row).primary }}</strong>
              <span>{{ getNodeAddress(row).secondary }}</span>
            </div>
          </template>
        </ElTableColumn>

        <ElTableColumn label="在线人数" width="116">
          <template #default="{ row }">
            <div class="online-cell">
              <span class="online-cell__primary">
                <ElIcon><User /></ElIcon>
                {{ row.online }}
              </span>
              <span>连接 {{ row.online_conn }}</span>
            </div>
          </template>
        </ElTableColumn>

        <ElTableColumn label="倍率" width="96">
          <template #default="{ row }">
            <ElTag round effect="plain" class="rate-tag">
              {{ formatNodeRate(row.rate) }}
            </ElTag>
          </template>
        </ElTableColumn>

        <ElTableColumn label="权限组" min-width="180">
          <template #default="{ row }">
            <div class="group-tags">
              <ElTag
                v-for="groupName in getNodeGroupNames(row)"
                :key="`${row.id}-${groupName}`"
                round
                effect="plain"
              >
                {{ groupName }}
              </ElTag>
              <span v-if="getNodeGroupNames(row).length === 0" class="muted-copy">未分配</span>
            </div>
          </template>
        </ElTableColumn>

        <ElTableColumn label="操作" width="92" fixed="right">
          <template #default="{ row }">
            <ElDropdown
              trigger="click"
              @command="(command) => handleAction(command as NodeAction, row)"
            >
              <ElButton
                text
                class="action-trigger"
                :loading="isWorking(row.id)"
              >
                <ElIcon><MoreFilled /></ElIcon>
              </ElButton>
              <template #dropdown>
                <ElDropdownMenu>
                  <ElDropdownItem command="edit">编辑节点</ElDropdownItem>
                  <ElDropdownItem command="check-gfw" :disabled="Boolean(row.parent_id)">
                    检测墙状态
                  </ElDropdownItem>
                  <ElDropdownItem command="pin-top">置顶节点</ElDropdownItem>
                  <ElDropdownItem command="copy">复制节点</ElDropdownItem>
                  <ElDropdownItem command="delete" divided>删除节点</ElDropdownItem>
                </ElDropdownMenu>
              </template>
            </ElDropdown>
          </template>
        </ElTableColumn>

        <template #empty>
          <div class="table-empty">
            <ElEmpty
              :description="hasActiveFilters ? '当前筛选条件下暂无节点。' : '暂无节点数据。'"
            >
              <ElButton v-if="hasActiveFilters" @click="handleReset">清空筛选</ElButton>
              <ElButton v-else @click="loadNodeBoard">
                <ElIcon><RefreshRight /></ElIcon>
                重新加载
              </ElButton>
            </ElEmpty>
          </div>
        </template>
      </ElTable>

      <footer class="board-footer">
        <span>第 {{ currentPage }} 页 · 已显示 {{ paginatedNodes.length }} / {{ filteredNodes.length }} 个节点</span>
        <ElPagination
          v-model:current-page="currentPage"
          v-model:page-size="pageSize"
          :page-sizes="[10, 20, 50, 100]"
          layout="total, sizes, prev, pager, next"
          :total="filteredNodes.length"
          background
          class="footer-pagination"
        />
        <ElTooltip
          content="按 Laravel Scheduler 每 30 分钟调度估算；scheduler 未运行时不会自动创建检测任务。"
          placement="top"
        >
          <div class="footer-hint">
            <ElIcon><Connection /></ElIcon>
            <span>{{ nextAutoGfwCheckHint }}</span>
          </div>
        </ElTooltip>
      </footer>
    </section>

    <NodeEditorDialog
      v-model:visible="editorVisible"
      :mode="editorMode"
      :node="activeNode"
      :groups="groups"
      :routes="routes"
      :nodes="nodes"
      @success="() => loadNodeBoard()"
    />

    <NodeSortDialog
      v-model:visible="sortDialogVisible"
      :nodes="nodes"
      @success="() => loadNodeBoard()"
    />

    <NodeBatchEditDialog
      v-model:visible="batchEditVisible"
      :groups="groups"
      :selected-count="selectedNodes.length"
      :loading="batchSubmitting"
      @submit="handleBatchSubmit"
    />
  </div>
</template>

<style scoped>
.nodes-page {
  display: grid;
  gap: 24px;
}

.nodes-hero {
  display: flex;
  justify-content: space-between;
  gap: 24px;
  padding: 30px 32px;
  border-radius: 28px;
  background: #000000;
}

.nodes-copy {
  display: grid;
  gap: 10px;
  max-width: 680px;
}

.nodes-kicker {
  font-size: 11px;
  letter-spacing: 0.24em;
  text-transform: uppercase;
  color: rgba(255, 255, 255, 0.68);
}

.nodes-copy h1 {
  font-size: clamp(36px, 5vw, 52px);
  line-height: 1.08;
  letter-spacing: -0.28px;
  color: #ffffff;
}

.nodes-copy span {
  color: rgba(255, 255, 255, 0.72);
  line-height: 1.47;
}

.hero-stats {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
  min-width: 320px;
}

.hero-stats article {
  display: grid;
  gap: 6px;
  padding: 18px;
  border-radius: 18px;
  background: rgba(255, 255, 255, 0.08);
}

.hero-stats span {
  color: rgba(255, 255, 255, 0.64);
  font-size: 12px;
}

.hero-stats strong {
  color: #ffffff;
  font-size: 22px;
}

.nodes-board {
  display: grid;
  gap: 18px;
  padding: 24px;
  border-radius: 26px;
  background: #ffffff;
  box-shadow: var(--xboard-shadow);
}

.board-toolbar,
.toolbar-fields,
.toolbar-actions,
.board-footer,
.footer-hint,
.selection-summary {
  display: flex;
  align-items: center;
  gap: 12px;
}

.board-toolbar,
.board-footer {
  justify-content: space-between;
}

.toolbar-fields {
  flex: 1;
  flex-wrap: wrap;
}

.toolbar-input {
  width: min(320px, 100%);
}

.toolbar-select {
  width: 150px;
}

.board-alert {
  border-radius: 16px;
}

.scope-hint,
.selection-summary__label {
  color: var(--xboard-text-muted);
  line-height: 1.5;
}

.selection-summary {
  justify-content: space-between;
  flex-wrap: wrap;
  padding: 14px 16px;
  border-radius: 18px;
  background: #fbfbfd;
  border: 1px solid rgba(0, 0, 0, 0.05);
}

.nodes-table :deep(th.el-table__cell) {
  color: var(--xboard-text-secondary);
  background: #fbfbfd;
}

.nodes-table :deep(.el-table__row td.el-table__cell) {
  padding-top: 16px;
  padding-bottom: 16px;
}

.switch-shell :deep(.el-switch) {
  --el-switch-on-color: var(--node-switch-color);
}

.switch-shell--auto :deep(.el-switch) {
  --el-switch-on-color: #0071e3;
}

.switch-shell--gfw :deep(.el-switch) {
  --el-switch-on-color: #34c759;
}

.node-cell,
.stack-cell,
.online-cell {
  display: grid;
  gap: 6px;
}

.node-cell__main,
.node-cell__sub,
.online-cell__primary,
.footer-hint {
  display: flex;
  align-items: center;
  gap: 8px;
}

.node-name-trigger {
  width: fit-content;
  max-width: 100%;
  padding: 0;
  border: 0;
  background: transparent;
  font: inherit;
  text-align: left;
  cursor: default;
}

.node-name-trigger strong {
  transition: color 0.18s ease;
}

.node-name-trigger:hover strong,
.node-name-trigger:focus-visible strong {
  color: #0071e3;
}

.node-name-trigger:focus-visible {
  outline: 2px solid #0071e3;
  outline-offset: 3px;
  border-radius: 8px;
}

.node-cell__sub {
  flex-wrap: wrap;
}

.node-cell__main strong,
.stack-cell strong {
  color: var(--xboard-text-strong);
}

.node-cell__sub span,
.stack-cell span,
.online-cell span,
.board-footer span,
.muted-copy {
  color: var(--xboard-text-muted);
}

.node-dot {
  width: 8px;
  height: 8px;
  border-radius: 999px;
  flex-shrink: 0;
}

.node-dot.online {
  background: #34c759;
}

.node-dot.pending {
  background: #f5a623;
}

.node-dot.offline {
  background: #ff5f57;
}

.node-dot.disabled {
  background: #9ca3af;
}

.group-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.rate-tag,
.id-tag,
.gfw-tag,
.auto-online-tag {
  font-variant-numeric: tabular-nums;
}

.gfw-tag {
  max-width: 150px;
}

.action-trigger {
  font-size: 18px;
}

.table-empty {
  padding: 24px 0;
}

.board-footer {
  flex-wrap: wrap;
}

.footer-pagination {
  margin-left: auto;
}

.footer-hint {
  justify-content: flex-end;
  color: var(--xboard-text-muted);
}

:global(.node-traffic-popover) {
  padding: 0 !important;
  border: 0 !important;
  border-radius: 18px !important;
  box-shadow: rgba(0, 0, 0, 0.18) 0 12px 36px 0 !important;
}

:global(.node-traffic-popover .node-traffic-card) {
  display: grid;
  gap: 10px;
  padding: 14px;
  color: #1d1d1f;
}

:global(.node-traffic-card__header) {
  display: grid;
  gap: 3px;
  padding: 2px 2px 6px;
}

:global(.node-traffic-card__header span) {
  color: rgba(0, 0, 0, 0.48);
  font-size: 12px;
  line-height: 1.33;
}

:global(.node-traffic-card__header strong) {
  color: #1d1d1f;
  font-size: 15px;
  line-height: 1.25;
}

:global(.node-traffic-row) {
  display: grid;
  gap: 8px;
  padding: 12px;
  border-radius: 12px;
  background: #f5f5f7;
}

:global(.node-traffic-row__summary),
:global(.node-traffic-row__split) {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

:global(.node-traffic-row__summary span),
:global(.node-traffic-row__split span) {
  color: rgba(0, 0, 0, 0.56);
  font-size: 12px;
  line-height: 1.33;
}

:global(.node-traffic-row__summary strong) {
  color: #0071e3;
  font-size: 17px;
  line-height: 1.19;
  font-variant-numeric: tabular-nums;
}

:global(.node-traffic-row__split span) {
  font-variant-numeric: tabular-nums;
}

:global(.node-traffic-row--limit) {
  background: #fff7ed;
}

:global(.node-traffic-limit-bar) {
  width: 100%;
  height: 6px;
  overflow: hidden;
  border-radius: 999px;
  background: rgba(0, 0, 0, 0.08);
}

:global(.node-traffic-limit-bar span) {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: #f97316;
}

@media (max-width: 1180px) {
  .nodes-hero,
  .board-toolbar,
  .board-footer,
  .selection-summary {
    flex-direction: column;
    align-items: stretch;
  }

  .hero-stats {
    min-width: 0;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .toolbar-actions {
    justify-content: flex-end;
    flex-wrap: wrap;
  }
}

@media (max-width: 767px) {
  .hero-stats {
    grid-template-columns: 1fr;
  }

  .footer-hint {
    justify-content: flex-start;
  }
}
</style>
