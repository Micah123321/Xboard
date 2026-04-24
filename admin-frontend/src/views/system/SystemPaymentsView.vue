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
  deletePayment,
  fetchPayments,
  getPaymentMethods,
  sortPayments,
  togglePaymentVisibility,
} from '@/api/admin'
import type { AdminPaymentListItem } from '@/types/api'
import {
  countCustomNotifyDomains,
  countEnabledPayments,
  filterPayments,
  formatPaymentFee,
  movePaymentOrder,
  normalizePayment,
  sortPaymentsByOrder,
} from '@/utils/payments'
import SystemPaymentEditorDrawer from './SystemPaymentEditorDrawer.vue'

type DrawerMode = 'create' | 'edit'

const loading = ref(true)
const sortSubmitting = ref(false)
const drawerVisible = ref(false)
const drawerMode = ref<DrawerMode>('create')
const activePayment = ref<AdminPaymentListItem | null>(null)
const sortDialogVisible = ref(false)
const errorMessage = ref('')

const keyword = ref('')
const current = ref(1)
const pageSize = ref(10)

const payments = ref<AdminPaymentListItem[]>([])
const paymentMethods = ref<string[]>([])
const sortDraft = ref<AdminPaymentListItem[]>([])
const toggleLoadingMap = ref<Record<string, boolean>>({})

const filteredPayments = computed(() => filterPayments(payments.value, keyword.value))
const visiblePayments = computed(() => {
  const start = (current.value - 1) * pageSize.value
  return filteredPayments.value.slice(start, start + pageSize.value)
})

const summaryCards = computed(() => [
  { label: '支付方式数', value: String(payments.value.length) },
  { label: '已启用', value: String(countEnabledPayments(payments.value)) },
  { label: '接口种类', value: String(new Set(payments.value.map((item) => item.payment)).size) },
  { label: '自定义通知域名', value: String(countCustomNotifyDomains(payments.value)) },
])

function getToggleKey(id: number): string {
  return `payment:${id}`
}

function isToggleLoading(id: number): boolean {
  return Boolean(toggleLoadingMap.value[getToggleKey(id)])
}

async function loadPage() {
  loading.value = true
  errorMessage.value = ''

  try {
    const [paymentsResult, methodsResult] = await Promise.allSettled([
      fetchPayments(),
      getPaymentMethods(),
    ])

    if (paymentsResult.status === 'rejected') {
      throw paymentsResult.reason
    }

    payments.value = sortPaymentsByOrder((paymentsResult.value.data ?? []).map((item) => normalizePayment(item)))

    if (methodsResult.status === 'fulfilled') {
      paymentMethods.value = methodsResult.value.data ?? []
    } else {
      paymentMethods.value = []
      ElMessage.warning('支付接口列表加载失败，创建或切换支付接口时需要重新拉取')
    }
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '支付配置加载失败'
  } finally {
    loading.value = false
  }
}

function openCreateDrawer() {
  drawerMode.value = 'create'
  activePayment.value = null
  drawerVisible.value = true
}

function openEditDrawer(payment: AdminPaymentListItem) {
  drawerMode.value = 'edit'
  activePayment.value = payment
  drawerVisible.value = true
}

function handleDrawerSuccess() {
  void loadPage()
}

async function handleToggle(payment: AdminPaymentListItem, nextValue: boolean | string | number) {
  const normalizedNextValue = Boolean(nextValue)
  if (Boolean(payment.enable) === normalizedNextValue) {
    return
  }

  const key = getToggleKey(payment.id)
  toggleLoadingMap.value[key] = true
  try {
    await togglePaymentVisibility(payment.id)
    payment.enable = normalizedNextValue
    ElMessage.success('支付方式状态已更新')
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '支付方式状态更新失败')
  } finally {
    toggleLoadingMap.value[key] = false
  }
}

async function handleDelete(payment: AdminPaymentListItem) {
  try {
    await ElMessageBox.confirm(`删除支付方式「${payment.name}」后无法恢复，确认继续吗？`, '删除支付方式', {
      type: 'warning',
    })
    await deletePayment(payment.id)
    ElMessage.success('支付方式已删除')
    await loadPage()
  } catch (error) {
    if (error === 'cancel' || error === 'close') {
      return
    }
    ElMessage.error(error instanceof Error ? error.message : '支付方式删除失败')
  }
}

function openSortEditor() {
  sortDraft.value = payments.value.map((item) => ({
    ...item,
    config: item.config ? { ...item.config } : {},
  }))
  sortDialogVisible.value = true
}

function moveDraft(index: number, direction: -1 | 1) {
  sortDraft.value = movePaymentOrder(sortDraft.value, index, direction)
}

async function submitSort() {
  sortSubmitting.value = true
  try {
    await sortPayments(sortDraft.value.map((item) => item.id))
    ElMessage.success('支付方式排序已保存')
    sortDialogVisible.value = false
    await loadPage()
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '支付方式排序保存失败')
  } finally {
    sortSubmitting.value = false
  }
}

watch(keyword, () => {
  current.value = 1
})

watch(filteredPayments, (list) => {
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
  <div class="payments-page">
    <section class="payments-hero">
      <div class="payments-copy">
        <p class="payments-kicker">System Management</p>
        <h1>支付配置</h1>
        <span>在这里可以配置支付方式，包括添加、编辑、启停、排序与通知地址管理。</span>
      </div>

      <div class="hero-stats">
        <article v-for="item in summaryCards" :key="item.label">
          <span>{{ item.label }}</span>
          <strong>{{ item.value }}</strong>
        </article>
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
          <ElButton type="primary" @click="openCreateDrawer">
            <ElIcon><Plus /></ElIcon>
            添加支付方式
          </ElButton>

          <ElInput
            v-model="keyword"
            clearable
            placeholder="搜索支付方式..."
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
        :data="visiblePayments"
        v-loading="loading"
        class="payments-table"
        row-key="id"
        empty-text="当前筛选条件下暂无支付方式"
      >
        <ElTableColumn prop="id" label="ID" width="86" />
        <ElTableColumn label="启用" width="92">
          <template #default="{ row }">
            <ElSwitch
              :model-value="Boolean(row.enable)"
              :loading="isToggleLoading(row.id)"
              @change="handleToggle(row, $event)"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn label="显示名称" min-width="320">
          <template #default="{ row }">
            <div class="name-cell">
              <div class="name-main">
                <div v-if="row.icon" class="icon-preview">
                  <img :src="row.icon" :alt="row.name" />
                </div>
                <div v-else class="icon-fallback">
                  {{ row.name.slice(0, 1) || 'P' }}
                </div>

                <div class="name-copy">
                  <strong>{{ row.name }}</strong>
                  <span>{{ formatPaymentFee(row) }}</span>
                </div>
              </div>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn label="支付接口" min-width="140">
          <template #default="{ row }">
            <div class="gateway-cell">
              <strong>{{ row.payment }}</strong>
              <span>排序 #{{ row.sort || 0 }}</span>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn label="通知地址" min-width="320">
          <template #default="{ row }">
            <div class="notify-cell">
              <code>{{ row.notify_url || '未生成通知地址' }}</code>
              <span v-if="row.notify_domain">{{ row.notify_domain }}</span>
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
        <span>共 {{ filteredPayments.length }} 项</span>
        <ElPagination
          v-model:current-page="current"
          v-model:page-size="pageSize"
          :page-sizes="[10, 20, 50]"
          layout="sizes, prev, pager, next"
          :total="filteredPayments.length"
          background
        />
      </footer>
    </section>

    <SystemPaymentEditorDrawer
      v-model:visible="drawerVisible"
      :mode="drawerMode"
      :payment="activePayment"
      :payment-methods="paymentMethods"
      @success="handleDrawerSuccess"
    />

    <ElDialog
      v-model="sortDialogVisible"
      width="min(640px, calc(100vw - 32px))"
      title="编辑排序"
      class="sort-dialog"
    >
      <div class="sort-shell">
        <p class="sort-copy">按照当前展示顺序调整支付方式排序，保存后会同步到后台 `/payment/sort`。</p>

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
                <span>{{ item.payment }} · {{ formatPaymentFee(item) }}</span>
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

<style scoped lang="scss" src="./SystemPaymentsView.scss"></style>
