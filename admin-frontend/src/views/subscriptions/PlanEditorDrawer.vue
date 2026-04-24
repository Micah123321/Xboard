<script setup lang="ts">
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { savePlan } from '@/api/admin'
import type { AdminPlanListItem, AdminServerGroupItem } from '@/types/api'
import {
  DEFAULT_PLAN_DESCRIPTION_TEMPLATE,
  PLAN_PRICE_PERIODS,
  RESET_TRAFFIC_METHOD_OPTIONS,
  createEmptyPlanForm,
  normalizePlanTag,
  renderPlanContent,
  sanitizePlanPriceInput,
  toPlanFormModel,
  toPlanSavePayload,
  type PlanFormModel,
} from '@/utils/plans'

const props = defineProps<{
  visible: boolean
  mode: 'create' | 'edit'
  plan?: AdminPlanListItem | null
  groups: AdminServerGroupItem[]
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  success: [message: string]
}>()

const formRef = ref<FormInstance>()
const submitting = ref(false)
const previewVisible = ref(false)
const tagInput = ref('')
const contentEditorRef = ref<HTMLTextAreaElement | null>(null)
const form = reactive<PlanFormModel>(createEmptyPlanForm())

const drawerTitle = computed(() => props.mode === 'create' ? '添加套餐' : '编辑套餐')
const renderedContent = computed(() => renderPlanContent(form.content))

const rules = computed<FormRules<PlanFormModel>>(() => ({
  name: [{ required: true, message: '请输入套餐名称', trigger: 'blur' }],
  transferEnableGb: [
    {
      validator: (_rule, value, callback) => {
        if (!Number.isFinite(Number(value)) || Number(value) < 1) {
          callback(new Error('请输入大于等于 1 的流量值'))
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

function syncForm() {
  Object.assign(form, toPlanFormModel(props.plan))
  tagInput.value = ''
  previewVisible.value = false
}

function handleTagConfirm() {
  const nextTag = normalizePlanTag(tagInput.value)
  if (!nextTag) {
    tagInput.value = ''
    return
  }

  if (!form.tags.includes(nextTag)) {
    form.tags.push(nextTag)
  }
  tagInput.value = ''
}

function removeTag(tag: string) {
  form.tags = form.tags.filter((item) => item !== tag)
}

function applyTemplate() {
  if (!form.content.trim()) {
    form.content = DEFAULT_PLAN_DESCRIPTION_TEMPLATE
    return
  }

  if (!form.content.includes(DEFAULT_PLAN_DESCRIPTION_TEMPLATE)) {
    form.content = `${form.content.trim()}\n\n${DEFAULT_PLAN_DESCRIPTION_TEMPLATE}`
  }
}

function insertSnippet(prefix: string, suffix = '', placeholder = '内容') {
  const textarea = contentEditorRef.value
  if (!textarea) {
    form.content = `${form.content}${prefix}${placeholder}${suffix}`
    return
  }

  const start = textarea.selectionStart
  const end = textarea.selectionEnd
  const selected = form.content.slice(start, end) || placeholder
  form.content = `${form.content.slice(0, start)}${prefix}${selected}${suffix}${form.content.slice(end)}`

  nextTick(() => {
    textarea.focus()
    const cursor = start + prefix.length + selected.length + suffix.length
    textarea.setSelectionRange(cursor, cursor)
  })
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
    await savePlan(toPlanSavePayload(form))
    const message = props.mode === 'create' ? '套餐已创建' : '套餐已更新'
    ElMessage.success(message)
    emit('success', message)
    closeDrawer()
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '套餐保存失败')
  } finally {
    submitting.value = false
  }
}

watch(
  () => [props.visible, props.plan, props.mode],
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
  <ElDrawer
    :model-value="props.visible"
    :title="drawerTitle"
    size="min(560px, 100vw)"
    destroy-on-close
    class="plan-editor-drawer"
    @close="closeDrawer"
    @update:model-value="emit('update:visible', $event)"
  >
    <div class="drawer-shell">
      <div class="drawer-copy">
        <p>订阅管理</p>
        <h2>{{ drawerTitle }}</h2>
        <span>根据现有 `plan/*` 接口维护套餐结构、价格与说明内容。</span>
      </div>

      <ElForm
        ref="formRef"
        :model="form"
        :rules="rules"
        label-position="top"
        class="drawer-form"
      >
        <div class="drawer-grid">
          <ElFormItem label="套餐名称" prop="name">
            <ElInput v-model="form.name" placeholder="请输入套餐名称" />
          </ElFormItem>
          <ElFormItem label="标签">
            <div class="tag-input-shell">
              <div v-if="form.tags.length" class="tag-list">
                <ElTag
                  v-for="tag in form.tags"
                  :key="tag"
                  closable
                  effect="plain"
                  round
                  @close="removeTag(tag)"
                >
                  {{ tag }}
                </ElTag>
              </div>
              <ElInput
                v-model="tagInput"
                placeholder="输入标签后按回车确认"
                @keyup.enter.prevent="handleTagConfirm"
                @blur="handleTagConfirm"
              />
            </div>
          </ElFormItem>
        </div>

        <div class="drawer-grid">
          <ElFormItem label="服务器分组">
            <ElSelect v-model="form.groupId" clearable placeholder="请选择分组">
              <ElOption
                v-for="group in props.groups"
                :key="group.id"
                :label="group.name"
                :value="group.id"
              />
            </ElSelect>
          </ElFormItem>

          <ElFormItem label="流量" prop="transferEnableGb">
            <ElInputNumber
              v-model="form.transferEnableGb"
              :min="1"
              :controls="false"
              class="full-width"
            />
          </ElFormItem>

          <ElFormItem label="速度限制">
            <ElInputNumber
              v-model="form.speedLimit"
              :min="0"
              :controls="false"
              class="full-width"
              placeholder="请输入速度限制"
            />
          </ElFormItem>

          <ElFormItem label="设备限制">
            <ElInputNumber
              v-model="form.deviceLimit"
              :min="0"
              :controls="false"
              class="full-width"
              placeholder="请输入设备限制"
            />
          </ElFormItem>

          <ElFormItem label="容量限制">
            <ElInputNumber
              v-model="form.capacityLimit"
              :min="0"
              :controls="false"
              class="full-width"
              placeholder="请输入容量限制"
            />
          </ElFormItem>

          <ElFormItem label="流量重置方式">
            <ElSelect v-model="form.resetTrafficMethod" placeholder="请选择重置方式">
              <ElOption
                v-for="option in RESET_TRAFFIC_METHOD_OPTIONS"
                :key="String(option.value)"
                :label="option.label"
                :value="option.value"
              />
            </ElSelect>
          </ElFormItem>
        </div>

        <section class="price-panel">
          <header class="section-header">
            <div>
              <h3>价格设置</h3>
              <span>留空表示该周期不开放购买。</span>
            </div>
          </header>

          <div class="price-grid">
            <ElFormItem
              v-for="period in PLAN_PRICE_PERIODS"
              :key="period.key"
              :label="period.label"
            >
              <ElInput
                :model-value="form.prices[period.key]"
                placeholder="请输入价格"
                @update:model-value="form.prices[period.key] = sanitizePlanPriceInput($event)"
              />
            </ElFormItem>
          </div>
        </section>

        <section class="description-panel">
          <header class="section-header">
            <div>
              <h3>套餐说明</h3>
              <span>支持 Markdown 与基础 HTML 换行。</span>
            </div>

            <div class="section-actions">
              <ElButton @click="applyTemplate">使用模板</ElButton>
              <ElButton @click="previewVisible = !previewVisible">
                {{ previewVisible ? '继续编辑' : '显示预览' }}
              </ElButton>
            </div>
          </header>

          <div class="editor-toolbar">
            <button type="button" @click="insertSnippet('**', '**', '加粗文本')">B</button>
            <button type="button" @click="insertSnippet('*', '*', '斜体文本')">I</button>
            <button type="button" @click="insertSnippet('<u>', '</u>', '下划线文本')">U</button>
            <button type="button" @click="insertSnippet('- ', '', '列表项')">列表</button>
            <button type="button" @click="insertSnippet('> ', '', '引用内容')">引用</button>
            <button type="button" @click="insertSnippet('`', '`', '代码')">代码</button>
            <button type="button" @click="insertSnippet('[', '](https://)', '链接文本')">链接</button>
            <button type="button" @click="insertSnippet('<br>', '', '')">换行</button>
          </div>

          <div v-if="previewVisible" class="description-preview markdown-body" v-html="renderedContent" />
          <textarea
            v-else
            ref="contentEditorRef"
            v-model="form.content"
            class="description-editor"
            placeholder="请输入套餐说明，支持 Markdown 或 <br> 换行"
          />
        </section>
      </ElForm>
    </div>

    <template #footer>
      <div class="drawer-footer">
        <ElCheckbox v-if="props.mode === 'edit'" v-model="form.forceUpdate">
          强制更新用户套餐
        </ElCheckbox>
        <span v-else />

        <div class="drawer-actions">
          <ElButton @click="closeDrawer">取消</ElButton>
          <ElButton type="primary" :loading="submitting" @click="handleSubmit">
            {{ props.mode === 'create' ? '提交' : '保存修改' }}
          </ElButton>
        </div>
      </div>
    </template>
  </ElDrawer>
</template>

<style scoped lang="scss" src="./PlanEditorDrawer.scss"></style>
