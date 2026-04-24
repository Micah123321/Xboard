<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { TableColumnCtx } from 'element-plus'
import {
  Delete,
  EditPen,
  Plus,
  Search,
} from '@element-plus/icons-vue'
import {
  deleteCoupon,
  fetchCoupons,
  getPlans,
  updateCoupon,
} from '@/api/admin'
import type {
  AdminCouponListItem,
  AdminCouponType,
  AdminPlanOption,
} from '@/types/api'
import CouponEditorDialog from './CouponEditorDialog.vue'
import {
  COUPON_TYPE_OPTIONS,
  countEnabledCoupons,
  countExpiredCoupons,
  filterCoupons,
  formatCouponDateRange,
  formatCouponLimit,
  formatCouponValue,
  getCouponExpiryMeta,
  getCouponTypeShortLabel,
  normalizeCoupon,
  sortCoupons,
  type CouponSortKey,
  type CouponSortOrder,
  type CouponTypeFilter,
} from '@/utils/coupons'

type DialogMode = 'create' | 'edit'

const loading = ref(false)
const dialogVisible = ref(false)
const dialogMode = ref<DialogMode>('create')
const activeCoupon = ref<AdminCouponListItem | null>(null)
const keyword = ref('')
const typeFilter = ref<CouponTypeFilter>('all')
const current = ref(1)
const pageSize = ref(20)
const sortKey = ref<CouponSortKey>('id')
const sortOrder = ref<CouponSortOrder>('descending')

const coupons = ref<AdminCouponListItem[]>([])
const plans = ref<AdminPlanOption[]>([])
const toggleLoadingMap = ref<Record<number, boolean>>({})

const filteredCoupons = computed(() => filterCoupons(coupons.value, keyword.value, typeFilter.value))
const sortedCoupons = computed(() => sortCoupons(filteredCoupons.value, sortKey.value, sortOrder.value))
const visibleCoupons = computed(() => {
  const start = (current.value - 1) * pageSize.value
  return sortedCoupons.value.slice(start, start + pageSize.value)
})

const heroStats = computed(() => [
  { label: '优惠券总数', value: String(coupons.value.length) },
  { label: '已启用', value: String(countEnabledCoupons(coupons.value)) },
  { label: '已过期', value: String(countExpiredCoupons(coupons.value)) },
])

async function loadData() {
  loading.value = true
  try {
    const [couponResult, planResult] = await Promise.all([
      fetchCoupons({ current: 1, pageSize: 500 }),
      getPlans(),
    ])
    coupons.value = (couponResult.data ?? []).map((item) => normalizeCoupon(item))
    plans.value = planResult.data ?? []
  } finally {
    loading.value = false
  }
}

function openCreateDialog() {
  dialogMode.value = 'create'
  activeCoupon.value = null
  dialogVisible.value = true
}

function openEditDialog(coupon: AdminCouponListItem) {
  dialogMode.value = 'edit'
  activeCoupon.value = coupon
  dialogVisible.value = true
}

function isToggleLoading(id: number): boolean {
  return Boolean(toggleLoadingMap.value[id])
}

async function handleToggle(coupon: AdminCouponListItem, nextValue: string | number | boolean) {
  const normalizedNextValue = Boolean(nextValue)
  if (coupon.show === normalizedNextValue) {
    return
  }

  toggleLoadingMap.value[coupon.id] = true
  try {
    await updateCoupon(coupon.id, { show: normalizedNextValue })
    coupon.show = normalizedNextValue
    ElMessage.success('优惠券状态已更新')
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '优惠券状态更新失败')
  } finally {
    toggleLoadingMap.value[coupon.id] = false
  }
}

async function handleDelete(coupon: AdminCouponListItem) {
  try {
    await ElMessageBox.confirm(`删除优惠券「${coupon.name}」后无法恢复，确认继续吗？`, '删除优惠券', {
      type: 'warning',
    })
    await deleteCoupon(coupon.id)
    ElMessage.success('优惠券已删除')
    await loadData()
  } catch (error) {
    if (error === 'cancel' || error === 'close') {
      return
    }
    ElMessage.error(error instanceof Error ? error.message : '优惠券删除失败')
  }
}

function handleSortChange(params: {
  column: TableColumnCtx<AdminCouponListItem>
  prop: string
  order: CouponSortOrder
}) {
  sortKey.value = (params.prop || 'id') as CouponSortKey
  sortOrder.value = params.order || 'descending'
}

function getExpiryClass(endedAt: number): string {
  return `expiry-pill--${getCouponExpiryMeta(endedAt).kind}`
}

watch([keyword, typeFilter, pageSize], () => {
  current.value = 1
})

watch(sortedCoupons, (list) => {
  const maxPage = Math.max(1, Math.ceil(list.length / pageSize.value))
  if (current.value > maxPage) {
    current.value = maxPage
  }
})

onMounted(() => {
  void loadData().catch((error) => {
    ElMessage.error(error instanceof Error ? error.message : '优惠券管理页面初始化失败')
  })
})
</script>

<template>
  <div class="coupons-page">
    <section class="coupons-hero">
      <div class="coupons-copy">
        <p class="coupons-kicker">Promotions</p>
        <h1>优惠券管理</h1>
        <span>在这里可以查看和维护优惠券，包括启用、筛选、批量生成、编辑与删除等操作。</span>
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
            添加优惠券
          </ElButton>

          <ElInput
            v-model="keyword"
            clearable
            placeholder="搜索优惠券..."
            class="toolbar-search"
          >
            <template #prefix>
              <ElIcon><Search /></ElIcon>
            </template>
          </ElInput>

          <ElSelect v-model="typeFilter" class="toolbar-filter">
            <ElOption label="全部类型" value="all" />
            <ElOption
              v-for="option in COUPON_TYPE_OPTIONS"
              :key="option.value"
              :label="option.label"
              :value="String(option.value)"
            />
          </ElSelect>
        </div>
      </header>

      <ElTable
        :data="visibleCoupons"
        v-loading="loading"
        class="coupons-table"
        row-key="id"
        empty-text="当前筛选条件下暂无优惠券"
        @sort-change="handleSortChange"
      >
        <ElTableColumn prop="id" label="ID" width="88" sortable="custom" />
        <ElTableColumn label="启用" width="92">
          <template #default="{ row }">
            <ElSwitch
              :model-value="row.show"
              :loading="isToggleLoading(row.id)"
              @change="handleToggle(row, $event)"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn label="券名称" min-width="200">
          <template #default="{ row }">
            <div class="name-cell">
              <strong>{{ row.name }}</strong>
              <span>{{ formatCouponValue(row) }}</span>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="type" label="类型" width="126" sortable="custom">
          <template #default="{ row }">
            <ElTag effect="plain" round>
              {{ getCouponTypeShortLabel(row.type as AdminCouponType) }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="券码" min-width="160">
          <template #default="{ row }">
            <span class="coupon-code mono">{{ row.code }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="limit_use" label="剩余次数" width="120" sortable="custom">
          <template #default="{ row }">
            <ElTag effect="plain" round>
              {{ formatCouponLimit(row.limit_use) }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="limit_use_with_user" label="可用次数/用户" width="150" sortable="custom">
          <template #default="{ row }">
            <ElTag effect="plain" round>
              {{ formatCouponLimit(row.limit_use_with_user, '无限制') }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="ended_at" label="有效期" min-width="260" sortable="custom">
          <template #default="{ row }">
            <div class="validity-cell">
              <span class="expiry-pill" :class="getExpiryClass(row.ended_at)">
                {{ getCouponExpiryMeta(row.ended_at).text }}
              </span>
              <span>{{ formatCouponDateRange(row) }}</span>
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
        <span>已选择 0 项，共 {{ sortedCoupons.length }} 项</span>
        <ElPagination
          v-model:current-page="current"
          v-model:page-size="pageSize"
          :page-sizes="[20, 50, 100]"
          layout="sizes, prev, pager, next"
          :total="sortedCoupons.length"
          background
        />
      </footer>
    </section>

    <CouponEditorDialog
      v-model:visible="dialogVisible"
      :mode="dialogMode"
      :coupon="activeCoupon"
      :plans="plans"
      @success="() => loadData()"
    />
  </div>
</template>

<style scoped lang="scss" src="./CouponsView.scss"></style>
