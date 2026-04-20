const TOKEN_KEY = 'xboard_admin_auth'
const REMEMBER_KEY = 'xboard_admin_remember'

export function getToken(): string | null {
  const remember = localStorage.getItem(REMEMBER_KEY)
  const storage = remember === 'true' ? localStorage : sessionStorage
  return storage.getItem(TOKEN_KEY)
}

export function setToken(token: string, remember: boolean = false): void {
  removeToken()
  if (remember) {
    localStorage.setItem(REMEMBER_KEY, 'true')
    localStorage.setItem(TOKEN_KEY, token)
  } else {
    sessionStorage.setItem(TOKEN_KEY, token)
  }
}

export function removeToken(): void {
  sessionStorage.removeItem(TOKEN_KEY)
  localStorage.removeItem(TOKEN_KEY)
  localStorage.removeItem(REMEMBER_KEY)
}

export function hasToken(): boolean {
  return getToken() !== null
}
