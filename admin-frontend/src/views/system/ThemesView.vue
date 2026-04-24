<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Brush, PictureFilled, RefreshRight, Setting, UploadFilled } from '@element-plus/icons-vue'
import {
  getThemeConfig,
  getThemes,
  saveAdminConfig,
  saveThemeConfig,
  uploadTheme,
} from '@/api/admin'
import type { AdminThemeConfigRecord } from '@/types/api'
import ThemeConfigDrawer from './ThemeConfigDrawer.vue'
import { resolveThemes, type ResolvedThemeSummary } from '@/utils/themes'

const loading = ref(true)
const reloading = ref(false)
const uploading = ref(false)
const drawerVisible = ref(false)
const drawerLoading = ref(false)
const drawerSaving = ref(false)
const drawerErrorMessage = ref('')
const errorMessage = ref('')
const applyingThemeName = ref('')
const fileInput = ref<HTMLInputElement | null>(null)

const activeThemeName = ref('Xboard')
const themes = ref<ResolvedThemeSummary[]>([])
const selectedThemeName = ref<string | null>(null)
const selectedThemeConfig = ref<AdminThemeConfigRecord | null>(null)

const selectedTheme = computed(
  () => themes.value.find((theme) => theme.name === selectedThemeName.value) ?? null,
)

const uploadedThemeCount = computed(() => themes.value.filter((theme) => !theme.is_system).length)
const hasThemes = computed(() => themes.value.length > 0)

async function loadPage(mode: 'initial' | 'reload' = 'initial') {
  if (mode === 'initial') {
    loading.value = true
  } else {
    reloading.value = true
  }
  errorMessage.value = ''

  try {
    const response = await getThemes()
    activeThemeName.value = response.data?.active || 'Xboard'
    themes.value = resolveThemes(response.data)

    if (selectedThemeName.value && !themes.value.some((theme) => theme.name === selectedThemeName.value)) {
      drawerVisible.value = false
      selectedThemeName.value = null
      selectedThemeConfig.value = null
    }
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '主题列表加载失败'
  } finally {
    loading.value = false
    reloading.value = false
  }
}

async function openThemeSettings(theme: ResolvedThemeSummary) {
  selectedThemeName.value = theme.name
  selectedThemeConfig.value = null
  drawerErrorMessage.value = ''
  drawerVisible.value = true
  drawerLoading.value = true

  try {
    const response = await getThemeConfig(theme.name)
    selectedThemeConfig.value = response.data ?? {}
  } catch (error) {
    drawerErrorMessage.value = error instanceof Error ? error.message : '主题配置加载失败'
  } finally {
    drawerLoading.value = false
  }
}

async function handleSaveThemeConfig(payload: {
  name: string
  config: AdminThemeConfigRecord
}) {
  drawerSaving.value = true
  try {
    const response = await saveThemeConfig(payload.name, payload.config)
    selectedThemeConfig.value = response.data ?? payload.config
    ElMessage.success('主题配置已保存')
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '主题配置保存失败')
  } finally {
    drawerSaving.value = false
  }
}

async function handleApplyTheme(name: string) {
  applyingThemeName.value = name
  try {
    await saveAdminConfig({ frontend_theme: name })
    activeThemeName.value = name
    ElMessage.success(`已切换为「${name}」`)
    await loadPage('reload')
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '主题切换失败')
  } finally {
    applyingThemeName.value = ''
  }
}

function triggerUpload() {
  fileInput.value?.click()
}

async function handleFileChange(event: Event) {
  const target = event.target as HTMLInputElement
  const file = target.files?.[0]
  target.value = ''
  if (!file) return

  if (!/\.zip$/i.test(file.name)) {
    ElMessage.error('请选择 zip 格式的主题包')
    return
  }

  uploading.value = true
  try {
    await uploadTheme(file)
    ElMessage.success('主题包上传成功')
    await loadPage('reload')
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '主题上传失败')
  } finally {
    uploading.value = false
  }
}

onMounted(() => {
  void loadPage()
})
</script>

<template>
  <div class="themes-page">
    <section class="themes-header">
      <div class="header-copy">
        <p class="header-kicker">System Management</p>
        <h1>主题配置</h1>
        <p>
          主题配置，包括主题色、背景与自定义页脚等。如果你采用前后分离的方式部署 V2board，
          这些主题配置不会生效。
        </p>
      </div>

      <div class="header-actions">
        <ElButton :loading="reloading" @click="loadPage('reload')">
          <ElIcon><RefreshRight /></ElIcon>
          刷新列表
        </ElButton>
        <ElButton type="primary" :loading="uploading" @click="triggerUpload">
          <ElIcon><UploadFilled /></ElIcon>
          上传主题
        </ElButton>
        <input
          ref="fileInput"
          class="file-input"
          type="file"
          accept=".zip,application/zip"
          @change="handleFileChange"
        >
      </div>
    </section>

    <section class="themes-toolbar">
      <div class="toolbar-pill">
        <span>当前主题</span>
        <strong>{{ activeThemeName }}</strong>
      </div>
      <div class="toolbar-pill">
        <span>可用主题</span>
        <strong>{{ themes.length }}</strong>
      </div>
      <div class="toolbar-pill">
        <span>已上传主题</span>
        <strong>{{ uploadedThemeCount }}</strong>
      </div>
    </section>

    <section v-if="loading" class="loading-shell">
      <ElSkeleton :rows="4" animated />
      <ElSkeleton :rows="4" animated />
    </section>

    <section v-else class="themes-shell">
      <ElAlert
        v-if="errorMessage"
        :title="errorMessage"
        type="error"
        show-icon
        :closable="false"
      />

      <div v-if="hasThemes" class="themes-grid">
        <article
          v-for="theme in themes"
          :key="theme.key"
          class="theme-card"
          :class="{ active: theme.name === activeThemeName }"
        >
          <div class="theme-card__top">
            <div class="theme-card__icon">
              <ElIcon v-if="theme.images"><PictureFilled /></ElIcon>
              <ElIcon v-else><Brush /></ElIcon>
            </div>

            <div class="theme-card__meta">
              <div class="theme-card__title">
                <h2>{{ theme.name }}</h2>
                <ElTag
                  v-if="theme.name === activeThemeName"
                  type="success"
                  effect="light"
                  round
                >
                  当前主题
                </ElTag>
                <ElTag v-else-if="theme.is_system" effect="plain" round>系统主题</ElTag>
                <ElTag v-else type="info" effect="plain" round>上传主题</ElTag>
              </div>

              <p>{{ theme.description || theme.name }}</p>
            </div>
          </div>

          <div class="theme-card__facts">
            <article>
              <span>版本</span>
              <strong>{{ theme.version || '未标注' }}</strong>
            </article>
            <article>
              <span>配置项</span>
              <strong>{{ theme.configs?.length || 0 }}</strong>
            </article>
          </div>

          <div class="theme-card__actions">
            <ElButton @click="openThemeSettings(theme)">
              <ElIcon><Setting /></ElIcon>
              主题设置
            </ElButton>
            <ElButton
              :disabled="theme.name === activeThemeName"
              :loading="applyingThemeName === theme.name"
              :type="theme.name === activeThemeName ? undefined : 'primary'"
              :plain="theme.name !== activeThemeName"
              @click="handleApplyTheme(theme.name)"
            >
              {{ theme.name === activeThemeName ? '当前主题' : '设为当前' }}
            </ElButton>
          </div>
        </article>
      </div>

      <div v-else class="empty-shell">
        <ElIcon><Brush /></ElIcon>
        <div>
          <h2>当前没有可用主题</h2>
          <p>请先上传包含 `config.json` 和 `dashboard.blade.php` 的主题包，再回到这里做配置。</p>
        </div>
      </div>
    </section>

    <ThemeConfigDrawer
      v-model:visible="drawerVisible"
      :theme="selectedTheme"
      :config="selectedThemeConfig"
      :loading="drawerLoading"
      :saving="drawerSaving"
      :applying="applyingThemeName === selectedThemeName"
      :is-active="selectedTheme?.name === activeThemeName"
      :error-message="drawerErrorMessage"
      @save="handleSaveThemeConfig"
      @apply="handleApplyTheme"
    />
  </div>
</template>

<style scoped>
.themes-page {
  display: grid;
  gap: 24px;
}

.themes-header {
  display: flex;
  justify-content: space-between;
  gap: 24px;
  align-items: flex-start;
}

.header-copy {
  display: grid;
  gap: 12px;
  max-width: 760px;
}

.header-kicker {
  margin: 0;
  color: var(--xboard-text-muted);
  font-size: 11px;
  letter-spacing: 0.22em;
  text-transform: uppercase;
}

.header-copy h1 {
  margin: 0;
  color: var(--xboard-text-strong);
  font-size: clamp(34px, 4vw, 46px);
  line-height: 1.06;
  letter-spacing: -0.28px;
}

.header-copy > p:last-child {
  margin: 0;
  color: var(--xboard-text-secondary);
  line-height: 1.7;
}

.header-actions {
  display: flex;
  gap: 12px;
  flex-shrink: 0;
}

.file-input {
  display: none;
}

.themes-toolbar {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 14px;
}

.toolbar-pill {
  display: grid;
  gap: 6px;
  padding: 18px 20px;
  border-radius: 20px;
  background: #ffffff;
  box-shadow: var(--xboard-shadow);
}

.toolbar-pill span {
  color: var(--xboard-text-muted);
  font-size: 12px;
}

.toolbar-pill strong {
  color: var(--xboard-text-strong);
  font-size: 22px;
  line-height: 1.15;
}

.loading-shell,
.themes-shell {
  display: grid;
  gap: 16px;
}

.loading-shell {
  padding: 24px;
  border-radius: 24px;
  background: #ffffff;
  box-shadow: var(--xboard-shadow);
}

.themes-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap: 18px;
}

.theme-card {
  display: grid;
  gap: 18px;
  padding: 22px 22px 20px;
  border-radius: 24px;
  background: #ffffff;
  box-shadow: var(--xboard-shadow);
  border: 1px solid transparent;
  transition: border-color 0.18s ease, transform 0.18s ease;
}

.theme-card:hover {
  transform: translateY(-1px);
  border-color: rgba(0, 113, 227, 0.08);
}

.theme-card.active {
  border-color: rgba(0, 113, 227, 0.16);
}

.theme-card__top {
  display: flex;
  gap: 16px;
  align-items: flex-start;
}

.theme-card__icon {
  width: 52px;
  height: 52px;
  display: grid;
  place-items: center;
  border-radius: 18px;
  background: #f5f5f7;
  color: #1d1d1f;
  font-size: 20px;
  flex-shrink: 0;
}

.theme-card__meta {
  display: grid;
  gap: 8px;
}

.theme-card__title {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  align-items: center;
}

.theme-card__title h2 {
  margin: 0;
  color: var(--xboard-text-strong);
  font-size: 28px;
  line-height: 1.1;
  letter-spacing: -0.24px;
}

.theme-card__meta p {
  margin: 0;
  color: var(--xboard-text-secondary);
  line-height: 1.6;
}

.theme-card__facts {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.theme-card__facts article {
  display: grid;
  gap: 6px;
  padding: 14px 16px;
  border-radius: 18px;
  background: #f5f5f7;
}

.theme-card__facts span {
  color: var(--xboard-text-muted);
  font-size: 12px;
}

.theme-card__facts strong {
  color: var(--xboard-text-strong);
  font-size: 18px;
  line-height: 1.2;
}

.theme-card__actions {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  flex-wrap: wrap;
}

.empty-shell {
  display: flex;
  gap: 16px;
  align-items: flex-start;
  padding: 28px;
  border-radius: 24px;
  background: #ffffff;
  box-shadow: var(--xboard-shadow);
}

.empty-shell :deep(.el-icon) {
  color: #0071e3;
  font-size: 20px;
  margin-top: 4px;
}

.empty-shell h2 {
  margin: 0 0 8px;
  color: var(--xboard-text-strong);
  font-size: 22px;
}

.empty-shell p {
  margin: 0;
  color: var(--xboard-text-secondary);
  line-height: 1.7;
}

@media (max-width: 960px) {
  .themes-header {
    flex-direction: column;
  }

  .header-actions {
    width: 100%;
    flex-wrap: wrap;
  }

  .themes-toolbar {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 767px) {
  .theme-card__facts {
    grid-template-columns: 1fr;
  }

  .theme-card__actions {
    justify-content: stretch;
  }

  .theme-card__actions :deep(.el-button) {
    flex: 1 1 140px;
  }
}
</style>
