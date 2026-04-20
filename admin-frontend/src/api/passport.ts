import { passportClient } from './client'
import type { ApiResponse, LoginRequest, LoginResponse } from '@/types/api'

export function login(data: LoginRequest): Promise<ApiResponse<LoginResponse>> {
  return passportClient
    .post<ApiResponse<LoginResponse>>('/auth/login', data)
    .then((res) => res.data)
}
