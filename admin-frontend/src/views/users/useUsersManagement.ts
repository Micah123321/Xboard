import { computed, onMounted, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  assignUserTemporaryTraffic,
  deleteUser,
  fetchUsers,
  getPlans,
} from '@/api/admin'
import type {
  AdminPlanListItem,
  AdminUserFilter,
  AdminUserFetchParams,
  AdminUserListItem,
} from '@/types/api'
import {
  buildUserFilters,
  summarizeUserFilters,
  type UserAdvancedFilterItem,
} from '@/utils/users'
import { useUsersBatchActions } from './useUsersBatchActions'
import { useUserScopedActions } from './useUserScopedActions'
import { isUserActionCancel } from './useUserRowActions'

type DrawerMode = 'create' | 'edit'
type UserAction =
  | 'edit'
  | 'assign-temporary-traffic'
  | 'assign-order'
  | 'copy'
  | 'reset-secret'
  | 'view-orders'
  | 'view-invites'
  | 'view-traffic'
  | 'reset-traffic'
  | 'toggle-ban'
  | 'delete'

export function useUsersManagement() {
  const loading = ref(false)
  const plansLoading = ref(false)
  const errorMessage = ref('')

  const users = ref<AdminUserListItem[]>([])
  const plans = ref<AdminPlanListItem[]>([])
  const total = ref(0)
  const current = ref(1)
  const pageSize = ref(20)
  const keyword = ref('')
  const statusFilter = ref('all')
  const planFilter = ref('all')
  const advancedFilters = ref<UserAdvancedFilterItem[]>([])

  const drawerVisible = ref(false)
  const drawerMode = ref<DrawerMode>('create')
  const activeUser = ref<AdminUserListItem | null>(null)
  const temporaryTrafficDialogVisible = ref(false)
  const temporaryTrafficUser = ref<AdminUserListItem | null>(null)
  const temporaryTrafficSubmitting = ref(false)

  const advancedFilterVisible = ref(false)
  const scopedActions = useUserScopedActions({
    onUserChanged: loadUsers,
  })

  const appliedFilters = computed<AdminUserFilter[]>(() => [
    ...buildUserFilters(
      keyword.value,
      statusFilter.value,
      planFilter.value,
      advancedFilters.value,
    ),
    ...scopedActions.scopedUserFilters.value,
    ...scopedActions.scopedInviteFilters.value,
  ])

  const appliedFilterSummaries = computed(() => summarizeUserFilters(
    keyword.value,
    statusFilter.value,
    planFilter.value,
    advancedFilters.value,
    plans.value,
  ).concat(
    scopedActions.scopedUserSummaries.value,
    scopedActions.scopedInviteSummaries.value,
  ))

  const batchActions = useUsersBatchActions({
    loading,
    total,
    keyword,
    statusFilter,
    planFilter,
    advancedFilters,
    appliedFilters,
    loadUsers,
  })

  const pageStats = computed(() => [
    { label: '当前结果', value: String(total.value) },
    { label: '已选用户', value: String(batchActions.selectedUsers.value.length) },
    { label: '生效条件', value: String(appliedFilterSummaries.value.length) },
  ])

  async function loadPlans() {
    plansLoading.value = true
    try {
      const response = await getPlans()
      plans.value = response.data ?? []
    } catch (error) {
      ElMessage.warning(error instanceof Error ? error.message : '订阅计划加载失败')
    } finally {
      plansLoading.value = false
    }
  }

  async function loadUsers() {
    loading.value = true
    errorMessage.value = ''

    try {
      const params: AdminUserFetchParams = {
        current: current.value,
        pageSize: pageSize.value,
        filter: appliedFilters.value,
        sort: [{ id: 'id', desc: true }],
      }
      const response = await fetchUsers(params)
      users.value = response.data ?? []
      total.value = response.total ?? 0
      batchActions.resetSelection()
    } catch (error) {
      users.value = []
      total.value = 0
      errorMessage.value = error instanceof Error ? error.message : '用户列表加载失败'
    } finally {
      loading.value = false
    }
  }

  function refreshUsers(resetPage: boolean = false) {
    if (resetPage && current.value !== 1) {
      current.value = 1
      return
    }

    void loadUsers()
  }

  function handleSearch() {
    refreshUsers(true)
  }

  function handleReset() {
    keyword.value = ''
    statusFilter.value = 'all'
    planFilter.value = 'all'
    advancedFilters.value = []
    void scopedActions.clearScopedUserQuery()
      .then(() => scopedActions.clearScopedInviteQuery())
      .finally(() => {
        refreshUsers(true)
      })
  }

  function clearAdvancedFilters() {
    advancedFilters.value = []
    refreshUsers(true)
  }

  function applyAdvancedFilters(filters: UserAdvancedFilterItem[]) {
    advancedFilters.value = filters
    advancedFilterVisible.value = false
    refreshUsers(true)
  }

  function openCreateDrawer() {
    drawerMode.value = 'create'
    activeUser.value = null
    drawerVisible.value = true
  }

  function openEditDrawer(user: AdminUserListItem) {
    drawerMode.value = 'edit'
    activeUser.value = user
    drawerVisible.value = true
  }

  function openTemporaryTrafficDialog(user: AdminUserListItem) {
    temporaryTrafficUser.value = user
    temporaryTrafficDialogVisible.value = true
  }

  function handleUserSaved() {
    refreshUsers(false)
  }

  async function submitTemporaryTraffic(payload: { trafficGb: number }) {
    const user = temporaryTrafficUser.value
    if (!user) {
      return
    }

    temporaryTrafficSubmitting.value = true
    try {
      await assignUserTemporaryTraffic({
        id: user.id,
        traffic_gb: payload.trafficGb,
      })
      ElMessage.success('一次性流量已分配')
      temporaryTrafficDialogVisible.value = false
      temporaryTrafficUser.value = null
      await loadUsers()
    } finally {
      temporaryTrafficSubmitting.value = false
    }
  }

  async function handleAction(action: UserAction, user: AdminUserListItem) {
    try {
      if (action === 'edit') {
        openEditDrawer(user)
        return
      }

      if (action === 'assign-order') {
        scopedActions.openAssignOrder(user)
        return
      }

      if (action === 'assign-temporary-traffic') {
        openTemporaryTrafficDialog(user)
        return
      }

      if (action === 'copy') {
        await scopedActions.copySubscribeUrl(user)
        return
      }

      if (action === 'reset-secret') {
        await scopedActions.resetSecret(user)
        return
      }

      if (action === 'toggle-ban') {
        await scopedActions.toggleBan(user)
        return
      }

      if (action === 'view-orders') {
        await scopedActions.viewUserOrders(user)
        return
      }

      if (action === 'view-invites') {
        await scopedActions.viewUserInvites(user, () => {
          keyword.value = ''
          statusFilter.value = 'all'
          planFilter.value = 'all'
          advancedFilters.value = []
        })
        return
      }

      if (action === 'view-traffic') {
        scopedActions.openTrafficLogs(user)
        return
      }

      if (action === 'reset-traffic') {
        await scopedActions.performResetTraffic(user)
        return
      }

      await ElMessageBox.confirm(`删除用户 ${user.email} 后无法恢复，确认继续吗？`, '删除用户', {
        type: 'warning',
      })
      await deleteUser(user.id)
      ElMessage.success('用户已删除')
      await loadUsers()
    } catch (error) {
      if (!isUserActionCancel(error)) {
        ElMessage.error(error instanceof Error ? error.message : '用户操作失败')
      }
    }
  }

  watch([current, pageSize], () => {
    void loadUsers()
  })

  watch(
    () => [
      scopedActions.route.query.user_id,
      scopedActions.route.query.user_email,
      scopedActions.route.query.invite_user_id,
      scopedActions.route.query.invite_user_email,
    ],
    () => {
      refreshUsers(true)
    },
  )

  onMounted(() => {
    void Promise.all([loadPlans(), loadUsers()]).catch(() => {
      ElMessage.error('用户管理页面初始化失败')
    })
  })

  return {
    loading,
    plansLoading,
    errorMessage,
    users,
    plans,
    total,
    current,
    pageSize,
    keyword,
    statusFilter,
    planFilter,
    advancedFilters,
    advancedFilterVisible,
    batchMailVisible: batchActions.batchMailVisible,
    batchMailSubmitting: batchActions.batchMailSubmitting,
    assignOrderVisible: scopedActions.assignOrderVisible,
    assignOrderEmail: scopedActions.assignOrderEmail,
    trafficLogVisible: scopedActions.trafficLogVisible,
    trafficLogUserId: scopedActions.trafficLogUserId,
    trafficLogUserEmail: scopedActions.trafficLogUserEmail,
    inviteDialogVisible: scopedActions.inviteDialogVisible,
    inviteDialogUserId: scopedActions.inviteDialogUserId,
    inviteDialogUserEmail: scopedActions.inviteDialogUserEmail,
    drawerVisible,
    drawerMode,
    activeUser,
    temporaryTrafficDialogVisible,
    temporaryTrafficUser,
    temporaryTrafficSubmitting,
    selectedUsers: batchActions.selectedUsers,
    pageStats,
    appliedFilterSummaries,
    batchTargetLabel: batchActions.batchTargetLabel,
    batchActionDisabled: batchActions.batchActionDisabled,
    refreshUsers,
    handleSearch,
    handleReset,
    clearAdvancedFilters,
    applyAdvancedFilters,
    handleSelectionChange: batchActions.handleSelectionChange,
    openCreateDrawer,
    handleUserSaved,
    submitTemporaryTraffic,
    handleAction,
    handleBatchCommand: batchActions.handleBatchCommand,
    submitBatchMail: batchActions.submitBatchMail,
    handleAssignOrderSuccess: scopedActions.handleAssignOrderSuccess,
  }
}
