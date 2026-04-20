import type { Router } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { hasToken } from '@/utils/token'

export function setupGuards(router: Router) {
  router.beforeEach(async (to) => {
    const auth = useAuthStore()

    if (to.meta.public) {
      if (hasToken() && !auth.validated) {
        const ok = await auth.validateAdmin()
        if (ok) return '/dashboard'
      }
      if (auth.validated) {
        return '/dashboard'
      }
      return true
    }

    if (!hasToken()) {
      return '/login'
    }

    if (!auth.validated) {
      const ok = await auth.validateAdmin()
      if (!ok) {
        return '/login'
      }
    }

    return true
  })
}
