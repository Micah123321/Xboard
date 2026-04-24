<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { UploadProps, UploadRequestOptions } from 'element-plus'
import { ChatLineRound, DataAnalysis, Picture, Search } from '@element-plus/icons-vue'
import { closeTicket, fetchTickets, getTicketById, replyTicket } from '@/api/admin'
import type { AdminTicketDetail, AdminTicketListItem } from '@/types/api'
import { formatDateTime } from '@/utils/dashboard'
import {
  buildTicketFilters,
  getTicketLevelMeta,
  getTicketStatusMeta,
  renderTicketMarkdown,
} from '@/utils/tickets'
import { uploadImage } from '@/utils/upload'
import TrafficLogDialog from './TrafficLogDialog.vue'

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
const replying = ref(false)
const sidebarTickets = ref<AdminTicketListItem[]>([])
const activeTicketId = ref<number | null>(null)
const detail = ref<AdminTicketDetail | null>(null)
const keyword = ref('')
const replyMessage = ref('')
const trafficVisible = ref(false)
const uploadingImage = ref(false)
type UploadError = Parameters<UploadRequestOptions['onError']>[0]

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

const beforeImageUpload: UploadProps['beforeUpload'] = (rawFile) => {
  if (!rawFile.type.startsWith('image/')) {
    ElMessage.error('仅支持上传图片文件')
    return false
  }

  if (rawFile.size / 1024 / 1024 > 10) {
    ElMessage.error('图片大小不能超过 10MB')
    return false
  }

  return true
}

async function handleImageUploadRequest(options: UploadRequestOptions) {
  uploadingImage.value = true
  try {
    const url = await uploadImage(options.file)
    const markdown = `![image](${url})`
    replyMessage.value = replyMessage.value
      ? `${replyMessage.value}\n${markdown}\n`
      : `${markdown}\n`
    options.onSuccess({ url })
    ElMessage.success('图片上传成功')
  } catch (error) {
    const message = error instanceof Error ? error.message : '图片上传失败'
    options.onError(Object.assign(new Error(message), {
      status: 500,
      method: 'POST',
      url: '/upload/rest/upload',
    }) as UploadError)
    ElMessage.error(message)
  } finally {
    uploadingImage.value = false
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

function closeDialog() {
  emit('update:visible', false)
}

watch(
  () => props.visible,
  async (visible) => {
    if (!visible) {
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

          <footer class="reply-box">
            <p v-if="willReopenClosedTicket" class="reply-box__hint">
              当前工单已关闭，发送新回复后会自动重新开启。
            </p>
            <ElInput
              v-model="replyMessage"
              type="textarea"
              :rows="3"
              resize="none"
              placeholder="输入回复内容..."
            />
            <div class="reply-box__actions">
              <ElUpload
                :show-file-list="false"
                accept="image/*"
                :before-upload="beforeImageUpload"
                :http-request="handleImageUploadRequest"
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

<style scoped>
.workspace-header {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 16px;
}

.workspace-title p {
  font-size: 11px;
  letter-spacing: 0.22em;
  text-transform: uppercase;
  color: var(--xboard-text-muted);
}

.workspace-title h2 {
  font-size: 34px;
  line-height: 1.08;
  color: var(--xboard-text-strong);
}

.workspace-header__actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.ghost-action {
  color: var(--xboard-link);
}

.danger-action {
  color: var(--xboard-danger);
}

.workspace-shell {
  display: grid;
  grid-template-columns: 280px minmax(0, 1fr);
  min-height: 70vh;
  border: 1px solid var(--xboard-border);
  border-radius: 24px;
  overflow: hidden;
}

.workspace-sidebar {
  display: grid;
  grid-template-rows: auto 1fr;
  border-right: 1px solid var(--xboard-border);
  background: #fbfbfd;
}

.sidebar-header {
  display: grid;
  gap: 12px;
  padding: 20px;
  border-bottom: 1px solid var(--xboard-border);
}

.sidebar-list {
  display: grid;
  align-content: start;
  gap: 8px;
  padding: 16px;
  overflow-y: auto;
}

.sidebar-ticket {
  border: 1px solid transparent;
  background: #ffffff;
  border-radius: 18px;
  padding: 16px;
  text-align: left;
  display: grid;
  gap: 8px;
  cursor: pointer;
  transition: 0.2s ease;
}

.sidebar-ticket.active {
  border-color: rgba(0, 113, 227, 0.24);
  background: rgba(0, 113, 227, 0.08);
}

.sidebar-ticket__row,
.sidebar-ticket__meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.sidebar-ticket span,
.sidebar-ticket small {
  color: var(--xboard-text-muted);
}

.workspace-main {
  display: grid;
  grid-template-rows: auto 1fr auto;
  background: #ffffff;
  min-height: 0;
}

.conversation-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding: 20px 24px;
  border-bottom: 1px solid var(--xboard-border);
}

.conversation-header h3 {
  font-size: 32px;
  line-height: 1.08;
  color: var(--xboard-text-strong);
}

.conversation-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 14px;
  margin-top: 8px;
  color: var(--xboard-text-muted);
}

.conversation-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.message-thread {
  display: grid;
  align-content: start;
  gap: 16px;
  padding: 24px;
  overflow-y: auto;
  background: linear-gradient(180deg, #ffffff 0%, #fbfbfd 100%);
}

.message-card {
  display: grid;
  gap: 10px;
  max-width: min(720px, 100%);
  padding: 18px 20px;
  border-radius: 20px;
  box-shadow: var(--xboard-shadow);
}

.from-user {
  background: #eef3fb;
}

.from-admin {
  background: #1d1d1f;
  color: #ffffff;
  margin-left: auto;
}

.message-card__meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  font-size: 12px;
}

.from-user .message-card__meta {
  color: var(--xboard-text-muted);
}

.from-admin .message-card__meta {
  color: rgba(255, 255, 255, 0.72);
}

.markdown-body :deep(p),
.markdown-body :deep(h1),
.markdown-body :deep(h2),
.markdown-body :deep(h3),
.markdown-body :deep(ul),
.markdown-body :deep(ol) {
  margin: 0 0 10px;
}

.markdown-body :deep(img) {
  max-width: min(420px, 100%);
  border-radius: 14px;
}

.markdown-body :deep(a) {
  color: inherit;
  text-decoration: underline;
}

.reply-box {
  display: grid;
  gap: 12px;
  padding: 20px 24px;
  border-top: 1px solid var(--xboard-border);
  background: rgba(255, 255, 255, 0.92);
}

.reply-box__hint {
  color: var(--xboard-text-muted);
  font-size: 13px;
  line-height: 1.5;
}

.reply-box__actions {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 12px;
}

.workspace-empty {
  display: grid;
  place-items: center;
  color: var(--xboard-text-muted);
}

@media (max-width: 1023px) {
  .workspace-shell {
    grid-template-columns: 1fr;
  }

  .workspace-sidebar {
    border-right: 0;
    border-bottom: 1px solid var(--xboard-border);
  }

  .workspace-header,
  .conversation-header {
    flex-direction: column;
    align-items: stretch;
  }
}
</style>
