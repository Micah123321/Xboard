<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  Connection,
  Delete,
  EditPen,
  Plus,
  Search,
  User,
} from '@element-plus/icons-vue'
import {
  deleteServerGroup,
  getServerGroups,
} from '@/api/admin'
import type { AdminServerGroupItem } from '@/types/api'
import {
  filterNodeGroups,
  normalizeNodeGroup,
  summarizeNodeGroups,
} from '@/utils/nodeGroups'
import NodeGroupEditorDialog from './NodeGroupEditorDialog.vue'

type DialogMode = 'create' | 'edit'

const router = useRouter()

const loading = ref(true)
const errorMessage = ref('')
const keyword = ref('')
const current = ref(1)
const pageSize = ref(10)
const groups = ref<AdminServerGroupItem[]>([])
const dialogVisible = ref(false)
const dialogMode = ref<DialogMode>('create')
const activeGroup = ref<AdminServerGroupItem | null>(null)

const filteredGroups = computed(() => filterNodeGroups(groups.value, keyword.value))
const visibleGroups = computed(() => {
  const start = (current.value - 1) * pageSize.value
  return filteredGroups.value.slice(start, start + pageSize.value)
})
const summary = computed(() => summarizeNodeGroups(groups.value))

async function loadPage() {
  loading.value = true
  errorMessage.value = ''

  try {
    const response = await getServerGroups()
    groups.value = (response.data ?? []).map((item) => normalizeNodeGroup(item))
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '权限组数据加载失败'
  } finally {
    loading.value = false
  }
}

function openCreateDialog() {
  dialogMode.value = 'create'
  activeGroup.value = null
  dialogVisible.value = true
}

function openEditDialog(group: AdminServerGroupItem) {
  dialogMode.value = 'edit'
  activeGroup.value = group
  dialogVisible.value = true
}

function handleDialogSuccess() {
  void loadPage()
}

async function handleDelete(group: AdminServerGroupItem) {
  try {
    await ElMessageBox.confirm(
      `删除权限组「${group.name}」后无法恢复，确认继续吗？`,
      '删除权限组',
      { type: 'warning' },
    )
    await deleteServerGroup(group.id)
    ElMessage.success('权限组已删除')
    await loadPage()
  } catch (error) {
    if (error === 'cancel' || error === 'close') {
      return
    }

    ElMessage.error(error instanceof Error ? error.message : '权限组删除失败')
  }
}

function openNodeFilter(group: AdminServerGroupItem) {
  void router.push({
    path: '/nodes',
    query: { group: String(group.id) },
  })
}

watch(keyword, () => {
  current.value = 1
})

watch(filteredGroups, (list) => {
  const maxPage = Math.max(1, Math.ceil(list.length / pageSize.value))
  if (current.value > maxPage) {
    current.value = maxPage
  }
})

watch(pageSize, () => {
  current.value = 1
})

onMounted(() => {
  void loadPage()
})
</script>

<template>
  <div class="node-groups-page">
    <section class="page-header">
      <div class="page-copy">
        <h1>权限组管理</h1>
        <p>管理所有权限组，包括添加、删除、编辑等操作。</p>
        <div class="page-summary">
          <span>共 {{ groups.length }} 组</span>
          <span>关联用户 {{ summary.totalUsers }}</span>
          <span>关联节点 {{ summary.totalServers }}</span>
        </div>
      </div>
    </section>

    <section class="table-shell">
      <ElAlert
        v-if="errorMessage"
        type="error"
        show-icon
        :closable="false"
        :title="errorMessage"
      >
        <template #default>
          <ElButton size="small" @click="loadPage">重新加载</ElButton>
        </template>
      </ElAlert>

      <header class="table-toolbar">
        <div class="toolbar-left">
          <ElButton type="primary" @click="openCreateDialog">
            <ElIcon><Plus /></ElIcon>
            添加权限组
          </ElButton>

          <ElInput
            v-model="keyword"
            clearable
            placeholder="搜索权限组..."
            class="toolbar-search"
          >
            <template #prefix>
              <ElIcon><Search /></ElIcon>
            </template>
          </ElInput>
        </div>
      </header>

      <ElTable
        :data="visibleGroups"
        v-loading="loading"
        row-key="id"
        class="node-groups-table"
        empty-text="当前筛选条件下暂无权限组"
      >
        <ElTableColumn prop="id" label="组ID" width="104" />
        <ElTableColumn label="组名称" min-width="280">
          <template #default="{ row }">
            <div class="name-cell">
              <strong>{{ row.name }}</strong>
              <span>用于节点、套餐与用户的权限范围归属</span>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn label="用户数量" width="160">
          <template #default="{ row }">
            <span class="metric-chip">
              <ElIcon><User /></ElIcon>
              {{ row.users_count ?? 0 }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn label="节点数量" width="180">
          <template #default="{ row }">
            <ElButton
              v-if="Number(row.server_count ?? 0) > 0"
              link
              type="primary"
              class="metric-link"
              @click="openNodeFilter(row)"
            >
              <ElIcon><Connection /></ElIcon>
              {{ row.server_count }}
            </ElButton>
            <span v-else class="metric-chip is-muted">
              <ElIcon><Connection /></ElIcon>
              0
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn label="操作" width="120" fixed="right">
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

        <template #empty>
          <div class="table-empty">
            <ElEmpty :description="keyword ? '当前搜索条件下暂无权限组。' : '暂无权限组数据。'">
              <ElButton v-if="keyword" @click="keyword = ''">清空搜索</ElButton>
              <ElButton v-else @click="loadPage">重新加载</ElButton>
            </ElEmpty>
          </div>
        </template>
      </ElTable>

      <footer class="table-footer">
        <span>已选择 0 项，共 {{ filteredGroups.length }} 项</span>
        <ElPagination
          v-model:current-page="current"
          v-model:page-size="pageSize"
          :page-sizes="[10, 20, 50]"
          layout="sizes, prev, pager, next"
          :total="filteredGroups.length"
          background
        />
      </footer>
    </section>

    <NodeGroupEditorDialog
      v-model:visible="dialogVisible"
      :mode="dialogMode"
      :group="activeGroup"
      @success="handleDialogSuccess"
    />
  </div>
</template>

<style scoped lang="scss" src="./NodeGroupsView.scss"></style>
