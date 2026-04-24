<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { generateGiftCardCodes } from '@/api/admin'
import type { AdminGiftCardTemplateItem } from '@/types/api'

const props = defineProps<{
  visible: boolean
  templates: AdminGiftCardTemplateItem[]
}>()

const emit = defineEmits<{
  (e: 'update:visible', value: boolean): void
  (e: 'success', payload: { batchId: string }): void
}>()

const formRef = ref<FormInstance>()
const saving = ref(false)
const form = reactive({
  template_id: undefined as number | undefined,
  count: 10,
  prefix: 'GC',
  expires_hours: undefined as number | undefined,
  max_usage: 1,
})

const rules: FormRules<typeof form> = {
  template_id: [{ required: true, message: '请选择模板', trigger: 'change' }],
  count: [{ required: true, message: '请输入生成数量', trigger: 'blur' }],
}

function resetForm() {
  form.template_id = undefined
  form.count = 10
  form.prefix = 'GC'
  form.expires_hours = undefined
  form.max_usage = 1
}

function closeDialog() {
  emit('update:visible', false)
}

async function handleSubmit() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid || !form.template_id) {
    return
  }

  saving.value = true
  try {
    const response = await generateGiftCardCodes({
      template_id: form.template_id,
      count: form.count,
      prefix: form.prefix.trim() || 'GC',
      expires_hours: form.expires_hours,
      max_usage: form.max_usage,
    })
    ElMessage.success(`兑换码已生成，本次批次：${response.data.batch_id}`)
    emit('success', { batchId: response.data.batch_id })
    closeDialog()
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '兑换码生成失败')
  } finally {
    saving.value = false
  }
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
    :model-value="visible"
    title="生成兑换码"
    width="520px"
    @update:model-value="emit('update:visible', $event)"
  >
    <ElForm ref="formRef" :model="form" :rules="rules" label-position="top" class="batch-form">
      <ElFormItem label="模板" prop="template_id">
        <ElSelect v-model="form.template_id" placeholder="请选择一个礼品卡模板">
          <ElOption
            v-for="item in templates"
            :key="item.id"
            :label="item.name"
            :value="item.id"
          />
        </ElSelect>
      </ElFormItem>

      <div class="form-grid">
        <ElFormItem label="生成数量" prop="count">
          <ElInputNumber v-model="form.count" :min="1" :max="10000" class="number-input" />
        </ElFormItem>

        <ElFormItem label="最大使用次数">
          <ElInputNumber v-model="form.max_usage" :min="1" :max="1000" class="number-input" />
        </ElFormItem>

        <ElFormItem label="前缀">
          <ElInput v-model="form.prefix" maxlength="10" placeholder="例如 GC" />
        </ElFormItem>

        <ElFormItem label="有效期（小时）">
          <ElInputNumber v-model="form.expires_hours" :min="1" class="number-input" />
        </ElFormItem>
      </div>

      <ElAlert
        type="info"
        :closable="false"
        show-icon
        title="生成后可在兑换码管理中按批次导出文本文件，并继续做启停、编辑或删除操作。"
      />
    </ElForm>

    <template #footer>
      <div class="dialog-footer">
        <ElButton @click="closeDialog">取消</ElButton>
        <ElButton type="primary" :loading="saving" @click="handleSubmit">开始生成</ElButton>
      </div>
    </template>
  </ElDialog>
</template>

<style scoped lang="scss">
.batch-form {
  display: grid;
  gap: 18px;
}

.form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
}

.number-input {
  width: 100%;
}

.dialog-footer {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
}

@media (max-width: 640px) {
  .form-grid {
    grid-template-columns: 1fr;
  }
}
</style>
