<script setup lang="ts">
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { saveNotice } from '@/api/admin'
import type { AdminNoticeItem } from '@/types/api'
import {
  createEmptyNoticeForm,
  normalizeNoticeTag,
  renderNoticeContent,
  toNoticeFormModel,
  toNoticeSavePayload,
  type NoticeFormModel,
} from '@/utils/notices'

const props = defineProps<{
  visible: boolean
  mode: 'create' | 'edit'
  notice?: AdminNoticeItem | null
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
const form = reactive<NoticeFormModel>(createEmptyNoticeForm())

const dialogTitle = computed(() => props.mode === 'create' ? '添加公告' : '编辑公告')
const renderedContent = computed(() => renderNoticeContent(form.content))

function validateImageUrl(_rule: unknown, value: string, callback: (error?: Error) => void) {
  if (!value.trim()) {
    callback()
    return
  }

  try {
    const url = new URL(value)
    if (!['http:', 'https:'].includes(url.protocol)) {
      callback(new Error('公告背景必须使用 http 或 https 链接'))
      return
    }
    callback()
  } catch {
    callback(new Error('公告背景链接格式不正确'))
  }
}

const rules = computed<FormRules<NoticeFormModel>>(() => ({
  title: [{ required: true, message: '请输入公告标题', trigger: 'blur' }],
  content: [{ required: true, message: '请输入公告内容', trigger: 'blur' }],
  imgUrl: [{ validator: validateImageUrl, trigger: 'blur' }],
}))

function closeDialog() {
  emit('update:visible', false)
}

function syncForm() {
  Object.assign(form, toNoticeFormModel(props.notice))
  tagInput.value = ''
  previewVisible.value = false
}

function handleTagConfirm() {
  const nextTag = normalizeNoticeTag(tagInput.value)
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
    await saveNotice(toNoticeSavePayload(form))
    const message = props.mode === 'create' ? '公告已创建' : '公告已更新'
    ElMessage.success(message)
    emit('success', message)
    closeDialog()
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '公告保存失败')
  } finally {
    submitting.value = false
  }
}

watch(
  () => [props.visible, props.notice, props.mode],
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
    width="min(820px, calc(100vw - 32px))"
    destroy-on-close
    class="notice-editor-dialog"
    @close="closeDialog"
    @update:model-value="emit('update:visible', $event)"
  >
    <div class="dialog-shell">
      <div class="dialog-copy">
        <p>系统管理</p>
        <h2>{{ dialogTitle }}</h2>
        <span>发布或编辑系统公告，支持标题、内容、背景图、标签与显隐状态维护。</span>
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
            <ElInput v-model="form.title" placeholder="请输入公告标题" maxlength="120" show-word-limit />
          </ElFormItem>
        </div>

        <section class="editor-panel">
          <header class="section-header">
            <div>
              <h3>公告内容</h3>
              <span>支持 Markdown 与基础 HTML 换行，优先保证内容表达清晰。</span>
            </div>

            <div class="section-actions">
              <ElButton @click="previewVisible = !previewVisible">
                {{ previewVisible ? '继续编辑' : '预览公告' }}
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

          <ElFormItem prop="content" class="editor-field">
            <div v-if="previewVisible" class="content-preview markdown-body" v-html="renderedContent" />
            <textarea
              v-else
              ref="contentEditorRef"
              v-model="form.content"
              class="content-editor"
              placeholder="请输入公告内容，支持 Markdown 或 <br> 换行"
            />
          </ElFormItem>
        </section>

        <div class="dialog-grid">
          <ElFormItem label="公告背景" prop="imgUrl" class="is-full">
            <ElInput
              v-model="form.imgUrl"
              placeholder="https://example.com/cover.png"
              clearable
            />
          </ElFormItem>

          <ElFormItem label="节点标签" class="is-full">
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
                placeholder="输入后回车添加标签"
                @keyup.enter.prevent="handleTagConfirm"
                @blur="handleTagConfirm"
              />
            </div>
          </ElFormItem>
        </div>

        <section class="switch-panel">
          <article class="switch-card">
            <div>
              <strong>显示</strong>
              <span>关闭后公告仍保留，但不会在前台继续展示。</span>
            </div>
            <ElSwitch v-model="form.show" />
          </article>

          <article class="switch-card">
            <div>
              <strong>弹窗公告</strong>
              <span>开启后公告会以弹窗优先展示，适合重要通知或紧急维护提示。</span>
            </div>
            <ElSwitch v-model="form.popup" />
          </article>
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

<style scoped lang="scss" src="./SystemNoticeEditorDialog.scss"></style>
