<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { ElMessage } from 'element-plus'
import type { AdminNodeBatchUpdatePayload, AdminPlanListItem, AdminServerGroupItem, AdminSubscriptionLabel } from '@/types/api'
import { buildNodeBatchEditPayload, type NodeBatchEditForm } from '@/utils/nodeBatchEditor'
import SubscriptionLabelPicker from './SubscriptionLabelPicker.vue'

type NodeBatchEditPayload = Omit<AdminNodeBatchUpdatePayload, 'ids'>

const props = defineProps<{
  visible: boolean
  groups: AdminServerGroupItem[]
  selectedCount: number
  loading?: boolean
  labels: AdminSubscriptionLabel[]
  plans: AdminPlanListItem[]
  labelLoadError?: string
  planLoadError?: string
  optionsLoading?: boolean
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  submit: [payload: NodeBatchEditPayload]
  retryOptions: []
}>()

const form = reactive<NodeBatchEditForm>({
  updateSubscriptionLabel: false,
  labelAction: 'assign',
  subscriptionLabelId: null,
  updateHost: false,
  host: '',
  updateRate: false,
  rate: 1,
  updateGroups: false,
  groupIds: [] as number[],
  updateAutoOnline: false,
  autoOnline: true,
  updateGfwCheck: false,
  gfwCheckEnabled: true,
  updateBandwidthCheck: false,
  bandwidthCheckEnabled: false,
})

const hasEnabledField = computed(() => (
  form.updateHost
  || form.updateRate
  || form.updateGroups
  || form.updateAutoOnline
  || form.updateGfwCheck
  || form.updateBandwidthCheck
  || form.updateSubscriptionLabel
))

function resetForm() {
  form.updateSubscriptionLabel = false
  form.labelAction = 'assign'
  form.subscriptionLabelId = null
  form.updateHost = false
  form.host = ''
  form.updateRate = false
  form.rate = 1
  form.updateGroups = false
  form.groupIds = []
  form.updateAutoOnline = false
  form.autoOnline = true
  form.updateGfwCheck = false
  form.gfwCheckEnabled = true
  form.updateBandwidthCheck = false
  form.bandwidthCheckEnabled = false
}

function closeDialog() {
  emit('update:visible', false)
}

function handleSubmit() {
  if (!hasEnabledField.value) {
    ElMessage.warning('请至少开启一个需要批量修改的字段')
    return
  }

  if (form.updateHost && !form.host.trim()) {
    ElMessage.warning('请输入新的节点地址 host')
    return
  }

  if (form.updateRate && (!Number.isFinite(Number(form.rate)) || Number(form.rate) <= 0)) {
    ElMessage.warning('请输入大于 0 的倍率')
    return
  }

  if (form.updateSubscriptionLabel && form.labelAction === 'assign') {
    if (props.optionsLoading || props.labelLoadError || props.planLoadError) {
      ElMessage.warning('请重试加载标签与套餐后再绑定订阅标签')
      return
    }
    if (form.subscriptionLabelId == null || !props.labels.some((label) => label.id === form.subscriptionLabelId)) {
      ElMessage.warning('请选择订阅标签，或明确选择清除标签并恢复原节点规则')
      return
    }
  }
  emit('submit', buildNodeBatchEditPayload(form))
}

watch(
  () => props.visible,
  (visible) => {
    if (visible) {
      resetForm()
    }
  },
)
</script>

<template>
  <ElDialog
    :model-value="props.visible"
    width="min(680px, calc(100vw - 24px))"
    class="node-batch-edit-dialog"
    destroy-on-close
    @close="closeDialog"
    @update:model-value="emit('update:visible', $event)"
  >
    <div class="batch-shell">
      <header class="batch-hero">
        <div>
          <h2>批量修改节点</h2>
          <p>本轮仅对已勾选节点生效；支持统一修改节点地址 host、权限组和倍率。</p>
        </div>
        <ElTag round effect="dark">
          已选 {{ props.selectedCount }} 个节点
        </ElTag>
      </header>

      <section class="batch-section" data-testid="batch-subscription-label">
        <ElCheckbox v-model="form.updateSubscriptionLabel" data-testid="enable-batch-subscription-label">修改订阅标签</ElCheckbox>
        <template v-if="form.updateSubscriptionLabel">
          <ElRadioGroup v-model="form.labelAction" class="batch-label-actions">
            <ElRadio value="assign">绑定订阅标签</ElRadio>
            <ElRadio value="clear" data-testid="clear-batch-subscription-label">清除标签并恢复原节点规则</ElRadio>
          </ElRadioGroup>
          <SubscriptionLabelPicker
            v-if="form.labelAction === 'assign'"
            v-model="form.subscriptionLabelId" :labels="props.labels" :plans="props.plans"
            :label-load-error="props.labelLoadError" :plan-load-error="props.planLoadError"
            :loading="props.optionsLoading" @retry="emit('retryOptions')"
          />
          <ElAlert v-else title="仅清除已选节点的订阅标签；各节点原手动白名单字段和值保留，并恢复原节点规则。" type="warning" :closable="false" show-icon />
        </template>
      </section>
      <section class="batch-section">
        <label class="batch-switch-card">
          <div>
            <strong>批量修改节点地址</strong>
            <span>只修改 `host`，不改端口；适合整批切换域名或 IP。</span>
          </div>
          <ElSwitch v-model="form.updateHost" />
        </label>

        <ElInput
          v-model="form.host"
          :disabled="!form.updateHost"
          placeholder="例如 node.example.com 或 1.2.3.4"
        />
      </section>

      <section class="batch-section">
        <label class="batch-switch-card">
          <div>
            <strong>批量修改权限组</strong>
            <span>启用后会整体替换所选节点的权限组；留空表示清空权限组。</span>
          </div>
          <ElSwitch v-model="form.updateGroups" />
        </label>

        <ElSelect
          v-model="form.groupIds"
          multiple
          collapse-tags
          collapse-tags-tooltip
          :disabled="!form.updateGroups"
          placeholder="请选择权限组"
        >
          <ElOption
            v-for="group in props.groups"
            :key="group.id"
            :label="group.name"
            :value="group.id"
          />
        </ElSelect>
      </section>

      <section class="batch-section">
        <label class="batch-switch-card">
          <div>
            <strong>批量修改倍率</strong>
            <span>适合统一调整节点倍率，不会改动动态倍率规则。</span>
          </div>
          <ElSwitch v-model="form.updateRate" />
        </label>

        <ElInputNumber
          v-model="form.rate"
          :disabled="!form.updateRate"
          :min="0.01"
          :step="0.01"
          :precision="2"
          :controls="false"
          class="full-width"
        />
      </section>

      <section class="batch-section">
        <label class="batch-switch-card">
          <div>
            <strong>批量设置自动上线</strong>
            <span>启用后会统一设置所选节点是否由后台自动同步前台显示。</span>
          </div>
          <ElSwitch v-model="form.updateAutoOnline" />
        </label>

        <label class="batch-switch-card batch-switch-card--nested">
          <div>
            <strong>{{ form.autoOnline ? '开启自动上线' : '关闭自动上线' }}</strong>
            <span>关闭时节点显隐继续由管理员手动控制。</span>
          </div>
          <ElSwitch v-model="form.autoOnline" :disabled="!form.updateAutoOnline" />
        </label>
      </section>

      <section class="batch-section">
        <label class="batch-switch-card">
          <div>
            <strong>批量设置墙检测托管</strong>
            <span>启用后父节点会自动检测；子节点不独立检测，只跟随父节点自动隐藏或恢复。</span>
          </div>
          <ElSwitch v-model="form.updateGfwCheck" />
        </label>

        <label class="batch-switch-card batch-switch-card--nested">
          <div>
            <strong>{{ form.gfwCheckEnabled ? '开启墙检测托管' : '关闭墙检测托管' }}</strong>
            <span>关闭后不会参与自动墙检测和墙状态自动显隐。</span>
          </div>
          <ElSwitch v-model="form.gfwCheckEnabled" :disabled="!form.updateGfwCheck" />
        </label>
      </section>
      <section class="batch-section">
        <label class="batch-switch-card">
          <div>
            <strong>批量设置带宽检测</strong>
            <span>节点下载 Cloudflare 公开测速数据（每次最多 10 MB）检测下行带宽，低于全局阈值连续 3 次后自动停用；同机共享测速，仅影响开启选项的节点。</span>
          </div>
          <ElSwitch v-model="form.updateBandwidthCheck" />
        </label>
        <label class="batch-switch-card batch-switch-card--nested">
          <div>
            <strong>{{ form.bandwidthCheckEnabled ? '开启带宽检测' : '关闭带宽检测' }}</strong>
            <span>适用于所有所选节点；关闭此选项解除该节点的带宽阻断。</span>
          </div>
          <ElSwitch v-model="form.bandwidthCheckEnabled" :disabled="!form.updateBandwidthCheck" />
        </label>
      </section>
    </div>

    <template #footer>
      <div class="batch-footer">
        <span class="batch-footer__hint">未开启的批量字段不会被提交；自动上线不会改动端口与协议配置。</span>
        <div class="batch-footer__actions">
          <ElButton @click="closeDialog">取消</ElButton>
          <ElButton type="primary" :loading="props.loading" @click="handleSubmit">
            确认批量修改
          </ElButton>
        </div>
      </div>
    </template>
  </ElDialog>
</template>

<style scoped lang="scss" src="./NodeBatchEditDialog.scss"></style>
