import { computed, ref, type Ref } from 'vue'
import { ElMessage } from 'element-plus'
import type { UploadProps, UploadRequestOptions } from 'element-plus'
import { uploadImage } from '@/utils/upload'

type ReplyImageUploadSource = 'button' | 'drop' | 'paste'
type UploadError = Parameters<UploadRequestOptions['onError']>[0]

interface ReplyImageUploadResult {
  urls: string[]
  failed: number
  lastError?: string
}

export function useTicketReplyImages(replyMessage: Ref<string>) {
  const uploadingImage = ref(false)
  const isReplyDragActive = ref(false)
  const replyDragDepth = ref(0)

  const replyImageUploadLabel = computed(() => {
    if (uploadingImage.value) {
      return '图片上传中，请稍候...'
    }

    if (isReplyDragActive.value) {
      return '松开后上传图片到当前回复'
    }

    return '支持拖拽图片到这里，或在输入框粘贴截图。'
  })

  function validateReplyImage(file: File): boolean {
    if (!file.type.startsWith('image/')) {
      ElMessage.error('仅支持上传图片文件')
      return false
    }

    if (file.size / 1024 / 1024 > 10) {
      ElMessage.error('图片大小不能超过 10MB')
      return false
    }

    return true
  }

  const beforeImageUpload: UploadProps['beforeUpload'] = (rawFile) => validateReplyImage(rawFile)

  function appendReplyImageMarkdown(url: string) {
    const leadingBreak = replyMessage.value && !replyMessage.value.endsWith('\n') ? '\n' : ''
    replyMessage.value = `${replyMessage.value}${leadingBreak}![image](${url})\n`
  }

  function getUploadFileLabel(file: File, index: number): string {
    return file.name || `图片 ${index + 1}`
  }

  async function uploadReplyImages(
    files: File[],
    source: ReplyImageUploadSource,
  ): Promise<ReplyImageUploadResult> {
    if (!files.length) {
      if (source === 'drop') {
        ElMessage.warning('请拖拽图片文件到回复区')
      }
      return { urls: [], failed: 0, lastError: '没有可上传的图片' }
    }

    if (uploadingImage.value) {
      const message = '图片正在上传，请稍候'
      ElMessage.warning(message)
      return { urls: [], failed: files.length, lastError: message }
    }

    const validFiles = files.filter((file) => validateReplyImage(file))
    if (!validFiles.length) {
      return { urls: [], failed: files.length, lastError: '没有可上传的图片' }
    }

    uploadingImage.value = true
    const urls: string[] = []
    let failed = 0
    let lastError: string | undefined

    try {
      for (const [index, file] of validFiles.entries()) {
        try {
          const url = await uploadImage(file)
          appendReplyImageMarkdown(url)
          urls.push(url)
        } catch (error) {
          failed += 1
          lastError = error instanceof Error ? error.message : '图片上传失败'
          ElMessage.error(`${getUploadFileLabel(file, index)} 上传失败: ${lastError}`)
        }
      }
    } finally {
      uploadingImage.value = false
    }

    if (urls.length) {
      const successMessage = urls.length === 1 ? '图片上传成功' : `${urls.length} 张图片上传成功`
      if (failed) {
        ElMessage.warning(`${successMessage}，${failed} 张失败`)
      } else {
        ElMessage.success(successMessage)
      }
    }

    return { urls, failed, lastError }
  }

  async function handleImageUploadRequest(options: UploadRequestOptions) {
    const result = await uploadReplyImages([options.file], 'button')
    const [url] = result.urls

    if (url) {
      options.onSuccess({ url })
      return
    }

    const message = result.lastError || '图片上传失败'
    options.onError(Object.assign(new Error(message), {
      status: 500,
      method: 'POST',
      url: '/upload/rest/upload',
    }) as UploadError)
  }

  function isDraggingFiles(event: DragEvent): boolean {
    return Array.from(event.dataTransfer?.types ?? []).includes('Files')
  }

  function resetReplyDragState() {
    replyDragDepth.value = 0
    isReplyDragActive.value = false
  }

  function handleReplyDragEnter(event: DragEvent) {
    if (!isDraggingFiles(event)) {
      return
    }

    event.preventDefault()
    replyDragDepth.value += 1
    isReplyDragActive.value = true
  }

  function handleReplyDragOver(event: DragEvent) {
    if (!isDraggingFiles(event)) {
      return
    }

    event.preventDefault()
    if (event.dataTransfer) {
      event.dataTransfer.dropEffect = 'copy'
    }
  }

  function handleReplyDragLeave(event: DragEvent) {
    if (!isDraggingFiles(event)) {
      return
    }

    event.preventDefault()
    replyDragDepth.value = Math.max(0, replyDragDepth.value - 1)
    if (replyDragDepth.value === 0) {
      isReplyDragActive.value = false
    }
  }

  function handleReplyDrop(event: DragEvent) {
    if (!isDraggingFiles(event)) {
      return
    }

    event.preventDefault()
    const files = Array.from(event.dataTransfer?.files ?? [])
    resetReplyDragState()
    void uploadReplyImages(files, 'drop')
  }

  function handleReplyPaste(event: ClipboardEvent) {
    const files = Array.from(event.clipboardData?.items ?? [])
      .filter((item) => item.kind === 'file')
      .map((item) => item.getAsFile())
      .filter((file): file is File => Boolean(file))
      .filter((file) => file.type.startsWith('image/'))

    if (!files.length) {
      return
    }

    event.preventDefault()
    void uploadReplyImages(files, 'paste')
  }

  return {
    uploadingImage,
    isReplyDragActive,
    replyImageUploadLabel,
    beforeImageUpload,
    handleImageUploadRequest,
    handleReplyDragEnter,
    handleReplyDragOver,
    handleReplyDragLeave,
    handleReplyDrop,
    handleReplyPaste,
    resetReplyDragState,
  }
}
