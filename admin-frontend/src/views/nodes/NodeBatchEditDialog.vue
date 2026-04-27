<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { ElMessage } from 'element-plus'
import type { AdminServerGroupItem } from '@/types/api'

interface NodeBatchEditPayload {
  host?: string
  rate?: number
  group_ids?: string[]
  auto_online?: boolean
  gfw_check_enabled?: boolean
}

const props = defineProps<{
  visible: boolean
  groups: AdminServerGroupItem[]
  selectedCount: number
  loading?: boolean
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  submit: [payload: NodeBatchEditPayload]
}>()

const form = reactive({
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
})

const hasEnabledField = computed(() => (
  form.updateHost
  || form.updateRate
  || form.updateGroups
  || form.updateAutoOnline
  || form.updateGfwCheck
))

function resetForm() {
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

  emit('submit', {
    host: form.updateHost ? form.host.trim() : undefined,
    rate: form.updateRate ? Number(form.rate) : undefined,
    group_ids: form.updateGroups ? [...new Set(form.groupIds.map((item) => String(item)))] : undefined,
    auto_online: form.updateAutoOnline ? form.autoOnline : undefined,
    gfw_check_enabled: form.updateGfwCheck ? form.gfwCheckEnabled : undefined,
  })
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
