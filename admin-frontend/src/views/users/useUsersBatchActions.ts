import { computed, ref, type ComputedRef, type Ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  batchUpdateUserBan,
  exportUsersCsv,
  sendUsersMail,
} from '@/api/admin'
import type {
  AdminUserBulkMailPayload,
  AdminUserBulkScopePayload,
  AdminUserFilter,
  AdminUserListItem,
} from '@/types/api'
import { hasUserFilters, type UserAdvancedFilterItem } from '@/utils/users'

interface UserBatchMailForm {
  subject: string
  content: string
}

interface UseUsersBatchActionsOptions {
  loading: Ref<boolean>
  total: Ref<number>
  keyword: Ref<string>
  statusFilter: Ref<string>
  planFilter: Ref<string>
  advancedFilters: Ref<UserAdvancedFilterItem[]>
  appliedFilters: ComputedRef<AdminUserFilter[]>
  loadUsers: () => Promise<void>
}

function isCancelError(error: unknown): boolean {
  return error === 'cancel' || error === 'close'
}

function createTimestamp(): string {
  const now = new Date()
  const pad = (value: number) => String(value).padStart(2, '0')
  return `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`
}

function triggerBlobDownload(blob: Blob, fileName: string) {
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = fileName
  document.body.appendChild(anchor)
  anchor.click()
  document.body.removeChild(anchor)
  URL.revokeObjectURL(url)
}

export function useUsersBatchActions(options: UseUsersBatchActionsOptions) {
  const selectedUsers = ref<AdminUserListItem[]>([])
  const batchMailVisible = ref(false)
  const batchMailSubmitting = ref(false)
  const batchActionSubmitting = ref(false)

  const batchTarget = computed(() => {
    if (selectedUsers.value.length > 0) {
      return {
        scope: 'selected' as const,
        label: `当前已选 ${selectedUsers.value.length} 个用户`,
        user_ids: selectedUsers.value.map((user) => user.id),
      }
    }

    if (hasUserFilters(
      options.keyword.value,
      options.statusFilter.value,
      options.planFilter.value,
      options.advancedFilters.value,
    )) {
      return {
        scope: 'filtered' as const,
        label: '当前筛选结果',
        filter: options.appliedFilters.value,
      }
    }

    return {
      scope: 'all' as const,
      label: '全部用户',
    }
  })

  const batchTargetLabel = computed(() => batchTarget.value.label)

  const batchActionDisabled = computed(() => (
    options.loading.value
    || batchActionSubmitting.value
    || batchMailSubmitting.value
    || (options.total.value === 0 && selectedUsers.value.length === 0)
  ))

  function handleSelectionChange(selection: AdminUserListItem[]) {
    selectedUsers.value = selection
  }

  function resetSelection() {
    selectedUsers.value = []
  }

  function buildScopePayload(): AdminUserBulkScopePayload {
    if (batchTarget.value.scope === 'selected') {
      return {
        scope: 'selected',
        user_ids: batchTarget.value.user_ids,
      }
    }

    if (batchTarget.value.scope === 'filtered') {
      return {
        scope: 'filtered',
        filter: batchTarget.value.filter,
      }
    }

    return { scope: 'all' }
  }

  function buildMutationScopePayload(): Pick<AdminUserBulkMailPayload, 'scope' | 'user_ids' | 'filter' | 'sort' | 'sort_type'> {
    return {
      ...buildScopePayload(),
      sort: 'id',
      sort_type: 'DESC',
    }
  }

  async function ensureAllUsersConfirmation(actionText: string) {
    if (batchTarget.value.scope !== 'all') {
      return
    }

    await ElMessageBox.confirm(`当前未勾选用户且未设置筛选条件，将对全部用户执行“${actionText}”，确认继续吗？`, `确认${actionText}`, {
      type: 'warning',
    })
  }

  async function exportCurrentUsers() {
    try {
      await ensureAllUsersConfirmation('导出 CSV')
      batchActionSubmitting.value = true
      const blob = await exportUsersCsv(buildScopePayload())
      triggerBlobDownload(blob, `users-${batchTarget.value.scope}-${createTimestamp()}.csv`)
      ElMessage.success(`CSV 已导出（${batchTarget.value.label}）`)
    } catch (error) {
      if (!isCancelError(error)) {
        ElMessage.error(error instanceof Error ? error.message : 'CSV 导出失败')
      }
    } finally {
      batchActionSubmitting.value = false
    }
  }

  async function submitBatchMail(form: UserBatchMailForm) {
    try {
      await ensureAllUsersConfirmation('发送邮件')
      batchMailSubmitting.value = true
      await sendUsersMail({
        ...buildMutationScopePayload(),
        subject: form.subject,
        content: form.content,
      })
      batchMailVisible.value = false
      ElMessage.success(`邮件发送任务已提交（${batchTarget.value.label}）`)
    } catch (error) {
      if (!isCancelError(error)) {
        ElMessage.error(error instanceof Error ? error.message : '批量邮件发送失败')
      }
    } finally {
      batchMailSubmitting.value = false
    }
  }

  async function updateBatchBanState(banned: boolean) {
    const actionText = banned ? '批量封禁' : '恢复正常'

    try {
      await ensureAllUsersConfirmation(actionText)
      await ElMessageBox.confirm(`确认对${batchTarget.value.label}执行“${actionText}”吗？`, actionText, {
        type: 'warning',
      })
      batchActionSubmitting.value = true
      await batchUpdateUserBan({
        ...buildMutationScopePayload(),
        banned: banned ? 1 : 0,
      })
      ElMessage.success(`${actionText}已完成（${batchTarget.value.label}）`)
      await options.loadUsers()
    } catch (error) {
      if (!isCancelError(error)) {
        ElMessage.error(error instanceof Error ? error.message : `${actionText}失败`)
      }
    } finally {
      batchActionSubmitting.value = false
    }
  }

  async function handleBatchCommand(command: string | number | object) {
    const normalizedCommand = String(command)

    if (normalizedCommand === 'send-mail') {
      batchMailVisible.value = true
      return
    }

    if (normalizedCommand === 'export-csv') {
      await exportCurrentUsers()
      return
    }

    if (normalizedCommand === 'ban') {
      await updateBatchBanState(true)
      return
    }

    if (normalizedCommand === 'restore') {
      await updateBatchBanState(false)
    }
  }

  return {
    selectedUsers,
    batchMailVisible,
    batchMailSubmitting,
    batchTargetLabel,
    batchActionDisabled,
    handleSelectionChange,
    resetSelection,
    handleBatchCommand,
    submitBatchMail,
  }
}
