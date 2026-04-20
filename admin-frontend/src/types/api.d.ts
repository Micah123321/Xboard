export interface ApiResponse<T = unknown> {
  status: 'success' | 'fail'
  message: string
  data: T
  error?: string | null
}

export interface LoginRequest {
  email: string
  password: string
}

export interface LoginResponse {
  token: string
  auth_data: string
  is_admin: boolean
}

export interface SystemStatus {
  [key: string]: unknown
}

declare global {
  interface Window {
    settings?: {
      secure_path?: string
      title?: string
      logo?: string
      version?: string
      theme_sidebar?: string
      theme_header?: string
      theme_color?: string
      background_url?: string
    }
  }
}
