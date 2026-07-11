<script setup lang="ts">
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { saveCoupon } from '@/api/admin'
import type { AdminCouponListItem, AdminPlanOption } from '@/types/api'
import {
  COUPON_PERIOD_OPTIONS,
  COUPON_TYPE_OPTIONS,
  createEmptyCouponForm,
  getCouponDateRangeError,
  getCouponTypeLabel,
  getCouponValueHelper,
  toCouponFormModel,
  toCouponSavePayload,
  type CouponFormModel,
} from '@/utils/coupons'

const props = defineProps<{
  visible: boolean
  mode: 'create' | 'edit'
  coupon?: AdminCouponListItem | null
  plans: AdminPlanOption[]
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  success: [message: string]
}>()

const formRef = ref<FormInstance>()
const submitting = ref(false)
const form = reactive<CouponFormModel>(createEmptyCouponForm())

const dialogTitle = computed(() => props.mode === 'create' ? '添加优惠券' : '编辑优惠券')
const valueHelper = computed(() => getCouponValueHelper(form.type))
const valueInputMax = computed(() => form.type === 1 ? undefined : 100)
const valueInputPrecision = computed(() => form.type === 1 ? 2 : 0)
const valueInputPlaceholder = computed(() => (
  form.type === 1 ? '减免金额（元）' : '减免百分比（1-100）'
))

const rules = computed<FormRules<CouponFormModel>>(() => ({
  name: [{ required: true, message: '请输入优惠券名称', trigger: 'blur' }],
  value: [
    {
      validator: (_rule, value, callback) => {
        const numeric = Number(value)
        if (!Number.isFinite(numeric) || numeric <= 0) {
          callback(new Error(`请输入有效的${getCouponTypeLabel(form.type)}值`))
          return
        }

        if (form.type === 2 && (numeric < 1 || numeric > 100)) {
          callback(new Error('比例减免请输入 1–100 的整数百分比（减免比例，不是几折）'))
          return
        }

        callback()
      },
      trigger: 'blur',
    },
  ],
  dateRange: [
    {
      validator: (_rule, value, callback) => {
        const error = getCouponDateRangeError(value)
        if (error) {
          callback(new Error(error))
          return
        }
        callback()
      },
      trigger: 'change',
    },
  ],
  code: [
    {
      validator: (_rule, value, callback) => {
        if (form.generateCount && form.generateCount > 1 && String(value || '').trim()) {
          callback(new Error('批量生成时请留空自定义优惠码'))
          return
        }
        callback()
      },
      trigger: 'blur',
    },
  ],
}))

function closeDialog() {
  emit('update:visible', false)
}

function disableBeforeUnixEpoch(date: Date): boolean {
  return date.getTime() < 0 || Math.floor(date.getTime() / 1000) > 2147483647
}

function syncForm() {
  delete form.id
  Object.assign(form, toCouponFormModel(props.coupon))
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
    await saveCoupon(toCouponSavePayload(form))
    const message = props.mode === 'create' ? '优惠券已创建' : '优惠券已更新'
    ElMessage.success(message)
    emit('success', message)
    closeDialog()
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '优惠券保存失败')
  } finally {
    submitting.value = false
  }
}

watch(
  () => [props.visible, props.coupon, props.mode],
  ([visible]) => {
    if (!visible) {
      return
    }

    syncForm()
    nextTick(() => {
      formRef.value?.clearValidate()
    })
  },
  { immediate: true },
)

watch(
  () => form.type,
  () => {
    nextTick(() => {
      formRef.value?.clearValidate(['value'])
    })
  },
)
</script>

<template>
  <ElDialog
    :model-value="props.visible"
    :title="dialogTitle"
    width="min(860px, calc(100vw - 32px))"
    top="5vh"
    destroy-on-close
    class="coupon-dialog"
    @close="closeDialog"
    @update:model-value="emit('update:visible', $event)"
  >
    <div class="dialog-shell">
      <div class="dialog-copy">
        <p>订阅管理</p>
        <h2>{{ dialogTitle }}</h2>
        <span>配置金额减免或比例减免；比例值是「减免百分比」，不是中文里的“几折”。</span>
      </div>

      <ElForm
        ref="formRef"
        :model="form"
        :rules="rules"
        label-position="top"
        class="dialog-form"
      >
        <div class="dialog-grid">
          <ElFormItem label="优惠券名称" prop="name">
            <ElInput v-model="form.name" placeholder="请输入优惠券名称" />
            <p class="field-helper">用于后台识别优惠活动，建议使用可读性更强的运营命名。</p>
          </ElFormItem>

          <ElFormItem label="批量生成数量">
            <ElInputNumber
              v-model="form.generateCount"
              :min="2"
              :max="500"
              :controls="false"
              class="full-width"
              placeholder="留空则仅生成单个"
            />
            <p class="field-helper">批量生成时会自动生成随机券码，最多支持 500 个。</p>
          </ElFormItem>

          <ElFormItem label="自定义优惠码" prop="code">
            <ElInput v-model="form.code" placeholder="留空则自动生成" />
            <p class="field-helper">单张优惠券可指定券码；批量生成时请保持为空。</p>
          </ElFormItem>

          <ElFormItem label="优惠方式与数值" prop="value">
            <div class="value-row">
              <ElSelect v-model="form.type" class="value-type">
                <ElOption
                  v-for="option in COUPON_TYPE_OPTIONS"
                  :key="option.value"
                  :label="option.label"
                  :value="option.value"
                />
              </ElSelect>

              <ElInputNumber
                v-model="form.value"
                :min="0"
                :max="valueInputMax"
                :precision="valueInputPrecision"
                :controls="false"
                class="value-input"
                :placeholder="valueInputPlaceholder"
              />
            </div>
            <p class="field-helper">{{ valueHelper }}</p>
          </ElFormItem>

          <ElFormItem label="优惠券有效期" prop="dateRange" class="full-span">
            <ElDatePicker
              v-model="form.dateRange"
              type="datetimerange"
              start-placeholder="开始时间"
              end-placeholder="结束时间"
              format="YYYY-MM-DD HH:mm:ss"
              value-format="x"
              :disabled-date="disableBeforeUnixEpoch"
              class="full-width"
            />
            <p class="field-helper">列表中的有效期和过期提示将直接依据这里的时间范围计算。</p>
          </ElFormItem>

          <ElFormItem label="最大使用次数">
            <ElInputNumber
              v-model="form.limitUse"
              :min="0"
              :controls="false"
              class="full-width"
              placeholder="留空则不限"
            />
            <p class="field-helper">优惠券总可用次数；用户下单创建时扣减，留空表示不限次数。</p>
          </ElFormItem>

          <ElFormItem label="每个用户可使用次数">
            <ElInputNumber
              v-model="form.limitUseWithUser"
              :min="0"
              :controls="false"
              class="full-width"
              placeholder="留空则不限"
            />
            <p class="field-helper">限制单个用户累计成功使用同一优惠券的次数（不含已取消订单）。</p>
          </ElFormItem>

          <ElFormItem label="指定周期">
            <ElSelect
              v-model="form.limitPeriod"
              multiple
              collapse-tags
              collapse-tags-tooltip
              clearable
              placeholder="留空则不限周期"
            >
              <ElOption
                v-for="option in COUPON_PERIOD_OPTIONS"
                :key="option.value"
                :label="option.label"
                :value="option.value"
              />
            </ElSelect>
            <p class="field-helper">仅允许在所选订阅周期中使用，留空表示所有周期均可使用。</p>
          </ElFormItem>

          <ElFormItem label="指定订阅">
            <ElSelect
              v-model="form.limitPlanIds"
              multiple
              collapse-tags
              collapse-tags-tooltip
              clearable
              placeholder="留空则不限订阅"
            >
              <ElOption
                v-for="plan in props.plans"
                :key="plan.id"
                :label="plan.name"
                :value="plan.id"
              />
            </ElSelect>
            <p class="field-helper">只在指定套餐下生效；升级单按目标套餐校验，留空表示不限套餐。</p>
          </ElFormItem>
        </div>
      </ElForm>
    </div>

    <template #footer>
      <div class="dialog-footer">
        <ElButton @click="closeDialog">取消</ElButton>
        <ElButton type="primary" :loading="submitting" @click="handleSubmit">
          {{ props.mode === 'create' ? '确认' : '保存修改' }}
        </ElButton>
      </div>
    </template>
  </ElDialog>
</template>

<style scoped lang="scss" src="./CouponEditorDialog.scss"></style>
