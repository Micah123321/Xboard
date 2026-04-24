<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { assignOrder } from '@/api/admin'
import type { AdminPlanListItem } from '@/types/api'
import {
  getAssignablePeriods,
  orderAmountToYuan,
  yuanToOrderAmount,
} from '@/utils/orders'

interface AssignOrderFormModel {
  email: string
  planId: number | null
  period: string
  totalAmountYuan: number | null
}

const props = defineProps<{
  visible: boolean
  plans: AdminPlanListItem[]
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  success: [tradeNo: string]
}>()

const formRef = ref<FormInstance>()
const submitting = ref(false)
const form = reactive<AssignOrderFormModel>({
  email: '',
  planId: null,
  period: '',
  totalAmountYuan: null,
})

const periodOptions = computed(() => {
  const activePlan = props.plans.find((item) => item.id === form.planId) ?? null
  return getAssignablePeriods(activePlan)
})

const rules = computed<FormRules<AssignOrderFormModel>>(() => ({
  email: [
    { required: true, message: '请输入用户邮箱', trigger: 'blur' },
    { type: 'email', message: '请输入有效邮箱', trigger: ['blur', 'change'] },
  ],
  planId: [{ required: true, message: '请选择订阅计划', trigger: 'change' }],
  period: [{ required: true, message: '请选择周期', trigger: 'change' }],
  totalAmountYuan: [{ required: true, message: '请输入支付金额', trigger: 'blur' }],
}))

function resetForm() {
  form.email = ''
  form.planId = null
  form.period = ''
  form.totalAmountYuan = null
}

function closeDrawer() {
  emit('update:visible', false)
}

function syncAmountFromPeriod(periodValue: string) {
  const matched = periodOptions.value.find((item) => item.value === periodValue)
  form.totalAmountYuan = matched ? orderAmountToYuan(yuanToOrderAmount(matched.amount)) : null
}

async function handleSubmit() {
  const instance = formRef.value
  if (!instance) {
    return
  }

  const valid = await instance.validate().catch(() => false)
  if (!valid) {
    return
  }

  submitting.value = true
  try {
    const response = await assignOrder({
      email: form.email.trim(),
      plan_id: Number(form.planId),
      period: form.period,
      total_amount: yuanToOrderAmount(form.totalAmountYuan),
    })

    ElMessage.success('订单已分配')
    emit('success', response.data)
    closeDrawer()
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '订单分配失败')
  } finally {
    submitting.value = false
  }
}

watch(
  () => props.visible,
  (visible) => {
    if (!visible) {
      return
    }

    resetForm()
    form.planId = props.plans[0]?.id ?? null
    const firstPeriod = getAssignablePeriods(props.plans[0] ?? null)[0]
    if (firstPeriod) {
      form.period = firstPeriod.value
      syncAmountFromPeriod(firstPeriod.value)
    }
    formRef.value?.clearValidate()
  },
  { immediate: true },
)

watch(
  () => form.planId,
  (planId) => {
    const activePlan = props.plans.find((item) => item.id === planId) ?? null
    const firstPeriod = getAssignablePeriods(activePlan)[0]
    form.period = firstPeriod?.value ?? ''
    syncAmountFromPeriod(form.period)
  },
)

watch(
  () => form.period,
  (period) => {
    if (!period) {
      form.totalAmountYuan = null
      return
    }

    syncAmountFromPeriod(period)
  },
)
</script>

<template>
  <ElDrawer
    :model-value="props.visible"
    title="分配订单"
    size="min(520px, 100vw)"
    class="order-assign-drawer"
    destroy-on-close
    @close="closeDrawer"
    @update:model-value="emit('update:visible', $event)"
  >
    <div class="drawer-shell">
      <div class="drawer-copy">
        <p>Order Assignment</p>
        <h2>为指定用户创建订单</h2>
        <span>先选择用户邮箱、订阅计划与周期，再按需调整支付金额。</span>
      </div>

      <ElForm
        ref="formRef"
        :model="form"
        :rules="rules"
        label-position="top"
        class="drawer-form"
      >
        <ElFormItem label="用户邮箱" prop="email">
          <ElInput v-model="form.email" placeholder="请输入要分配订单的用户邮箱" />
        </ElFormItem>

        <div class="drawer-grid">
          <ElFormItem label="订阅计划" prop="planId">
            <ElSelect v-model="form.planId" placeholder="请选择订阅计划">
              <ElOption
                v-for="plan in props.plans"
                :key="plan.id"
                :label="plan.name"
                :value="plan.id"
              />
            </ElSelect>
          </ElFormItem>

          <ElFormItem label="支付周期" prop="period">
            <ElSelect
              v-model="form.period"
              :disabled="periodOptions.length === 0"
              placeholder="请选择周期"
            >
              <ElOption
                v-for="item in periodOptions"
                :key="item.value"
                :label="item.label"
                :value="item.value"
              />
            </ElSelect>
          </ElFormItem>
        </div>

        <ElFormItem label="支付金额（元）" prop="totalAmountYuan">
          <ElInputNumber
            v-model="form.totalAmountYuan"
            :min="0"
            :precision="2"
            :step="0.1"
            :controls="false"
            style="width: 100%"
          />
        </ElFormItem>

        <ElAlert
          v-if="periodOptions.length === 0"
          type="warning"
          :closable="false"
          show-icon
          title="当前套餐没有可分配的有效周期，请先在套餐管理里配置售价。"
        />
        <p v-else class="amount-tip">
          默认金额会按所选周期售价回填，你也可以手动调整为运营侧需要的金额。
        </p>
      </ElForm>
    </div>

    <template #footer>
      <div class="drawer-actions">
        <ElButton @click="closeDrawer">取消</ElButton>
        <ElButton
          type="primary"
          :loading="submitting"
          :disabled="periodOptions.length === 0"
          @click="handleSubmit"
        >
          提交分配
        </ElButton>
      </div>
    </template>
  </ElDrawer>
</template>

<style scoped>
.drawer-shell {
  display: grid;
  gap: 20px;
}

.drawer-copy {
  display: grid;
  gap: 4px;
}

.drawer-copy p {
  font-size: 12px;
  color: var(--xboard-text-muted);
  letter-spacing: 0.18em;
  text-transform: uppercase;
}

.drawer-copy h2 {
  font-size: 30px;
  line-height: 1.08;
  color: var(--xboard-text-strong);
}

.drawer-copy span {
  color: var(--xboard-text-secondary);
  line-height: 1.47;
}

.drawer-form {
  display: grid;
  gap: 12px;
}

.drawer-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px 16px;
}

.amount-tip {
  color: var(--xboard-text-muted);
  line-height: 1.5;
}

.drawer-actions {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  width: 100%;
}

@media (max-width: 767px) {
  .drawer-grid {
    grid-template-columns: 1fr;
  }
}
</style>
