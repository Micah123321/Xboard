<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { ElMessage } from 'element-plus'
import type { AdminServerGroupItem } from '@/types/api'

interface NodeBatchEditPayload {
  host?: string
  rate?: number
  group_ids?: number[]
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
})

const hasEnabledField = computed(() => form.updateHost || form.updateRate || form.updateGroups)

function resetForm() {
  form.updateHost = false
  form.host = ''
  form.updateRate = false
  form.rate = 1
  form.updateGroups = false
  form.groupIds = []
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
    group_ids: form.updateGroups ? [...form.groupIds] : undefined,
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
    </div>

    <template #footer>
      <div class="batch-footer">
        <span class="batch-footer__hint">批量修改不会影响端口、协议配置与显隐状态。</span>
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
