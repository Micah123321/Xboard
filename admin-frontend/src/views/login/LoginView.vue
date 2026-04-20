<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { ElMessage } from 'element-plus'
import { Lock, Message, Right } from '@element-plus/icons-vue'
import type { FormInstance, FormRules } from 'element-plus'
import { useAppStore } from '@/stores/app'
import { DEFAULT_AFTER_LOGIN, normalizeRedirectTarget } from '@/utils/navigation'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const app = useAppStore()

const formRef = ref<FormInstance>()
const loading = ref(false)
const errorMessage = ref('')

const form = reactive({
  email: '',
  password: '',
  remember: false,
})

const redirectTarget = computed(() => normalizeRedirectTarget(route.query.redirect))
const redirectHint = computed(() => (
  redirectTarget.value === DEFAULT_AFTER_LOGIN
    ? '登录后将进入仪表盘总览'
    : `登录后将返回 ${redirectTarget.value}`
))

const rules: FormRules = {
  email: [
    { required: true, message: '请输入邮箱', trigger: 'blur' },
    { type: 'email', message: '邮箱格式不正确', trigger: 'blur' },
  ],
  password: [
    { required: true, message: '请输入密码', trigger: 'blur' },
    { min: 8, message: '密码至少 8 位', trigger: 'blur' },
  ],
}

async function onSubmit() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) return

  loading.value = true
  errorMessage.value = ''
  try {
    await auth.login(form.email, form.password, form.remember)
    ElMessage.success('登录成功')
    await router.replace(redirectTarget.value)
  } catch (err: unknown) {
    const msg = err instanceof Error ? err.message : '登录失败'
    errorMessage.value = msg
    ElMessage.error(msg)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-page">
    <section class="login-hero">
      <p class="login-eyebrow">XBOARD ADMIN</p>
      <h1 class="login-headline">管理后台，像产品页一样安静。</h1>
      <p class="login-description">
        页面重新回到 Apple 式的信息编排方式。减少装饰层和不必要的视觉负担，让登录入口更直接。
      </p>
      <div class="login-meta">
        <span>secure_path /{{ app.securePath || 'admin' }}</span>
        <span>{{ redirectHint }}</span>
      </div>
    </section>

    <section class="login-panel">
      <div class="login-header">
        <p class="login-panel-kicker">Sign in</p>
        <h2 class="login-title">管理员登录</h2>
        <p class="login-subtitle">使用具备后台权限的账号进入 {{ app.title }}。</p>
      </div>

      <ElAlert
        v-if="errorMessage"
        :title="errorMessage"
        type="error"
        show-icon
        :closable="false"
        class="login-alert"
      />

      <ElForm
        ref="formRef"
        :model="form"
        :rules="rules"
        label-position="top"
        size="large"
        @keyup.enter="onSubmit"
      >
        <ElFormItem label="邮箱" prop="email">
          <ElInput
            v-model="form.email"
            placeholder="admin@example.com"
            :prefix-icon="Message"
          />
        </ElFormItem>

        <ElFormItem label="密码" prop="password">
          <ElInput
            v-model="form.password"
            type="password"
            placeholder="请输入密码"
            :prefix-icon="Lock"
            show-password
          />
        </ElFormItem>

        <div class="login-form-meta">
          <ElCheckbox v-model="form.remember">记住登录</ElCheckbox>
          <span class="login-meta-text">{{ redirectHint }}</span>
        </div>

        <ElButton
          type="primary"
          :loading="loading"
          class="login-btn"
          @click="onSubmit"
        >
          <span>{{ loading ? '登录中...' : '继续' }}</span>
          <ElIcon><Right /></ElIcon>
        </ElButton>
      </ElForm>
    </section>
  </div>
</template>

<style scoped>
.login-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 28px;
  padding: 40px 32px;
}

.login-hero,
.login-panel {
  width: min(100%, 560px);
}

.login-hero {
  padding: 48px;
  border-radius: 28px;
  background: #000000;
  color: var(--xboard-text-on-dark);
  display: grid;
  gap: 18px;
}

.login-eyebrow,
.login-panel-kicker {
  margin: 0;
  font-size: 12px;
  letter-spacing: 0.28em;
  text-transform: uppercase;
}

.login-headline {
  margin: 0;
  font-size: clamp(40px, 5vw, 56px);
  line-height: 1.07;
  letter-spacing: -0.28px;
  color: #ffffff;
}

.login-description {
  margin: 0;
  max-width: 420px;
  line-height: 1.47;
  color: var(--xboard-text-on-dark-muted);
}

.login-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
}

.login-meta span {
  border: 1px solid rgba(255, 255, 255, 0.12);
  color: rgba(255, 255, 255, 0.72);
  border-radius: 999px;
  padding: 10px 16px;
  font-size: 12px;
}

.login-panel {
  border-radius: 28px;
  background: #ffffff;
  box-shadow: var(--xboard-shadow);
  padding: 40px 36px;
}

.login-header {
  display: grid;
  gap: 8px;
  margin-bottom: 24px;
}

.login-title {
  margin: 0;
  font-size: 34px;
  line-height: 1.12;
  letter-spacing: -0.32px;
  color: var(--xboard-text-strong);
}

.login-subtitle {
  margin: 0;
  color: var(--xboard-text-secondary);
}

.login-alert {
  margin-bottom: 16px;
}

.login-form-meta {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  margin-bottom: 24px;
}

.login-meta-text {
  color: var(--xboard-text-muted);
  font-size: 12px;
}

.login-btn {
  width: 100%;
  height: 44px;
  border-radius: 999px;
  justify-content: center;
  gap: 8px;
}

@media (max-width: 980px) {
  .login-page {
    flex-direction: column;
    align-items: stretch;
    gap: 20px;
  }

  .login-hero,
  .login-panel {
    width: 100%;
  }
}

@media (max-width: 720px) {
  .login-page {
    padding: 20px;
  }

  .login-hero,
  .login-panel {
    padding: 24px;
  }

  .login-form-meta {
    flex-direction: column;
    align-items: flex-start;
  }
}
</style>
