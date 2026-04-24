<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { saveServerGroup } from '@/api/admin'
import type { AdminServerGroupItem } from '@/types/api'

type DialogMode = 'create' | 'edit'

interface NodeGroupFormModel {
  name: string
}

const props = defineProps<{
  visible: boolean
  mode: DialogMode
  group: AdminServerGroupItem | null
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  success: []
}>()

const formRef = ref<FormInstance>()
const submitting = ref(false)
const form = reactive<NodeGroupFormModel>({
  name: '',
})

const dialogTitle = computed(() => props.mode === 'create' ? '添加权限组' : '编辑权限组')
const dialogDescription = computed(() => props.mode === 'create'
  ? '创建新的权限组，供节点、套餐与用户权限分配使用。'
  : '修改权限组信息，更新后会立即影响后台显示。')

const rules = computed<FormRules<NodeGroupFormModel>>(() => ({
  name: [{ required: true, message: '请输入权限组名称', trigger: 'blur' }],
}))

function resetForm() {
  form.name = ''
}

function closeDialog() {
  emit('update:visible', false)
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
    await saveServerGroup({
      id: props.mode === 'edit' ? props.group?.id : undefined,
      name: form.name.trim(),
    })

    ElMessage.success(props.mode === 'create' ? '权限组已创建' : '权限组已更新')
    emit('success')
    closeDialog()
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '权限组保存失败')
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
    form.name = props.group?.name ?? ''
    formRef.value?.clearValidate()
  },
  { immediate: true },
)
</script>

<template>
  <ElDialog
    :model-value="props.visible"
    :title="dialogTitle"
    width="min(480px, calc(100vw - 32px))"
    class="node-group-dialog"
    destroy-on-close
    @close="closeDialog"
    @update:model-value="emit('update:visible', $event)"
  >
    <div class="dialog-shell">
      <p class="dialog-description">{{ dialogDescription }}</p>

      <ElForm
        ref="formRef"
        :model="form"
        :rules="rules"
        label-position="top"
      >
        <ElFormItem label="组名称" prop="name">
          <ElInput
            v-model="form.name"
            maxlength="30"
            show-word-limit
            placeholder="请输入有意义的权限组名称"
          />
        </ElFormItem>
      </ElForm>
    </div>

    <template #footer>
      <div class="dialog-footer">
        <ElButton @click="closeDialog">取消</ElButton>
        <ElButton type="primary" :loading="submitting" @click="handleSubmit">
          {{ props.mode === 'create' ? '创建' : '更新' }}
        </ElButton>
      </div>
    </template>
  </ElDialog>
</template>

<style scoped>
.dialog-shell {
  display: grid;
  gap: 18px;
}

.dialog-description {
  color: var(--xboard-text-muted);
  line-height: 1.6;
}

.dialog-footer {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  width: 100%;
}
</style>
