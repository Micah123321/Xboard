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
  getCouponTypeLabel,
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
const valueHelper = computed(() => form.type === 1 ? '按金额优惠时请输入元，例如 50 表示减免 50 元。' : '按比例优惠时请输入百分比，例如 85 表示 85 折。')

const rules = computed<FormRules<CouponFormModel>>(() => ({
  name: [{ required: true, message: '请输入优惠券名称', trigger: 'blur' }],
  value: [
    {
      validator: (_rule, value, callback) => {
        if (!Number.isFinite(Number(value)) || Number(value) <= 0) {
          callback(new Error(`请输入有效的${getCouponTypeLabel(form.type)}值`))
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
        if (!Array.isArray(value) || value.length !== 2 || !value[0] || !value[1]) {
          callback(new Error('请选择优惠券有效期'))
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

function syncForm() {
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
        <span>创建或调整优惠券策略，支持金额、折扣、批量生成与订阅限制。</span>
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

          <ElFormItem label="优惠券类型和值" prop="value">
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
                :precision="form.type === 1 ? 2 : 0"
                :controls="false"
                class="value-input"
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
              value-format="x"
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
            <p class="field-helper">设置优惠券总共可被使用的次数；留空表示不限次数。</p>
          </ElFormItem>

          <ElFormItem label="每个用户可使用次数">
            <ElInputNumber
              v-model="form.limitUseWithUser"
              :min="0"
              :controls="false"
              class="full-width"
              placeholder="留空则不限"
            />
            <p class="field-helper">用于限制单个用户重复使用同一优惠券的次数。</p>
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
            <p class="field-helper">只在指定套餐下生效，适合为活动套餐或定向促销设置专属优惠。</p>
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
