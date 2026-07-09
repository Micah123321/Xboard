<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { fetchUserInviteInfo } from '@/api/admin'
import type {
  AdminUserInviteCodeItem,
  AdminUserInviteInfo,
  AdminUserInvitedOrderItem,
  AdminUserInvitedUserItem,
} from '@/types/api'
import { formatDateTime } from '@/utils/dashboard'
import { formatOrderAmount, getCommissionStatusMeta, getOrderStatusMeta } from '@/utils/orders'

const props = defineProps<{
  visible: boolean
  userId: number | null
  userEmail?: string
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
}>()

const router = useRouter()
const loading = ref(false)
const info = ref<AdminUserInviteInfo | null>(null)

const titleEmail = computed(() => props.userEmail || info.value?.user.email || '未知用户')

async function loadInviteInfo() {
  if (!props.visible || !props.userId) {
    info.value = null
    return
  }

  loading.value = true
  try {
    const response = await fetchUserInviteInfo(props.userId)
    info.value = response.data
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '邀请信息加载失败')
    info.value = null
  } finally {
    loading.value = false
  }
}

watch(
  () => [props.visible, props.userId],
  ([visible, userId]) => {
    if (!visible || !userId) {
      return
    }
    void loadInviteInfo()
  },
  { immediate: true },
)

async function copyText(text: string, successMessage: string) {
  try {
    await navigator.clipboard.writeText(text)
    ElMessage.success(successMessage)
  } catch {
    ElMessage.error('复制失败')
  }
}

function viewAllInvitedUsers() {
  if (!props.userId) {
    return
  }

  emit('update:visible', false)
  void router.push({
    name: 'Users',
    query: {
      invite_user_id: String(props.userId),
      invite_user_email: titleEmail.value,
    },
  })
}

function viewAllInvitedOrders() {
  if (!props.userId) {
    return
  }

  emit('update:visible', false)
  void router.push({
    name: 'SubscriptionOrders',
    query: {
      invite_user_id: String(props.userId),
      invite_user_email: titleEmail.value,
    },
  })
}
</script>

<template>
  <ElDialog
    :model-value="props.visible"
    width="860px"
    class="user-invite-dialog"
    append-to-body
    destroy-on-close
    @close="emit('update:visible', false)"
    @update:model-value="emit('update:visible', $event)"
  >
    <template #header>
      <div class="dialog-header">
        <div>
          <p>Invite Info</p>
          <h2>邀请信息</h2>
        </div>
        <span>{{ titleEmail }}</span>
      </div>
    </template>

    <div v-loading="loading" class="dialog-body">
      <section class="summary-grid">
        <article>
          <span>邀请码</span>
          <strong>{{ info?.codes.length ?? 0 }}</strong>
        </article>
        <article>
          <span>已邀请用户</span>
          <strong>{{ info?.invited_users_count ?? 0 }}</strong>
        </article>
        <article>
          <span>邀请订单</span>
          <strong>{{ info?.invited_orders_count ?? 0 }}</strong>
        </article>
      </section>

      <section class="section">
        <header class="section-header">
          <h3>邀请链接</h3>
        </header>
        <ElTable :data="(info?.codes ?? []) as AdminUserInviteCodeItem[]" empty-text="暂无邀请码" row-key="code">
          <ElTableColumn prop="code" label="邀请码" min-width="120" />
          <ElTableColumn label="邀请链接" min-width="260">
            <template #default="{ row }">
              <div class="link-cell">
                <span class="mono">{{ row.invite_url }}</span>
                <ElButton text type="primary" @click="copyText(row.invite_url, '邀请链接已复制')">
                  复制
                </ElButton>
              </div>
            </template>
          </ElTableColumn>
          <ElTableColumn label="创建时间" width="160">
            <template #default="{ row }">
              {{ formatDateTime(row.created_at) }}
            </template>
          </ElTableColumn>
        </ElTable>
      </section>

      <section class="section">
        <header class="section-header">
          <h3>已邀请用户</h3>
          <ElButton text type="primary" @click="viewAllInvitedUsers">查看全部</ElButton>
        </header>
        <ElTable :data="(info?.invited_users ?? []) as AdminUserInvitedUserItem[]" empty-text="暂无邀请用户" row-key="id">
          <ElTableColumn prop="email" label="用户邮箱" min-width="220" />
          <ElTableColumn label="注册时间" width="160">
            <template #default="{ row }">
              {{ formatDateTime(row.created_at) }}
            </template>
          </ElTableColumn>
        </ElTable>
      </section>

      <section class="section">
        <header class="section-header">
          <h3>邀请订单</h3>
          <ElButton text type="primary" @click="viewAllInvitedOrders">查看全部</ElButton>
        </header>
        <ElTable :data="(info?.invited_orders ?? []) as AdminUserInvitedOrderItem[]" empty-text="暂无邀请订单" row-key="id">
          <ElTableColumn prop="trade_no" label="订单号" min-width="170" />
          <ElTableColumn prop="email" label="用户邮箱" min-width="180" />
          <ElTableColumn label="金额" width="110">
            <template #default="{ row }">
              {{ formatOrderAmount(row.total_amount) }}
            </template>
          </ElTableColumn>
          <ElTableColumn label="订单状态" width="110">
            <template #default="{ row }">
              {{ getOrderStatusMeta(row.status).label }}
            </template>
          </ElTableColumn>
          <ElTableColumn label="佣金状态" width="110">
            <template #default="{ row }">
              {{
                row.commission_status === null
                  ? '无佣金'
                  : getCommissionStatusMeta(row.commission_status, 1, null).label
              }}
            </template>
          </ElTableColumn>
          <ElTableColumn label="创建时间" width="160">
            <template #default="{ row }">
              {{ formatDateTime(row.created_at) }}
            </template>
          </ElTableColumn>
        </ElTable>
      </section>
    </div>
  </ElDialog>
</template>

<style scoped>
.dialog-header {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 12px;
}

.dialog-header p {
  font-size: 11px;
  letter-spacing: 0.22em;
  text-transform: uppercase;
  color: var(--xboard-text-muted);
}

.dialog-header h2 {
  font-size: 30px;
  line-height: 1.08;
  color: var(--xboard-text-strong);
}

.dialog-header span {
  color: var(--xboard-text-secondary);
}

.dialog-body {
  display: grid;
  gap: 18px;
}

.summary-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}

.summary-grid article {
  display: grid;
  gap: 6px;
  padding: 16px 18px;
  border-radius: 16px;
  background: #f5f5f7;
}

.summary-grid span {
  color: var(--xboard-text-muted);
  font-size: 12px;
}

.summary-grid strong {
  color: var(--xboard-text-strong);
  font-size: 22px;
}

.section {
  display: grid;
  gap: 10px;
}

.section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.section-header h3 {
  margin: 0;
  font-size: 16px;
  color: var(--xboard-text-strong);
}

.link-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}

.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;
  word-break: break-all;
}

@media (max-width: 767px) {
  .dialog-header,
  .section-header,
  .link-cell {
    flex-direction: column;
    align-items: stretch;
  }

  .summary-grid {
    grid-template-columns: 1fr;
  }
}
</style>
