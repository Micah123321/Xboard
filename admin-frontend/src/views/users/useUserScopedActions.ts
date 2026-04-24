import { computed, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useRoute, useRouter } from 'vue-router'
import { resetUserTraffic } from '@/api/admin'
import type { AdminUserFilter, AdminUserListItem } from '@/types/api'

export function useUserScopedActions() {
  const route = useRoute()
  const router = useRouter()

  const assignOrderVisible = ref(false)
  const assignOrderEmail = ref('')
  const trafficLogVisible = ref(false)
  const trafficLogUserId = ref<number | null>(null)
  const trafficLogUserEmail = ref('')
  const resettingTrafficId = ref<number | null>(null)

  const scopedInviteUserId = computed(() => {
    const raw = route.query.invite_user_id
    const value = Array.isArray(raw) ? raw[0] : raw
    const numeric = Number(value)
    return Number.isFinite(numeric) && numeric > 0 ? numeric : null
  })

  const scopedInviteUserEmail = computed(() => {
    const raw = route.query.invite_user_email
    const value = Array.isArray(raw) ? raw[0] : raw
    return typeof value === 'string' ? value : ''
  })

  const scopedInviteFilters = computed<AdminUserFilter[]>(() => (
    scopedInviteUserId.value
      ? [{ id: 'invite_user_id', value: `eq:${scopedInviteUserId.value}` }]
      : []
  ))

  const scopedInviteSummaries = computed(() => {
    if (!scopedInviteUserId.value) {
      return []
    }

    const label = scopedInviteUserEmail.value || `用户 #${scopedInviteUserId.value}`
    return [`邀请人：${label}`]
  })

  function clearScopedInviteQuery() {
    if (!scopedInviteUserId.value && !scopedInviteUserEmail.value) {
      return Promise.resolve()
    }

    const nextQuery = { ...route.query }
    delete nextQuery.invite_user_id
    delete nextQuery.invite_user_email
    return router.replace({ name: 'Users', query: nextQuery })
  }

  function openAssignOrder(user: Pick<AdminUserListItem, 'email'>) {
    assignOrderEmail.value = user.email
    assignOrderVisible.value = true
  }

  function handleAssignOrderSuccess() {
    assignOrderVisible.value = false
  }

  function openTrafficLogs(user: Pick<AdminUserListItem, 'id' | 'email'>) {
    trafficLogUserId.value = user.id
    trafficLogUserEmail.value = user.email
    trafficLogVisible.value = true
  }

  function viewUserOrders(user: Pick<AdminUserListItem, 'id' | 'email'>) {
    return router.push({
      name: 'SubscriptionOrders',
      query: {
        user_id: String(user.id),
        user_email: user.email,
      },
    })
  }

  function viewUserInvites(
    user: Pick<AdminUserListItem, 'id' | 'email'>,
    resetLocalFilters: () => void,
  ) {
    resetLocalFilters()
    return router.push({
      name: 'Users',
      query: {
        invite_user_id: String(user.id),
        invite_user_email: user.email,
      },
    })
  }

  async function performResetTraffic(user: Pick<AdminUserListItem, 'id' | 'email'>) {
    await ElMessageBox.confirm(`确认重置用户 ${user.email} 的已用流量吗？该操作会清空当前上行和下行统计。`, '重置流量', {
      type: 'warning',
    })

    resettingTrafficId.value = user.id

    try {
      await resetUserTraffic(user.id, '用户管理更多操作手动重置')
      ElMessage.success('用户流量已重置')
    } finally {
      resettingTrafficId.value = null
    }
  }

  return {
    route,
    assignOrderVisible,
    assignOrderEmail,
    trafficLogVisible,
    trafficLogUserId,
    trafficLogUserEmail,
    resettingTrafficId,
    scopedInviteUserId,
    scopedInviteFilters,
    scopedInviteSummaries,
    clearScopedInviteQuery,
    openAssignOrder,
    handleAssignOrderSuccess,
    openTrafficLogs,
    viewUserOrders,
    viewUserInvites,
    performResetTraffic,
  }
}
