<script setup lang="ts">
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { saveNodeRoute } from '@/api/admin'
import type { AdminNodeRouteItem } from '@/types/api'
import {
  createEmptyNodeRouteForm,
  getNodeRouteActionMeta,
  getNodeRouteActionValueLabel,
  getNodeRouteActionValuePlaceholder,
  NODE_ROUTE_ACTION_OPTIONS,
  parseRouteMatchLines,
  requiresNodeRouteActionValue,
  toNodeRouteFormModel,
  toNodeRouteSavePayload,
  type NodeRouteFormModel,
} from '@/utils/routes'

const props = defineProps<{
  visible: boolean
  mode: 'create' | 'edit'
  route?: AdminNodeRouteItem | null
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  success: [message: string]
}>()

const formRef = ref<FormInstance>()
const submitting = ref(false)
const form = reactive<NodeRouteFormModel>(createEmptyNodeRouteForm())

const dialogTitle = computed(() => props.mode === 'create' ? '添加路由' : '编辑路由')
const needsActionValue = computed(() => requiresNodeRouteActionValue(form.action))
const actionMeta = computed(() => getNodeRouteActionMeta(form.action))

function closeDialog() {
  emit('update:visible', false)
}

function syncForm() {
  Object.assign(form, toNodeRouteFormModel(props.route))
  if (!needsActionValue.value) {
    form.actionValue = ''
  }
}

function validateMatchText(_rule: unknown, value: string, callback: (error?: Error) => void) {
  if (parseRouteMatchLines(value).length === 0) {
    callback(new Error('请至少输入一条匹配规则'))
    return
  }

  callback()
}

function validateActionValue(_rule: unknown, value: string, callback: (error?: Error) => void) {
  if (needsActionValue.value && !value.trim()) {
    callback(new Error(`请输入${getNodeRouteActionValueLabel(form.action)}`))
    return
  }

  callback()
}

const rules = computed<FormRules<NodeRouteFormModel>>(() => ({
  remarks: [{ required: true, message: '请输入备注', trigger: 'blur' }],
  matchText: [{ validator: validateMatchText, trigger: 'blur' }],
  action: [{ required: true, message: '请选择动作', trigger: 'change' }],
  actionValue: [{ validator: validateActionValue, trigger: 'blur' }],
}))

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
    await saveNodeRoute(toNodeRouteSavePayload(form))
    const message = props.mode === 'create' ? '路由已创建' : '路由已更新'
    ElMessage.success(message)
    emit('success', message)
    closeDialog()
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '路由保存失败')
  } finally {
    submitting.value = false
  }
}

watch(
  () => [props.visible, props.route, props.mode],
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
  () => form.action,
  () => {
    if (!needsActionValue.value) {
      form.actionValue = ''
    }
  },
)
</script>

<template>
  <ElDialog
    :model-value="props.visible"
    :title="dialogTitle"
    width="min(640px, calc(100vw - 32px))"
    destroy-on-close
    class="node-route-editor-dialog"
    @close="closeDialog"
    @update:model-value="emit('update:visible', $event)"
  >
    <div class="dialog-shell">
      <div class="dialog-copy">
        <p>Node Routes</p>
        <h2>{{ dialogTitle }}</h2>
        <span>维护路由备注、匹配规则与动作配置；保存后会同步到节点侧使用的路由规则。</span>
      </div>

      <ElForm
        ref="formRef"
        :model="form"
        :rules="rules"
        label-position="top"
        class="dialog-form"
      >
        <ElFormItem label="备注" prop="remarks">
          <ElInput
            v-model="form.remarks"
            placeholder="例如：屏蔽广告、走指定 DNS"
            maxlength="80"
            show-word-limit
          />
        </ElFormItem>

        <ElFormItem label="匹配规则" prop="matchText">
          <ElInput
            v-model="form.matchText"
            type="textarea"
            :autosize="{ minRows: 5, maxRows: 8 }"
            placeholder="每行一条规则，例如：&#10;test.com&#10;*.apple.com"
          />
          <div class="field-help">
            <span>每行一条规则，保存时会自动去空与去重。</span>
          </div>
        </ElFormItem>

        <div class="dialog-grid">
          <ElFormItem label="动作" prop="action">
            <ElSelect v-model="form.action" placeholder="请选择动作">
              <ElOption
                v-for="option in NODE_ROUTE_ACTION_OPTIONS"
                :key="option.value"
                :label="option.label"
                :value="option.value"
              />
            </ElSelect>
          </ElFormItem>

          <ElFormItem
            v-if="needsActionValue"
            :label="getNodeRouteActionValueLabel(form.action)"
            prop="actionValue"
          >
            <ElInput
              v-model="form.actionValue"
              :placeholder="getNodeRouteActionValuePlaceholder(form.action)"
            />
          </ElFormItem>
        </div>

        <section class="action-panel">
          <div class="action-panel__main">
            <strong>当前动作</strong>
            <ElTag round effect="plain" :type="actionMeta.tagType">
              {{ actionMeta.label }}
            </ElTag>
          </div>
          <span v-if="needsActionValue">
            {{ getNodeRouteActionValueLabel(form.action) }} 会随当前路由一起下发到节点端。
          </span>
          <span v-else>
            当前动作不需要额外动作值，保存后会直接按策略执行。
          </span>
        </section>
      </ElForm>
    </div>

    <template #footer>
      <div class="dialog-footer">
        <ElButton @click="closeDialog">取消</ElButton>
        <ElButton type="primary" :loading="submitting" @click="handleSubmit">
          {{ props.mode === 'create' ? '提交' : '保存修改' }}
        </ElButton>
      </div>
    </template>
  </ElDialog>
</template>

<style scoped lang="scss" src="./NodeRouteEditorDialog.scss"></style>
