<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ChatLineRound, DataAnalysis, Picture, Search, Tickets, User } from '@element-plus/icons-vue'
import { useRouter } from 'vue-router'
import { closeTicket, fetchTickets, getTicketById, replyTicket } from '@/api/admin'
import type { AdminTicketDetail, AdminTicketListItem } from '@/types/api'
import { formatDateTime } from '@/utils/dashboard'
import {
  buildTicketFilters,
  getTicketLevelMeta,
  getTicketStatusMeta,
  renderTicketMarkdown,
} from '@/utils/tickets'
import TrafficLogDialog from './TrafficLogDialog.vue'
import { useTicketReplyImages } from './useTicketReplyImages'

const props = defineProps<{
  visible: boolean
  ticketId: number | null
  statusPreset?: number
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  updated: []
}>()

const router = useRouter()
const loadingSidebar = ref(false)
const loadingDetail = ref(false)
const replying = ref(false)
const sidebarTickets = ref<AdminTicketListItem[]>([])
const activeTicketId = ref<number | null>(null)
const detail = ref<AdminTicketDetail | null>(null)
const keyword = ref('')
const replyMessage = ref('')
const trafficVisible = ref(false)

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

async function openTicketUser() {
  if (!detail.value?.user?.id) {
    return
  }

  await router.push({
    name: 'Users',
    query: {
      user_id: String(detail.value.user.id),
      user_email: detail.value.user.email,
    },
  })
}

async function openTicketUserOrders() {
  if (!detail.value?.user?.id) {
    return
  }

  await router.push({
    name: 'SubscriptionOrders',
    query: {
      user_id: String(detail.value.user.id),
      user_email: detail.value.user.email,
    },
  })
}

function closeDialog() {
  resetReplyDragState()
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
            @click="openTicketUser"
          >
            <ElIcon><User /></ElIcon>
            查看用户
          </ElButton>
          <ElButton
            v-if="detail?.user?.id"
            text
            class="ghost-action"
            @click="openTicketUserOrders"
          >
            <ElIcon><Tickets /></ElIcon>
            用户订单
          </ElButton>
          <ElButton
            v-if="detail?.user?.id"
            text
            class="ghost-action"
            @click="trafficVisible = true"
          >
            <ElIcon><DataAnalysis /></ElIcon>
            流量日志
          </ElButton>
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
      v-model:visible="trafficVisible"
      :user-id="detail?.user?.id ?? null"
      :user-email="detail?.user?.email"
    />
  </ElDialog>
</template>

<style scoped lang="scss" src="./TicketWorkspaceDialog.scss"></style>
