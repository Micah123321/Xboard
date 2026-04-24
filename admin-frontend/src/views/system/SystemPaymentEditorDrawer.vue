<script setup lang="ts">
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { getPaymentForm, savePayment } from '@/api/admin'
import type {
  AdminPaymentConfigFields,
  AdminPaymentListItem,
} from '@/types/api'
import {
  createEmptyPaymentForm,
  extractPaymentConfigValues,
  normalizePaymentConfigFields,
  toPaymentFormModel,
  toPaymentSavePayload,
  type PaymentFormModel,
} from '@/utils/payments'

const props = defineProps<{
  visible: boolean
  mode: 'create' | 'edit'
  payment?: AdminPaymentListItem | null
  paymentMethods: string[]
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  success: [message: string]
}>()

const formRef = ref<FormInstance>()
const submitting = ref(false)
const configLoading = ref(false)
const hydrating = ref(false)
const currentFields = ref<AdminPaymentConfigFields>({})
const initialPaymentMethod = ref('')
const form = reactive<PaymentFormModel>(createEmptyPaymentForm())

const drawerTitle = computed(() => props.mode === 'create' ? '添加支付方式' : '编辑支付方式')
const configEntries = computed(() => Object.entries(currentFields.value))
const iconPreview = computed(() => form.icon.trim())

const rules = computed<FormRules<PaymentFormModel>>(() => ({
  name: [{ required: true, message: '请输入显示名称', trigger: 'blur' }],
  payment: [{ required: true, message: '请选择支付接口', trigger: 'change' }],
  notifyDomain: [
    {
      validator: (_rule, value, callback) => {
        const normalized = String(value || '').trim()
        if (!normalized) {
          callback()
          return
        }

        try {
          const target = new URL(normalized)
          if (!/^https?:$/.test(target.protocol)) {
            callback(new Error('通知域名仅支持 http 或 https'))
            return
          }
          callback()
        } catch {
          callback(new Error('请输入有效的通知域名'))
        }
      },
      trigger: 'blur',
    },
  ],
  handlingFeePercent: [
    {
      validator: (_rule, value, callback) => {
        if (value === null || value === undefined || value === '') {
          callback()
          return
        }

        const numeric = Number(value)
        if (!Number.isFinite(numeric) || numeric < 0 || numeric > 100) {
          callback(new Error('百分比手续费需在 0-100 之间'))
          return
        }
        callback()
      },
      trigger: 'blur',
    },
  ],
  handlingFeeFixed: [
    {
      validator: (_rule, value, callback) => {
        if (value === null || value === undefined || value === '') {
          callback()
          return
        }

        const numeric = Number(value)
        if (!Number.isFinite(numeric) || numeric < 0 || !Number.isInteger(numeric)) {
          callback(new Error('固定手续费需为大于等于 0 的整数'))
          return
        }
        callback()
      },
      trigger: 'blur',
    },
  ],
}))

function closeDrawer() {
  emit('update:visible', false)
}

async function loadDynamicConfig(method: string, paymentId?: number) {
  if (!method) {
    currentFields.value = {}
    form.config = {}
    return
  }

  configLoading.value = true
  try {
    const response = await getPaymentForm({
      payment: method,
      ...(paymentId ? { id: paymentId } : {}),
    })
    const normalizedFields = normalizePaymentConfigFields(response.data)
    currentFields.value = normalizedFields
    form.config = extractPaymentConfigValues(normalizedFields)
  } catch (error) {
    currentFields.value = {}
    form.config = {}
    ElMessage.error(error instanceof Error ? error.message : '支付接口配置加载失败')
  } finally {
    configLoading.value = false
  }
}

async function initializeForm() {
  hydrating.value = true
  Object.assign(form, createEmptyPaymentForm())
  Object.assign(form, toPaymentFormModel(props.payment))
  initialPaymentMethod.value = props.payment?.payment || form.payment
  await loadDynamicConfig(form.payment, props.payment?.id)
  await nextTick()
  formRef.value?.clearValidate()
  hydrating.value = false
}

function updateConfigValue(key: string, value: string) {
  form.config = {
    ...form.config,
    [key]: value,
  }
}

async function reloadCurrentConfig() {
  if (!form.payment) {
    return
  }
  const paymentId = props.mode === 'edit' && form.payment === initialPaymentMethod.value
    ? props.payment?.id
    : undefined
  await loadDynamicConfig(form.payment, paymentId)
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

  if (configLoading.value) {
    ElMessage.warning('支付接口配置仍在加载，请稍后再试')
    return
  }

  if (!configEntries.value.length) {
    ElMessage.error('当前支付接口配置未加载成功，请重新选择支付接口')
    return
  }

  submitting.value = true
  try {
    await savePayment(toPaymentSavePayload(form, currentFields.value))
    const message = props.mode === 'create' ? '支付方式已创建' : '支付方式已更新'
    ElMessage.success(message)
    emit('success', message)
    closeDrawer()
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '支付方式保存失败')
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
    void initializeForm()
  },
)

watch(
  () => form.payment,
  (nextValue, previousValue) => {
    if (!props.visible || hydrating.value || !nextValue || nextValue === previousValue) {
      return
    }

    const paymentId = props.mode === 'edit' && nextValue === initialPaymentMethod.value
      ? props.payment?.id
      : undefined
    void loadDynamicConfig(nextValue, paymentId)
  },
)
</script>

<template>
  <ElDrawer
    :model-value="props.visible"
    :title="drawerTitle"
    size="min(560px, 100vw)"
    destroy-on-close
    class="payment-editor-drawer"
    @close="closeDrawer"
    @update:model-value="emit('update:visible', $event)"
  >
    <div class="drawer-shell">
      <div class="drawer-copy">
        <p>支付配置</p>
        <h2>{{ drawerTitle }}</h2>
        <span>根据当前 Laravel `/payment/*` 接口维护支付方式与网关参数。</span>
      </div>

      <ElForm
        ref="formRef"
        :model="form"
        :rules="rules"
        label-position="top"
        class="drawer-form"
      >
        <div class="drawer-grid">
          <ElFormItem label="显示名称" prop="name">
            <ElInput v-model="form.name" placeholder="请输入支付方式显示名称" />
          </ElFormItem>

          <ElFormItem label="图标URL">
            <ElInput v-model="form.icon" placeholder="https://cdn.example.com/payment.png" />
            <div v-if="iconPreview" class="icon-preview">
              <img :src="iconPreview" alt="支付图标预览" />
              <span>图标预览</span>
            </div>
          </ElFormItem>

          <ElFormItem label="通知域名" prop="notifyDomain">
            <ElInput v-model="form.notifyDomain" placeholder="https://pay.example.com" />
            <p class="field-helper">仅填写通知域名与协议，实际回调路径会由后端自动拼接。</p>
          </ElFormItem>

          <ElFormItem label="百分比手续费 (%)" prop="handlingFeePercent">
            <ElInputNumber
              v-model="form.handlingFeePercent"
              :min="0"
              :max="100"
              :precision="2"
              :controls="false"
              class="full-width"
              placeholder="0-100"
            />
          </ElFormItem>

          <ElFormItem label="固定手续费" prop="handlingFeeFixed">
            <ElInputNumber
              v-model="form.handlingFeeFixed"
              :min="0"
              :precision="0"
              :controls="false"
              class="full-width"
              placeholder="请输入固定手续费"
            />
          </ElFormItem>

          <ElFormItem label="支付接口" prop="payment">
            <ElSelect v-model="form.payment" placeholder="请选择支付接口">
              <ElOption
                v-for="method in props.paymentMethods"
                :key="method"
                :label="method"
                :value="method"
              />
            </ElSelect>
          </ElFormItem>
        </div>

        <section class="config-panel" v-loading="configLoading">
          <header class="section-header">
            <div>
              <h3>支付配置</h3>
              <span>根据当前支付接口动态加载配置字段，保持与后端插件表单契约一致。</span>
            </div>

            <ElButton :disabled="!form.payment" @click="reloadCurrentConfig">
              重新拉取配置
            </ElButton>
          </header>

          <div v-if="!form.payment" class="config-empty">
            <strong>请选择支付接口</strong>
            <span>选择接口后会在这里加载对应的支付网关配置字段。</span>
          </div>

          <div v-else-if="configEntries.length" class="config-grid">
            <div
              v-for="[key, field] in configEntries"
              :key="key"
              class="config-field"
              :class="{ 'is-full': field.type === 'text' }"
            >
              <ElFormItem :label="field.label">
                <ElInput
                  v-if="field.type !== 'text'"
                  :model-value="form.config[key]"
                  :placeholder="field.placeholder || `请输入${field.label}`"
                  @update:model-value="updateConfigValue(key, String($event || ''))"
                />

                <ElInput
                  v-else
                  :model-value="form.config[key]"
                  type="textarea"
                  :rows="4"
                  :placeholder="field.placeholder || `请输入${field.label}`"
                  @update:model-value="updateConfigValue(key, String($event || ''))"
                />

                <p v-if="field.description" class="field-helper">
                  {{ field.description }}
                </p>
              </ElFormItem>
            </div>
          </div>

          <div v-else class="config-empty">
            <strong>当前接口未返回配置字段</strong>
            <span>请确认该支付插件已启用，或点击“重新拉取配置”重试。</span>
          </div>
        </section>
      </ElForm>
    </div>

    <template #footer>
      <div class="drawer-footer">
        <ElButton @click="closeDrawer">取消</ElButton>
        <ElButton type="primary" :loading="submitting" @click="handleSubmit">
          {{ props.mode === 'create' ? '提交' : '保存修改' }}
        </ElButton>
      </div>
    </template>
  </ElDrawer>
</template>

<style scoped lang="scss" src="./SystemPaymentEditorDrawer.scss"></style>
