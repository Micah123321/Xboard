<script setup lang="ts">
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { saveKnowledge } from '@/api/admin'
import type { AdminKnowledgeDetail } from '@/types/api'
import {
  KNOWLEDGE_LANGUAGE_OPTIONS,
  createEmptyKnowledgeForm,
  renderKnowledgeBody,
  toKnowledgeFormModel,
  toKnowledgeSavePayload,
  type KnowledgeFormModel,
} from '@/utils/knowledge'

const props = defineProps<{
  visible: boolean
  mode: 'create' | 'edit'
  knowledge?: AdminKnowledgeDetail | null
  categories: string[]
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  success: [message: string]
}>()

const formRef = ref<FormInstance>()
const submitting = ref(false)
const previewVisible = ref(false)
const contentEditorRef = ref<HTMLTextAreaElement | null>(null)
const form = reactive<KnowledgeFormModel>(createEmptyKnowledgeForm())

const dialogTitle = computed(() => props.mode === 'create' ? '添加知识' : '编辑知识')
const renderedBody = computed(() => renderKnowledgeBody(form.body))
const bodyLength = computed(() => form.body.trim().length)
const categoryOptions = computed(() => props.categories.filter(Boolean))

const rules = computed<FormRules<KnowledgeFormModel>>(() => ({
  title: [{ required: true, message: '请输入标题', trigger: 'blur' }],
  category: [{ required: true, message: '请输入分类', trigger: 'change' }],
  language: [{ required: true, message: '请选择语言', trigger: 'change' }],
  body: [{ required: true, message: '请输入内容', trigger: 'blur' }],
}))

function closeDialog() {
  emit('update:visible', false)
}

function syncForm() {
  Object.assign(form, createEmptyKnowledgeForm(), toKnowledgeFormModel(props.knowledge))
  previewVisible.value = false
}

function insertSnippet(prefix: string, suffix = '', placeholder = '内容') {
  const textarea = contentEditorRef.value
  const content = form.body
  if (!textarea) {
    form.body = `${content}${prefix}${placeholder}${suffix}`
    return
  }

  const start = textarea.selectionStart
  const end = textarea.selectionEnd
  const selected = content.slice(start, end) || placeholder
  form.body = `${content.slice(0, start)}${prefix}${selected}${suffix}${content.slice(end)}`

  nextTick(() => {
    textarea.focus()
    const cursor = start + prefix.length + selected.length + suffix.length
    textarea.setSelectionRange(cursor, cursor)
  })
}

function insertTextAtCursor(text: string, cursorOffsetFromEnd = 0) {
  const textarea = contentEditorRef.value
  const content = form.body
  if (!textarea) {
    form.body = `${content}${text}`
    return
  }

  const start = textarea.selectionStart
  const end = textarea.selectionEnd
  form.body = `${content.slice(0, start)}${text}${content.slice(end)}`

  nextTick(() => {
    textarea.focus()
    const cursor = start + text.length - cursorOffsetFromEnd
    textarea.setSelectionRange(cursor, cursor)
  })
}

function insertHeading() {
  insertSnippet('# ', '', '一级标题')
}

function insertList() {
  insertSnippet('- ', '', '列表项')
}

function insertQuote() {
  insertSnippet('> ', '', '引用内容')
}

function insertCode() {
  insertSnippet('`', '`', '代码')
}

function insertLink() {
  insertTextAtCursor('[链接文本](https://)', 1)
}

function insertImage() {
  insertTextAtCursor('![图片描述](https://)', 1)
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
    await saveKnowledge(toKnowledgeSavePayload(form))
    const message = props.mode === 'create' ? '知识已创建' : '知识已更新'
    ElMessage.success(message)
    emit('success', message)
    closeDialog()
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '知识保存失败')
  } finally {
    submitting.value = false
  }
}

watch(
  () => [props.visible, props.knowledge, props.mode],
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
    class="knowledge-editor-dialog"
    @close="closeDialog"
    @update:model-value="emit('update:visible', $event)"
  >
    <div class="dialog-shell">
      <div class="dialog-copy">
        <p>系统管理</p>
        <h2>{{ dialogTitle }}</h2>
        <span>发布或维护知识库文案，支持分类、语言、显示状态和 Markdown 正文编辑。</span>
      </div>

      <ElForm
        ref="formRef"
        :model="form"
        :rules="rules"
        label-position="top"
        class="dialog-form"
      >
        <div class="dialog-grid">
          <ElFormItem label="标题" prop="title" class="is-full">
            <ElInput v-model="form.title" placeholder="请输入标题" />
          </ElFormItem>

          <ElFormItem label="分类" prop="category">
            <ElSelect
              v-model="form.category"
              filterable
              allow-create
              default-first-option
              placeholder="请选择或输入分类"
            >
              <ElOption
                v-for="item in categoryOptions"
                :key="item"
                :label="item"
                :value="item"
              />
            </ElSelect>
          </ElFormItem>

          <ElFormItem label="语言" prop="language">
            <ElSelect v-model="form.language" placeholder="请选择语言">
              <ElOption
                v-for="item in KNOWLEDGE_LANGUAGE_OPTIONS"
                :key="item.value"
                :label="item.label"
                :value="item.value"
              />
            </ElSelect>
          </ElFormItem>
        </div>

        <section class="visibility-panel">
          <div class="visibility-copy">
            <strong>显示状态</strong>
            <span>关闭后仍保留内容，但不会在用户侧展示。</span>
          </div>

          <ElSwitch v-model="form.show" />
        </section>

        <ElFormItem label="内容" prop="body">
          <section class="editor-panel">
            <header class="editor-header">
              <div>
                <h3>正文编辑</h3>
                <span>采用轻量 Markdown 编辑方案，适合教程、常见问题和知识说明文档。</span>
              </div>

              <div class="editor-actions">
                <span class="editor-counter">{{ bodyLength }} 字</span>
                <ElButton @click="previewVisible = !previewVisible">
                  {{ previewVisible ? '继续编辑' : '显示预览' }}
                </ElButton>
              </div>
            </header>

            <div class="editor-toolbar">
              <button type="button" @click="insertHeading">H1</button>
              <button type="button" @click="insertSnippet('**', '**', '加粗文本')">B</button>
              <button type="button" @click="insertSnippet('*', '*', '斜体文本')">I</button>
              <button type="button" @click="insertSnippet('<u>', '</u>', '下划线')">U</button>
              <button type="button" @click="insertList">列表</button>
              <button type="button" @click="insertQuote">引用</button>
              <button type="button" @click="insertCode">代码</button>
              <button type="button" @click="insertLink">链接</button>
              <button type="button" @click="insertImage">图片</button>
              <button type="button" @click="insertTextAtCursor('<br>')">换行</button>
            </div>

            <div
              v-if="previewVisible"
              class="editor-preview markdown-body"
              v-html="renderedBody"
            />
            <textarea
              v-else
              ref="contentEditorRef"
              v-model="form.body"
              class="editor-textarea"
              placeholder="请输入知识内容，支持 Markdown 与基础 HTML。"
            />
          </section>
        </ElFormItem>
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

<style scoped lang="scss" src="./KnowledgeEditorDialog.scss"></style>
