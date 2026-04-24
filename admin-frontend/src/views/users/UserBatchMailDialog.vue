<script setup lang="ts">
import { reactive, watch } from 'vue'

const props = defineProps<{
  visible: boolean
  loading: boolean
  targetLabel: string
}>()

const emit = defineEmits<{
  (e: 'update:visible', value: boolean): void
  (e: 'submit', value: { subject: string; content: string }): void
}>()

const form = reactive({
  subject: '',
  content: '',
})

function resetForm() {
  form.subject = ''
  form.content = ''
}

function closeDialog() {
  emit('update:visible', false)
}

function handleSubmit() {
  emit('submit', {
    subject: form.subject.trim(),
    content: form.content.trim(),
  })
}

watch(() => props.visible, (visible) => {
  if (visible) {
    resetForm()
  }
})
</script>

<template>
  <ElDialog
    :model-value="visible"
    width="620px"
    destroy-on-close
    class="batch-mail-dialog"
    @close="closeDialog"
  >
    <template #header>
      <div class="dialog-header">
        <h2>发送邮件</h2>
        <p>邮件将发送给：{{ targetLabel }}</p>
      </div>
    </template>

    <div class="dialog-body">
      <ElForm label-position="top">
        <ElFormItem label="邮件主题" required>
          <ElInput v-model="form.subject" maxlength="120" show-word-limit placeholder="请输入邮件主题" />
        </ElFormItem>

        <ElFormItem label="邮件内容" required>
          <ElInput
            v-model="form.content"
            type="textarea"
            :rows="8"
            maxlength="5000"
            show-word-limit
            placeholder="请输入要发送给用户的内容"
          />
        </ElFormItem>

        <p class="helper-text">建议在执行前再次确认筛选范围，避免误发给不需要通知的用户。</p>
      </ElForm>
    </div>

    <template #footer>
      <div class="dialog-footer">
        <ElButton @click="closeDialog">取消</ElButton>
        <ElButton
          type="primary"
          :loading="loading"
          :disabled="!form.subject.trim() || !form.content.trim()"
          @click="handleSubmit"
        >
          发送邮件
        </ElButton>
      </div>
    </template>
  </ElDialog>
</template>

<style scoped lang="scss">
.dialog-header h2 {
  margin: 0;
  font-size: 24px;
  color: var(--xboard-text-strong);
}

.dialog-header p {
  margin: 8px 0 0;
  color: var(--xboard-text-secondary);
}

.dialog-body {
  padding-top: 4px;
}

.dialog-footer {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
}

.helper-text {
  margin: 0;
  color: var(--xboard-text-muted);
  font-size: 12px;
  line-height: 1.5;
}
</style>
