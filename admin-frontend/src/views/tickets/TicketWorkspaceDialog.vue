<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  ChatLineRound,
  DataAnalysis,
  Link,
  MoreFilled,
  Picture,
  Plus,
  Search,
  Tickets,
  User,
} from '@element-plus/icons-vue'
import type { LocationQueryRaw } from 'vue-router'
import {
  assignUserTemporaryTraffic,
  closeTicket,
  fetchTickets,
  getPlans,
  getTicketById,
  replyTicket,
} from '@/api/admin'
import type { AdminPlanListItem, AdminTicketDetail, AdminTicketListItem } from '@/types/api'
import { formatDateTime } from '@/utils/dashboard'
import {
  buildTicketFilters,
  getTicketLevelMeta,
  getTicketStatusMeta,
  renderTicketMarkdown,
} from '@/utils/tickets'
import OrderAssignDrawer from '@/views/subscriptions/OrderAssignDrawer.vue'
import UserFormDrawer from '@/views/users/UserFormDrawer.vue'
import UserTemporaryTrafficDialog from '@/views/users/UserTemporaryTrafficDialog.vue'
import { isUserActionCancel, useUserRowActions, type UserRowAction } from '@/views/users/useUserRowActions'
import TicketOrdersDialog from './TicketOrdersDialog.vue'
import TrafficLogDialog from './TrafficLogDialog.vue'
import { useTicketReplyImages } from './useTicketReplyImages'
import { buildTicketReturnQuery } from './useTicketReturnLink'

const props = defineProps<{
  visible: boolean
  ticketId: number | null
  statusPreset?: number
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  updated: []
}>()

const loadingSidebar = ref(false)
const loadingDetail = ref(false)
const plansLoading = ref(false)
const plansLoadRequest = ref<Promise<void> | null>(null)
const replying = ref(false)
const sidebarTickets = ref<AdminTicketListItem[]>([])
const plans = ref<AdminPlanListItem[]>([])
const activeTicketId = ref<number | null>(null)
const detail = ref<AdminTicketDetail | null>(null)
const keyword = ref('')
const replyMessage = ref('')
const userEditorVisible = ref(false)
const ordersVisible = ref(false)
const temporaryTrafficVisible = ref(false)
const temporaryTrafficSubmitting = ref(false)

const {
  uploadingImage,
  isReplyDragActive,
  replyImageUploadLabel,
  beforeImageUpload,
  handleImageUploadRequest,
  handleReplyDragEnter,
  handleReplyDragOver,
  handleReplyDragLeave,
  handleReplyDrop,
  handleReplyPaste,
  resetReplyDragState,
} = useTicketReplyImages(replyMessage)

const statusMeta = computed(() => detail.value ? getTicketStatusMeta(detail.value) : null)
const levelMeta = computed(() => detail.value ? getTicketLevelMeta(detail.value.level) : null)
const willReopenClosedTicket = computed(() => detail.value?.status === 1)
const userActionLabel = computed(() => {
  const user = detail.value?.user
  if (!user?.id) {
    return '用户操作'
  }

  return user.banned ? '用户操作 · 已封禁' : '用户操作'
})

const userActions = useUserRowActions({
  onUserChanged: async () => {
    await refreshWorkspace()
    emit('updated')
  },
})

async function loadSidebarTickets() {
  if (!props.visible) {
    return
  }

  loadingSidebar.value = true
  try {
    const extra = buildTicketFilters(keyword.value, 'all')
    const response = await fetchTickets({
      current: 1,
      pageSize: 20,
      status: props.statusPreset,
      email: extra.email,
      filter: extra.filter,
    })
    sidebarTickets.value = response.data

    if (!activeTicketId.value && response.data.length) {
      activeTicketId.value = response.data[0].id
    }
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '工单列表加载失败')
  } finally {
    loadingSidebar.value = false
  }
}

async function loadDetail(id: number) {
  loadingDetail.value = true
  try {
    const response = await getTicketById(id)
    detail.value = response.data
    activeTicketId.value = id
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '工单详情加载失败')
  } finally {
    loadingDetail.value = false
  }
}

async function refreshWorkspace() {
  await loadSidebarTickets()
  if (activeTicketId.value) {
    await loadDetail(activeTicketId.value)
  }
}

async function ensurePlansLoaded() {
  if (plans.value.length) {
    return
  }

  if (plansLoadRequest.value) {
    await plansLoadRequest.value
    return
  }

  plansLoading.value = true
  const request = getPlans().then((response) => {
    plans.value = response.data ?? []
  }).catch((error) => {
    ElMessage.warning(error instanceof Error ? error.message : '订阅计划加载失败')
  }).finally(() => {
    plansLoading.value = false
    plansLoadRequest.value = null
  })

  plansLoadRequest.value = request
  await request
}

async function handleReply() {
  if (!detail.value || !replyMessage.value.trim()) {
    return
  }

  replying.value = true
  try {
    await replyTicket(detail.value.id, replyMessage.value.trim())
    replyMessage.value = ''
    ElMessage.success('工单已回复')
    await refreshWorkspace()
    emit('updated')
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '工单回复失败')
  } finally {
    replying.value = false
  }
}

async function handleCloseTicket() {
  if (!detail.value || detail.value.status === 1) {
    return
  }

  await ElMessageBox.confirm(`确认关闭工单 #${detail.value.id} 吗？`, '关闭工单', {
    type: 'warning',
  })

  try {
    await closeTicket(detail.value.id)
    ElMessage.success('工单已关闭')
    await refreshWorkspace()
    emit('updated')
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '关闭工单失败')
  }
}

async function openTicketUserEditor() {
  if (!detail.value?.user?.id) {
    return
  }

  await ensurePlansLoaded()
  userEditorVisible.value = true
}

function openTicketUserOrders() {
  if (!detail.value?.user?.id) {
    return
  }

  ordersVisible.value = true
}

function openTicketTemporaryTraffic() {
  if (!detail.value?.user?.id) {
    return
  }

  temporaryTrafficVisible.value = true
}

function buildCurrentTicketReturnQuery(): LocationQueryRaw {
  if (!detail.value) {
    return {}
  }

  return { ...buildTicketReturnQuery(detail.value) }
}

async function openTicketUserManagement() {
  const user = detail.value?.user
  if (!user?.id) {
    return
  }

  await userActions.viewUserManagement(user, buildCurrentTicketReturnQuery())
  closeDialog()
}

async function handleTicketUserAction(command: UserRowAction | 'manage-user') {
  const user = detail.value?.user
  if (!user?.id) {
    return
  }

  try {
    if (command === 'edit') {
      await openTicketUserEditor()
      return
    }

    if (command === 'assign-order') {
      await ensurePlansLoaded()
      userActions.openAssignOrder(user)
      return
    }

    if (command === 'assign-temporary-traffic') {
      openTicketTemporaryTraffic()
      return
    }

    if (command === 'copy') {
      await userActions.copySubscribeUrl(user)
      return
    }

    if (command === 'reset-secret') {
      await userActions.resetSecret(user)
      return
    }

    if (command === 'view-orders') {
      openTicketUserOrders()
      return
    }

    if (command === 'view-invites') {
      await userActions.viewUserInvites(user, buildCurrentTicketReturnQuery())
      closeDialog()
      return
    }

    if (command === 'view-traffic') {
      userActions.openTrafficLogs(user)
      return
    }

    if (command === 'reset-traffic') {
      await userActions.performResetTraffic(user)
      return
    }

    if (command === 'toggle-ban') {
      await userActions.toggleBan(user)
      return
    }

    if (command === 'manage-user') {
      await openTicketUserManagement()
    }
  } catch (error) {
    if (!isUserActionCancel(error)) {
      ElMessage.error(error instanceof Error ? error.message : '用户操作失败')
    }
  }
}

async function handleUserSaved() {
  await refreshWorkspace()
  emit('updated')
}

async function submitTicketTemporaryTraffic(payload: { trafficGb: number }) {
  const user = detail.value?.user
  if (!user?.id) {
    return
  }

  temporaryTrafficSubmitting.value = true
  try {
    await assignUserTemporaryTraffic({
      id: user.id,
      traffic_gb: payload.trafficGb,
    })
    ElMessage.success('一次性流量已分配')
    temporaryTrafficVisible.value = false
    await refreshWorkspace()
    emit('updated')
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '流量分配失败')
  } finally {
    temporaryTrafficSubmitting.value = false
  }
}

function closeDialog() {
  resetReplyDragState()
  userEditorVisible.value = false
  ordersVisible.value = false
  userActions.assignOrderVisible.value = false
  userActions.trafficLogVisible.value = false
  temporaryTrafficVisible.value = false
  emit('update:visible', false)
}

watch(
  () => props.visible,
  async (visible) => {
    if (!visible) {
      resetReplyDragState()
      return
    }

    activeTicketId.value = props.ticketId
    await refreshWorkspace()
  },
  { immediate: true },
)

watch(
  () => props.ticketId,
  async (ticketId) => {
    if (!props.visible || ticketId === null) {
      return
    }

    activeTicketId.value = ticketId
    await loadDetail(ticketId)
  },
)
</script>

<template>
  <ElDialog
    :model-value="props.visible"
    width="92vw"
    top="3vh"
    append-to-body
    destroy-on-close
    class="ticket-workspace-dialog"
    @close="closeDialog"
    @update:model-value="emit('update:visible', $event)"
  >
    <template #header>
      <div class="workspace-header">
        <div class="workspace-title">
          <p>Ticket Workspace</p>
          <h2>{{ detail?.subject || '工单详情' }}</h2>
        </div>

        <div class="workspace-header__actions">
          <ElButton
            v-if="detail?.user?.id"
            text
            class="ghost-action"
            :loading="plansLoading"
            @click="handleTicketUserAction('edit')"
          >
            <ElIcon><User /></ElIcon>
            编辑用户
          </ElButton>
          <ElButton
            v-if="detail?.user?.id"
            text
            class="ghost-action"
            @click="handleTicketUserAction('view-orders')"
          >
            <ElIcon><Tickets /></ElIcon>
            用户订单
          </ElButton>
          <ElButton
            v-if="detail?.user?.id"
            text
            class="ghost-action"
            @click="handleTicketUserAction('view-traffic')"
          >
            <ElIcon><DataAnalysis /></ElIcon>
            流量日志
          </ElButton>
          <ElButton
            v-if="detail?.user?.id"
            text
            class="ghost-action"
            :loading="temporaryTrafficSubmitting"
            @click="handleTicketUserAction('assign-temporary-traffic')"
          >
            <ElIcon><Plus /></ElIcon>
            分配流量
          </ElButton>
          <ElButton
            v-if="detail?.user?.id"
            text
            class="ghost-action"
            @click="handleTicketUserAction('manage-user')"
          >
            <ElIcon><Link /></ElIcon>
            用户管理
          </ElButton>
          <ElDropdown
            v-if="detail?.user?.id"
            trigger="click"
            @command="(command) => handleTicketUserAction(command as UserRowAction | 'manage-user')"
          >
            <ElButton text class="ghost-action action-menu-trigger">
              <ElIcon><MoreFilled /></ElIcon>
              <span>{{ userActionLabel }}</span>
            </ElButton>
            <template #dropdown>
              <ElDropdownMenu>
                <ElDropdownItem command="edit">编辑</ElDropdownItem>
                <ElDropdownItem command="assign-order">分配订单</ElDropdownItem>
                <ElDropdownItem command="assign-temporary-traffic">分配流量</ElDropdownItem>
                <ElDropdownItem command="copy" divided>复制订阅URL</ElDropdownItem>
                <ElDropdownItem command="reset-secret">重置UUID及订阅URL</ElDropdownItem>
                <ElDropdownItem command="view-orders" divided>TA的订单</ElDropdownItem>
                <ElDropdownItem command="view-invites">TA的邀请</ElDropdownItem>
                <ElDropdownItem command="view-traffic">TA的流量记录</ElDropdownItem>
                <ElDropdownItem command="reset-traffic">重置流量</ElDropdownItem>
                <ElDropdownItem command="toggle-ban">
                  {{ detail.user.banned ? '恢复正常' : '封禁用户' }}
                </ElDropdownItem>
                <ElDropdownItem command="manage-user" divided>进入用户管理</ElDropdownItem>
              </ElDropdownMenu>
            </template>
          </ElDropdown>
          <ElButton
            v-if="detail && detail.status !== 1"
            text
            class="ghost-action danger-action"
            @click="handleCloseTicket"
          >
            关闭工单
          </ElButton>
        </div>
      </div>
    </template>

    <div class="workspace-shell">
      <aside class="workspace-sidebar">
        <div class="sidebar-header">
          <strong>工单列表</strong>
          <ElInput
            v-model="keyword"
            placeholder="搜索工单标题或用户邮箱"
            clearable
            @keyup.enter="loadSidebarTickets"
          >
            <template #prefix>
              <ElIcon><Search /></ElIcon>
            </template>
          </ElInput>
        </div>

        <div class="sidebar-list" v-loading="loadingSidebar">
          <button
            v-for="item in sidebarTickets"
            :key="item.id"
            type="button"
            class="sidebar-ticket"
            :class="{ active: item.id === activeTicketId }"
            @click="loadDetail(item.id)"
          >
            <div class="sidebar-ticket__row">
              <strong>{{ item.subject }}</strong>
              <ElTag size="small" round :type="getTicketStatusMeta(item).type">
                {{ getTicketStatusMeta(item).label }}
              </ElTag>
            </div>
            <span>{{ item.user?.email || '未知用户' }}</span>
            <div class="sidebar-ticket__meta">
              <small>{{ formatDateTime(item.created_at) }}</small>
              <ElTag size="small" effect="plain" round :type="getTicketLevelMeta(item.level).type">
                {{ getTicketLevelMeta(item.level).label }}
              </ElTag>
            </div>
          </button>
        </div>
      </aside>

      <section class="workspace-main" v-loading="loadingDetail">
        <template v-if="detail">
          <header class="conversation-header">
            <div>
              <h3>{{ detail.subject }}</h3>
              <div class="conversation-meta">
                <span>{{ detail.user?.email || '未知用户' }}</span>
                <span>创建于 {{ formatDateTime(detail.created_at) }}</span>
              </div>
            </div>

            <div class="conversation-tags">
              <ElTag v-if="levelMeta" round effect="plain" :type="levelMeta.type">
                {{ levelMeta.label }}
              </ElTag>
              <ElTag v-if="statusMeta" round :type="statusMeta.type">
                {{ statusMeta.label }}
              </ElTag>
            </div>
          </header>

          <div class="message-thread">
            <article
              v-for="message in detail.messages"
              :key="message.id"
              class="message-card"
              :class="message.is_from_admin ? 'from-admin' : 'from-user'"
            >
              <div class="message-card__meta">
                <span>{{ message.is_from_admin ? '管理员' : detail.user?.email || '用户' }}</span>
                <small>{{ formatDateTime(message.created_at) }}</small>
              </div>
              <div class="message-card__body markdown-body" v-html="renderTicketMarkdown(message.message)" />
            </article>
          </div>

          <footer
            class="reply-box"
            :class="{
              'is-drag-active': isReplyDragActive,
              'is-uploading': uploadingImage,
            }"
            @dragenter="handleReplyDragEnter"
            @dragover="handleReplyDragOver"
            @dragleave="handleReplyDragLeave"
            @drop="handleReplyDrop"
          >
            <p v-if="willReopenClosedTicket" class="reply-box__hint">
              当前工单已关闭，发送新回复后会自动重新开启。
            </p>
            <ElInput
              v-model="replyMessage"
              type="textarea"
              :rows="3"
              resize="none"
              placeholder="输入回复内容..."
              @paste="handleReplyPaste"
            />
            <div class="reply-box__drop-hint">
              <ElIcon><Picture /></ElIcon>
              <span>{{ replyImageUploadLabel }}</span>
            </div>
            <div class="reply-box__actions">
              <ElUpload
                :show-file-list="false"
                accept="image/*"
                :before-upload="beforeImageUpload"
                :http-request="handleImageUploadRequest"
                :disabled="uploadingImage"
              >
                <ElButton text class="ghost-action" :loading="uploadingImage">
                  <ElIcon><Picture /></ElIcon>
                  上传图片
                </ElButton>
              </ElUpload>
              <ElButton
                type="primary"
                :icon="ChatLineRound"
                :loading="replying"
                :disabled="uploadingImage"
                @click="handleReply"
              >
                {{ willReopenClosedTicket ? '发送并重开' : '发送' }}
              </ElButton>
            </div>
          </footer>
        </template>

        <div v-else class="workspace-empty">
          请选择一个工单查看会话详情。
        </div>
      </section>
    </div>

    <TrafficLogDialog
      v-model:visible="userActions.trafficLogVisible.value"
      :user-id="userActions.trafficLogUserId.value"
      :user-email="userActions.trafficLogUserEmail.value"
    />

    <TicketOrdersDialog
      v-model:visible="ordersVisible"
      :user-id="detail?.user?.id ?? null"
      :user-email="detail?.user?.email"
    />

    <UserFormDrawer
      v-model:visible="userEditorVisible"
      mode="edit"
      :user="detail?.user ?? null"
      :plans="plans"
      @success="handleUserSaved"
    />

    <UserTemporaryTrafficDialog
      v-model:visible="temporaryTrafficVisible"
      :user="detail?.user ?? null"
      :loading="temporaryTrafficSubmitting"
      @submit="submitTicketTemporaryTraffic"
    />

    <OrderAssignDrawer
      v-model:visible="userActions.assignOrderVisible.value"
      :plans="plans"
      :initial-email="userActions.assignOrderEmail.value"
      @success="userActions.handleAssignOrderSuccess"
    />
  </ElDialog>
</template>

<style scoped lang="scss" src="./TicketWorkspaceDialog.scss"></style>
