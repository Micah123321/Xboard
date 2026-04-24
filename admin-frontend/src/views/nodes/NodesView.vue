<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  Connection,
  MoreFilled,
  Plus,
  RefreshRight,
  Search,
  User,
} from '@element-plus/icons-vue'
import {
  copyNode,
  deleteNode,
  fetchNodes,
  fetchNodeRoutes,
  getServerGroups,
  updateNode,
} from '@/api/admin'
import type { AdminNodeItem, AdminNodeRouteItem, AdminServerGroupItem } from '@/types/api'
import NodeEditorDialog from './NodeEditorDialog.vue'
import NodeSortDialog from './NodeSortDialog.vue'
import {
  buildNodeTypeOptions,
  countOnlineNodes,
  countVisibleNodes,
  filterNodes,
  formatNodeRate,
  getNodeAddress,
  getNodeGroupNames,
  getNodeIdLabel,
  getNodeStatusMeta,
  getNodeTypeLabel,
} from '@/utils/nodes'
import { sortNodesByOrder } from '@/utils/nodeEditor'

type NodeAction = 'edit' | 'copy' | 'delete'
type NodeDialogMode = 'create' | 'edit'

const route = useRoute()
const router = useRouter()
const loading = ref(false)
const errorMessage = ref('')
const nodes = ref<AdminNodeItem[]>([])
const groups = ref<AdminServerGroupItem[]>([])
const routes = ref<AdminNodeRouteItem[]>([])
const keyword = ref('')
const typeFilter = ref('all')
const groupFilter = ref('all')
const switchingIds = ref<number[]>([])
const workingIds = ref<number[]>([])
const editorVisible = ref(false)
const editorMode = ref<NodeDialogMode>('create')
const activeNode = ref<AdminNodeItem | null>(null)
const sortDialogVisible = ref(false)

const filteredNodes = computed(() => sortNodesByOrder(filterNodes(
  nodes.value,
  keyword.value,
  typeFilter.value,
  groupFilter.value,
)))

const typeOptions = computed(() => buildNodeTypeOptions(nodes.value))
const hasActiveFilters = computed(() => keyword.value !== '' || typeFilter.value !== 'all' || groupFilter.value !== 'all')

const summaryCards = computed(() => [
  { label: '节点总数', value: String(nodes.value.length) },
  { label: '在线节点', value: String(countOnlineNodes(nodes.value)) },
  { label: '显示中', value: String(countVisibleNodes(nodes.value)) },
  { label: '当前结果', value: String(filteredNodes.value.length) },
])

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

function isWorking(id: number): boolean {
  return workingIds.value.includes(id)
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
    applyRouteGroupFilter()
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
}

function openNodeGroupManagement() {
  void router.push('/node-groups')
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

async function handleAction(action: NodeAction, node: AdminNodeItem) {
  if (action === 'edit') {
    openEditEditor(node)
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
  void loadNodeBoard()
})

watch(
  () => route.query.group,
  () => {
    applyRouteGroupFilter()
  },
)
</script>

<template>
  <div class="nodes-page">
    <section class="nodes-hero">
      <div class="nodes-copy">
        <p class="nodes-kicker">Nodes</p>
        <h1>节点管理</h1>
        <span>
          管理所有节点，包括添加、筛选、显隐控制、复制和删除等首批运营动作。
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
        </div>

        <div class="toolbar-actions">
          <ElButton @click="openNodeGroupManagement">管理权限组</ElButton>
          <ElButton @click="handleReset" :disabled="!hasActiveFilters">
            <ElIcon><RefreshRight /></ElIcon>
            重置筛选
          </ElButton>
          <ElButton @click="openSortEditor">编辑排序</ElButton>
        </div>
      </header>

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
        :data="filteredNodes"
        v-loading="loading"
        row-key="id"
        class="nodes-table"
      >
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
                @change="(value) => handleToggleShow(row, Boolean(value))"
              />
            </div>
          </template>
        </ElTableColumn>

        <ElTableColumn label="节点" min-width="280">
          <template #default="{ row }">
            <div class="node-cell">
              <div class="node-cell__main">
                <span class="node-dot" :class="getNodeStatusMeta(row).dotClass" />
                <strong>{{ row.name }}</strong>
              </div>
              <div class="node-cell__sub">
                <ElTag round effect="plain" :type="getNodeStatusMeta(row).tagType">
                  {{ getNodeStatusMeta(row).label }}
                </ElTag>
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
        <span>已显示 {{ filteredNodes.length }} / {{ nodes.length }} 个节点</span>
        <div class="footer-hint">
          <ElIcon><Connection /></ElIcon>
          <span>节点新增、编辑与排序已在当前工作台内接入真实流程。</span>
        </div>
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
.footer-hint {
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
.id-tag {
  font-variant-numeric: tabular-nums;
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

.footer-hint {
  justify-content: flex-end;
  color: var(--xboard-text-muted);
}

@media (max-width: 1180px) {
  .nodes-hero,
  .board-toolbar,
  .board-footer {
    flex-direction: column;
    align-items: stretch;
  }

  .hero-stats {
    min-width: 0;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .toolbar-actions {
    justify-content: flex-end;
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
