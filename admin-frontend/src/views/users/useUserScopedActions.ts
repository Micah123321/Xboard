import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import type { AdminUserFilter, AdminUserListItem } from '@/types/api'
import { useUserRowActions } from './useUserRowActions'

interface UserScopedActionOptions {
  onUserChanged?: () => void | Promise<void>
}

export function useUserScopedActions(options: UserScopedActionOptions = {}) {
  const route = useRoute()
  const router = useRouter()
  const rowActions = useUserRowActions(options)

  const scopedUserId = computed(() => {
    const raw = route.query.user_id
    const value = Array.isArray(raw) ? raw[0] : raw
    const numeric = Number(value)
    return Number.isFinite(numeric) && numeric > 0 ? numeric : null
  })

  const scopedUserEmail = computed(() => {
    const raw = route.query.user_email
    const value = Array.isArray(raw) ? raw[0] : raw
    return typeof value === 'string' ? value : ''
  })

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

  const scopedUserFilters = computed<AdminUserFilter[]>(() => (
    scopedUserId.value
      ? [{ id: 'id', value: `eq:${scopedUserId.value}` }]
      : []
  ))

  const scopedUserSummaries = computed(() => {
    if (!scopedUserId.value) {
      return []
    }

    const label = scopedUserEmail.value || `用户 #${scopedUserId.value}`
    return [`用户：${label}`]
  })

  const scopedInviteSummaries = computed(() => {
    if (!scopedInviteUserId.value) {
      return []
    }

    const label = scopedInviteUserEmail.value || `用户 #${scopedInviteUserId.value}`
    return [`邀请人：${label}`]
  })

  function clearScopedUserQuery() {
    if (!scopedUserId.value && !scopedUserEmail.value) {
      return Promise.resolve()
    }

    const nextQuery = { ...route.query }
    delete nextQuery.user_id
    delete nextQuery.user_email
    return router.replace({ name: 'Users', query: nextQuery })
  }

  function clearScopedInviteQuery() {
    if (!scopedInviteUserId.value && !scopedInviteUserEmail.value) {
      return Promise.resolve()
    }

    const nextQuery = { ...route.query }
    delete nextQuery.invite_user_id
    delete nextQuery.invite_user_email
    return router.replace({ name: 'Users', query: nextQuery })
  }

  function viewUserOrders(user: Pick<AdminUserListItem, 'id' | 'email'>) {
    return rowActions.viewUserOrders(user)
  }

  function viewUserInvites(
    user: Pick<AdminUserListItem, 'id' | 'email'>,
    resetLocalFilters: () => void,
  ) {
    resetLocalFilters()
    return rowActions.viewUserInvites(user)
  }

  return {
    route,
    assignOrderVisible: rowActions.assignOrderVisible,
    assignOrderEmail: rowActions.assignOrderEmail,
    trafficLogVisible: rowActions.trafficLogVisible,
    trafficLogUserId: rowActions.trafficLogUserId,
    trafficLogUserEmail: rowActions.trafficLogUserEmail,
    resettingTrafficId: rowActions.resettingTrafficId,
    scopedUserId,
    scopedUserFilters,
    scopedUserSummaries,
    scopedInviteUserId,
    scopedInviteFilters,
    scopedInviteSummaries,
    clearScopedUserQuery,
    clearScopedInviteQuery,
    copySubscribeUrl: rowActions.copySubscribeUrl,
    resetSecret: rowActions.resetSecret,
    toggleBan: rowActions.toggleBan,
    openAssignOrder: rowActions.openAssignOrder,
    handleAssignOrderSuccess: rowActions.handleAssignOrderSuccess,
    openTrafficLogs: rowActions.openTrafficLogs,
    viewUserOrders,
    viewUserInvites,
    performResetTraffic: rowActions.performResetTraffic,
  }
}
