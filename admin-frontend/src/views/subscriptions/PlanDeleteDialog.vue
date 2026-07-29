<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { AdminPlanListItem } from '@/types/api'

const props = defineProps<{
  visible: boolean
  plan: AdminPlanListItem | null
  plans: AdminPlanListItem[]
  submitting: boolean
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  submit: [replacementPlanId?: number]
}>()

const replacementPlanId = ref<number>()

const replacementPlans = computed(() => props.plans.filter((item) => item.id !== props.plan?.id))
const requiresReplacement = computed(() => Boolean(
  (props.plan?.users_count ?? 0) > 0 || (props.plan?.orders_count ?? 0) > 0,
))
const canSubmit = computed(() => !requiresReplacement.value || Boolean(replacementPlanId.value))

function closeDialog() {
  if (props.submitting) {
    return
  }
  emit('update:visible', false)
}

function updateVisibility(value: boolean) {
  if (!props.submitting) {
    emit('update:visible', value)
  }
}

function submit() {
  if (!canSubmit.value) {
    return
  }
  emit('submit', replacementPlanId.value)
}

watch(
  () => [props.visible, props.plan?.id],
  ([visible]) => {
    if (visible) {
      replacementPlanId.value = undefined
    }
  },
)
</script>

<template>
  <ElDialog
    :model-value="visible"
    width="min(520px, calc(100vw - 32px))"
    title="删除套餐"
    :show-close="!submitting"
    :close-on-click-modal="!submitting"
    :close-on-press-escape="!submitting"
    @close="closeDialog"
    @update:model-value="updateVisibility"
  >
    <div class="delete-plan-dialog">
      <p>删除套餐「{{ plan?.name }}」后无法恢复。</p>

      <template v-if="requiresReplacement">
        <ElAlert
          title="关联订单和用户会迁移到下方选定的套餐。"
          type="warning"
          :closable="false"
          show-icon
        />

        <ElFormItem label="替代套餐" required>
          <ElSelect v-model="replacementPlanId" placeholder="请选择替代套餐" class="full-width">
            <ElOption
              v-for="item in replacementPlans"
              :key="item.id"
              :label="`${item.name}（ID: ${item.id}）`"
              :value="item.id"
            />
          </ElSelect>
        </ElFormItem>
      </template>

      <ElAlert
        v-else
        title="该套餐当前没有关联订单或用户。"
        type="info"
        :closable="false"
        show-icon
      />
    </div>

    <template #footer>
      <ElButton :disabled="submitting" @click="closeDialog">取消</ElButton>
      <ElButton type="danger" :disabled="!canSubmit" :loading="submitting" @click="submit">
        确认删除
      </ElButton>
    </template>
  </ElDialog>
</template>

<style scoped lang="scss">
.delete-plan-dialog {
  display: grid;
  gap: 18px;
}

.delete-plan-dialog p {
  color: var(--xboard-text-secondary);
  line-height: 1.6;
}

.full-width {
  width: 100%;
}
</style>
