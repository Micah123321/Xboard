import type { Router } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { hasToken } from '@/utils/token'
import { normalizeRedirectTarget } from '@/utils/navigation'

export function setupGuards(router: Router) {
  router.beforeEach(async (to) => {
    const auth = useAuthStore()
    const redirectTarget = normalizeRedirectTarget(to.query.redirect)

    if (to.meta.public) {
      if (hasToken() && !auth.validated) {
        const ok = await auth.validateAdmin()
        if (ok) return redirectTarget
      }
      if (auth.validated) {
        return redirectTarget
      }
      return true
    }

    if (!hasToken()) {
      return {
        path: '/login',
        query: { redirect: to.fullPath },
      }
    }

    if (!auth.validated) {
      const ok = await auth.validateAdmin()
      if (!ok) {
        return {
          path: '/login',
          query: { redirect: to.fullPath },
        }
      }
    }

    return true
  })
}
