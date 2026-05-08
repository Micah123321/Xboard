<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import type { AdminUserListItem } from '@/types/api'
import { bytesToGigabytes } from '@/utils/users'

const props = defineProps<{
  visible: boolean
  loading?: boolean
  user?: AdminUserListItem | null
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  submit: [payload: { trafficGb: number }]
}>()

const form = reactive({
  trafficGb: 50,
})

const currentTemporaryGb = computed(() => bytesToGigabytes(props.user?.temporary_transfer_enable) ?? 0)
const nextTemporaryGb = computed(() => Number((currentTemporaryGb.value + Number(form.trafficGb || 0)).toFixed(2)))
const canSubmit = computed(() => Number.isFinite(Number(form.trafficGb)) && Number(form.trafficGb) > 0)

function closeDialog() {
  emit('update:visible', false)
}

function handleSubmit() {
  if (!canSubmit.value) {
    return
  }

  emit('submit', {
    trafficGb: Number(form.trafficGb),
  })
}

watch(
  () => props.visible,
  (visible) => {
    if (!visible) {
      return
    }

    form.trafficGb = 50
  },
)
</script>

<template>
  <ElDialog
    :model-value="props.visible"
    title="分配流量"
    width="min(420px, calc(100vw - 32px))"
    append-to-body
    class="temporary-traffic-dialog"
    @close="closeDialog"
    @update:model-value="emit('update:visible', $event)"
  >
    <div class="traffic-dialog-body">
      <div class="traffic-user">
        <span>用户</span>
        <strong>{{ props.user?.email || '-' }}</strong>
      </div>

      <ElForm label-position="top" class="traffic-form">
        <ElFormItem label="一次性流量">
          <ElInputNumber
            v-model="form.trafficGb"
            :min="0.01"
            :precision="2"
            :controls="false"
            :disabled="props.loading"
            class="traffic-input"
          />
          <span class="unit-label">GB</span>
        </ElFormItem>

      </ElForm>

      <div class="traffic-note">
        <strong>本期临时额度：{{ nextTemporaryGb }} GB</strong>
        <span>重置流量或更换套餐后不再保留。</span>
      </div>
    </div>

    <template #footer>
      <div class="traffic-actions">
        <ElButton :disabled="props.loading" @click="closeDialog">取消</ElButton>
        <ElButton type="primary" :loading="props.loading" :disabled="!canSubmit" @click="handleSubmit">确认分配</ElButton>
      </div>
    </template>
  </ElDialog>
</template>

<style scoped>
.traffic-dialog-body {
  display: grid;
  gap: 18px;
}

.traffic-user,
.traffic-note {
  display: grid;
  gap: 4px;
  padding: 14px 16px;
  border: 1px solid var(--xboard-border);
  border-radius: 12px;
  background: var(--xboard-surface-soft);
}

.traffic-user span,
.traffic-note span {
  color: var(--xboard-text-muted);
  font-size: 12px;
}

.traffic-user strong,
.traffic-note strong {
  color: var(--xboard-text-strong);
}

.traffic-form {
  display: grid;
  gap: 4px;
}

.traffic-input {
  width: calc(100% - 42px);
}

.unit-label {
  margin-left: 10px;
  color: var(--xboard-text-muted);
}

.traffic-actions {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
}
</style>
