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
  deletePlan,
  getPlans,
  getServerGroups,
  sortPlans,
  updatePlan,
} from '@/api/admin'
import type { AdminPlanListItem, AdminServerGroupItem } from '@/types/api'
import {
  countEnabledPlans,
  filterPlans,
  formatPlanTraffic,
  getPlanPriceBadges,
  movePlanOrder,
} from '@/utils/plans'
import PlanEditorDrawer from './PlanEditorDrawer.vue'

type DrawerMode = 'create' | 'edit'
type PlanToggleField = 'show' | 'sell' | 'renew'

const loading = ref(false)
const sortSubmitting = ref(false)
const drawerVisible = ref(false)
const drawerMode = ref<DrawerMode>('create')
const activePlan = ref<AdminPlanListItem | null>(null)
const sortDialogVisible = ref(false)

const keyword = ref('')
const current = ref(1)
const pageSize = ref(10)

const plans = ref<AdminPlanListItem[]>([])
const groups = ref<AdminServerGroupItem[]>([])
const sortDraft = ref<AdminPlanListItem[]>([])
const toggleLoadingMap = ref<Record<string, boolean>>({})

const filteredPlans = computed(() => filterPlans(plans.value, keyword.value))
const visiblePlans = computed(() => {
  const start = (current.value - 1) * pageSize.value
  return filteredPlans.value.slice(start, start + pageSize.value)
})

const heroStats = computed(() => [
  { label: '套餐总数', value: String(plans.value.length) },
  { label: '展示中', value: String(countEnabledPlans(plans.value, 'show')) },
  { label: '支持新购', value: String(countEnabledPlans(plans.value, 'sell')) },
  { label: '支持续费', value: String(countEnabledPlans(plans.value, 'renew')) },
])

function getToggleKey(id: number, field: PlanToggleField): string {
  return `${id}:${field}`
}

function isToggleLoading(id: number, field: PlanToggleField): boolean {
  return Boolean(toggleLoadingMap.value[getToggleKey(id, field)])
}

async function loadData() {
  loading.value = true
  try {
    const [plansResponse, groupsResponse] = await Promise.all([getPlans(), getServerGroups()])
    plans.value = [...(plansResponse.data ?? [])].sort((left, right) => (left.sort || 0) - (right.sort || 0))
    groups.value = groupsResponse.data ?? []
  } finally {
    loading.value = false
  }
}

function openCreateDrawer() {
  drawerMode.value = 'create'
  activePlan.value = null
  drawerVisible.value = true
}

function openEditDrawer(plan: AdminPlanListItem) {
  drawerMode.value = 'edit'
  activePlan.value = plan
  drawerVisible.value = true
}

async function handleToggle(plan: AdminPlanListItem, field: PlanToggleField, nextValue: boolean | string | number) {
  const key = getToggleKey(plan.id, field)
  toggleLoadingMap.value[key] = true
  try {
    await updatePlan(plan.id, { [field]: Boolean(nextValue) })
    plan[field] = Boolean(nextValue)
    ElMessage.success('套餐状态已更新')
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '套餐状态更新失败')
  } finally {
    toggleLoadingMap.value[key] = false
  }
}

async function handleDelete(plan: AdminPlanListItem) {
  try {
    await ElMessageBox.confirm(`删除套餐「${plan.name}」后无法恢复，确认继续吗？`, '删除套餐', {
      type: 'warning',
    })
    await deletePlan(plan.id)
    ElMessage.success('套餐已删除')
    await loadData()
  } catch (error) {
    if (error === 'cancel' || error === 'close') {
      return
    }
    ElMessage.error(error instanceof Error ? error.message : '套餐删除失败')
  }
}

function openSortEditor() {
  sortDraft.value = plans.value.map((plan) => ({ ...plan }))
  sortDialogVisible.value = true
}

function moveDraft(index: number, direction: -1 | 1) {
  sortDraft.value = movePlanOrder(sortDraft.value, index, direction)
}

async function submitSort() {
  sortSubmitting.value = true
  try {
    await sortPlans(sortDraft.value.map((item) => item.id))
    ElMessage.success('排序已保存')
    sortDialogVisible.value = false
    await loadData()
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '排序保存失败')
  } finally {
    sortSubmitting.value = false
  }
}

watch(keyword, () => {
  current.value = 1
})

watch(filteredPlans, (list) => {
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
    ElMessage.error(error instanceof Error ? error.message : '套餐管理页面初始化失败')
  })
})
</script>

<template>
  <div class="plans-page">
    <section class="plans-hero">
      <div class="plans-copy">
        <p class="plans-kicker">Subscriptions</p>
        <h1>订阅套餐</h1>
        <span>在这里可以配置订阅计划，包括添加、删除、编辑、排序与价格维护。</span>
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
          <ElButton type="primary" @click="openCreateDrawer">
            <ElIcon><Plus /></ElIcon>
            添加套餐
          </ElButton>

          <ElInput
            v-model="keyword"
            clearable
            placeholder="搜索套餐..."
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
        :data="visiblePlans"
        v-loading="loading"
        class="plans-table"
        row-key="id"
        empty-text="当前筛选条件下暂无套餐"
      >
        <ElTableColumn prop="id" label="ID" width="86" />
        <ElTableColumn label="显示" width="92">
          <template #default="{ row }">
            <ElSwitch
              :model-value="row.show"
              :loading="isToggleLoading(row.id, 'show')"
              @change="handleToggle(row, 'show', $event)"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn label="新购" width="92">
          <template #default="{ row }">
            <ElSwitch
              :model-value="row.sell"
              :loading="isToggleLoading(row.id, 'sell')"
              @change="handleToggle(row, 'sell', $event)"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn label="续费" width="92">
          <template #default="{ row }">
            <ElSwitch
              :model-value="row.renew"
              :loading="isToggleLoading(row.id, 'renew')"
              @change="handleToggle(row, 'renew', $event)"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn label="名称" min-width="280">
          <template #default="{ row }">
            <div class="name-cell">
              <strong>{{ row.name }}</strong>
              <span>
                {{ formatPlanTraffic(row) }}
                <template v-if="row.tags?.length">
                  · {{ row.tags.join(' / ') }}
                </template>
              </span>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn label="统计" min-width="154">
          <template #default="{ row }">
            <div class="metric-cell">
              <ElTag effect="plain" round>
                总用户 {{ row.users_count ?? 0 }}
              </ElTag>
              <ElTag type="success" effect="plain" round>
                活跃 {{ row.active_users_count ?? 0 }}
              </ElTag>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn label="权限组" min-width="120">
          <template #default="{ row }">
            <ElTag effect="plain" round>
              {{ row.group?.name || '未分组' }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="价格" min-width="260">
          <template #default="{ row }">
            <div class="price-cell">
              <ElTag
                v-for="badge in getPlanPriceBadges(row)"
                :key="`${row.id}-${badge.key}`"
                effect="plain"
                round
              >
                {{ badge.label }}
              </ElTag>
              <span v-if="!getPlanPriceBadges(row).length" class="price-empty">未设置价格</span>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn label="操作" width="108" fixed="right">
          <template #default="{ row }">
            <div class="action-group">
              <ElButton text class="action-btn" @click="openEditDrawer(row)">
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
        <span>已选择 0 项，共 {{ filteredPlans.length }} 项</span>
        <ElPagination
          v-model:current-page="current"
          v-model:page-size="pageSize"
          :page-sizes="[10, 20, 50]"
          layout="sizes, prev, pager, next"
          :total="filteredPlans.length"
          background
        />
      </footer>
    </section>

    <PlanEditorDrawer
      v-model:visible="drawerVisible"
      :mode="drawerMode"
      :plan="activePlan"
      :groups="groups"
      @success="() => loadData()"
    />

    <ElDialog
      v-model="sortDialogVisible"
      width="min(640px, calc(100vw - 32px))"
      title="编辑排序"
      class="sort-dialog"
    >
      <div class="sort-shell">
        <p class="sort-copy">按照当前展示顺序调整套餐排序，保存后会同步到后台 `/plan/sort`。</p>

        <div class="sort-list">
          <article
            v-for="(item, index) in sortDraft"
            :key="item.id"
            class="sort-item"
          >
            <div class="sort-item__main">
              <span class="sort-index">{{ index + 1 }}</span>
              <div class="sort-meta">
                <strong>{{ item.name }}</strong>
                <span>{{ formatPlanTraffic(item) }} · {{ item.group?.name || '未分组' }}</span>
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

<style scoped lang="scss" src="./PlansView.scss"></style>
