interface UploadResponse {
  code: number
  msg: string
  data?: Array<{
    copyUrl: string
  }>
}

export async function uploadImage(file: File): Promise<string> {
  const formData = new FormData()
  formData.append('files', file)

  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest()
    xhr.open('POST', '/upload/rest/upload', true)
    xhr.setRequestHeader('Accept', 'application/json')

    xhr.onload = () => {
      if (xhr.status !== 200) {
        reject(new Error(`上传失败: ${xhr.status}`))
        return
      }

      try {
        const result = JSON.parse(xhr.responseText) as UploadResponse
        if (result.code === 200 && result.data?.[0]?.copyUrl) {
          resolve(result.data[0].copyUrl)
          return
        }

        reject(new Error(result.msg || `图片上传失败: ${result.code}`))
      } catch {
        reject(new Error('解析上传响应失败'))
      }
    }

    xhr.onerror = () => {
      reject(new Error('网络错误，请检查网络连接'))
    }

    xhr.send(formData)
  })
}
