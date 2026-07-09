import { h, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useRouter, type LocationQueryRaw } from 'vue-router'
import { resetUserSecret, resetUserTraffic, updateUser } from '@/api/admin'
import type { AdminUserListItem } from '@/types/api'

export type UserRowAction =
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

type MaybePromise<T> = T | Promise<T>

interface UserRowActionOptions {
  onUserChanged?: () => MaybePromise<void>
}

export function isUserActionCancel(error: unknown): boolean {
  return error === 'cancel' || error === 'close'
}

export function useUserRowActions(options: UserRowActionOptions = {}) {
  const router = useRouter()

  const assignOrderVisible = ref(false)
  const assignOrderEmail = ref('')
  const trafficLogVisible = ref(false)
  const trafficLogUserId = ref<number | null>(null)
  const trafficLogUserEmail = ref('')
  const inviteDialogVisible = ref(false)
  const inviteDialogUserId = ref<number | null>(null)
  const inviteDialogUserEmail = ref('')
  const resettingTrafficId = ref<number | null>(null)

  async function notifyUserChanged() {
    await options.onUserChanged?.()
  }

  async function showManualSubscribeUrl(url: string) {
    await ElMessageBox.alert(
      h('textarea', {
        class: 'manual-subscribe-url',
        readonly: true,
        value: url,
      }),
      '手动复制订阅地址',
      {
        confirmButtonText: '我已复制',
        customClass: 'manual-subscribe-url-dialog',
      },
    )
  }

  async function copySubscribeUrl(user: Pick<AdminUserListItem, 'subscribe_url'>) {
    if (!navigator.clipboard?.writeText) {
      await showManualSubscribeUrl(user.subscribe_url)
      return
    }

    try {
      await navigator.clipboard.writeText(user.subscribe_url)
      ElMessage.success('订阅地址已复制')
    } catch {
      await showManualSubscribeUrl(user.subscribe_url)
    }
  }

  async function resetSecret(user: Pick<AdminUserListItem, 'id' | 'email'>) {
    await ElMessageBox.confirm(`确认重置 ${user.email} 的 UUID 与订阅地址吗？`, '重置密钥', {
      type: 'warning',
    })
    await resetUserSecret(user.id)
    ElMessage.success('UUID 与订阅地址已重置')
    await notifyUserChanged()
  }

  async function toggleBan(user: Pick<AdminUserListItem, 'id' | 'email' | 'banned'>) {
    const nextValue = !user.banned
    const actionText = nextValue ? '封禁' : '恢复'

    await ElMessageBox.confirm(`确认${actionText}用户 ${user.email} 吗？`, `${actionText}用户`, {
      type: 'warning',
    })

    await updateUser({ id: user.id, banned: nextValue })
    ElMessage.success(`用户已${actionText}`)
    await notifyUserChanged()
  }

  async function performResetTraffic(user: Pick<AdminUserListItem, 'id' | 'email'>) {
    await ElMessageBox.confirm(`确认重置用户 ${user.email} 的已用流量吗？该操作会清空当前上行和下行统计。`, '重置流量', {
      type: 'warning',
    })

    resettingTrafficId.value = user.id

    try {
      await resetUserTraffic(user.id, '用户管理更多操作手动重置')
      ElMessage.success('用户流量已重置')
      await notifyUserChanged()
    } finally {
      resettingTrafficId.value = null
    }
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

  function openInviteDialog(user: Pick<AdminUserListItem, 'id' | 'email'>) {
    inviteDialogUserId.value = user.id
    inviteDialogUserEmail.value = user.email
    inviteDialogVisible.value = true
  }

  function viewUserOrders(user: Pick<AdminUserListItem, 'id' | 'email'>, extraQuery: LocationQueryRaw = {}) {
    return router.push({
      name: 'SubscriptionOrders',
      query: {
        user_id: String(user.id),
        user_email: user.email,
        ...extraQuery,
      },
    })
  }

  function viewUserInvites(
    user: Pick<AdminUserListItem, 'id' | 'email'>,
    extraQuery: LocationQueryRaw = {},
  ) {
    if (Object.keys(extraQuery).length === 0) {
      openInviteDialog(user)
      return Promise.resolve()
    }

    return router.push({
      name: 'Users',
      query: {
        invite_user_id: String(user.id),
        invite_user_email: user.email,
        ...extraQuery,
      },
    })
  }

  function viewUserManagement(user: Pick<AdminUserListItem, 'id' | 'email'>, extraQuery: LocationQueryRaw = {}) {
    return router.push({
      name: 'Users',
      query: {
        user_id: String(user.id),
        user_email: user.email,
        ...extraQuery,
      },
    })
  }

  return {
    assignOrderVisible,
    assignOrderEmail,
    trafficLogVisible,
    trafficLogUserId,
    trafficLogUserEmail,
    inviteDialogVisible,
    inviteDialogUserId,
    inviteDialogUserEmail,
    resettingTrafficId,
    copySubscribeUrl,
    resetSecret,
    toggleBan,
    performResetTraffic,
    openAssignOrder,
    handleAssignOrderSuccess,
    openTrafficLogs,
    openInviteDialog,
    viewUserOrders,
    viewUserInvites,
    viewUserManagement,
  }
}
