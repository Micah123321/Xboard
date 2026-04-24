<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { UploadRequestOptions } from 'element-plus'
import { RefreshRight, Search, UploadFilled } from '@element-plus/icons-vue'
import {
  disablePlugin,
  enablePlugin,
  getPluginConfig,
  getPlugins,
  getPluginTypes,
  installPlugin,
  savePluginConfig,
  uninstallPlugin,
  upgradePlugin,
  uploadPluginPackage,
} from '@/api/admin'
import type {
  AdminPluginConfigField,
  AdminPluginItem,
  AdminPluginTypeItem,
} from '@/types/api'
import PluginCard from './PluginCard.vue'
import PluginDetailDrawer from './PluginDetailDrawer.vue'
import {
  buildPluginTabs,
  countEnabledPlugins,
  countUpgradeablePlugins,
  countUserPlugins,
  filterPlugins,
  hasPluginConfig,
  type PluginStatusFilter,
  type PluginTabValue,
  PLUGIN_STATUS_FILTER_OPTIONS,
} from '@/utils/plugins'

type PluginAction = 'install' | 'enable' | 'disable' | 'upgrade' | 'uninstall'
type UploadError = Parameters<UploadRequestOptions['onError']>[0]

const loading = ref(true)
const reloading = ref(false)
const uploadLoading = ref(false)
const errorMessage = ref('')

const keyword = ref('')
const typeFilter = ref<PluginTabValue>('all')
const statusFilter = ref<PluginStatusFilter>('all')

const pluginTypes = ref<AdminPluginTypeItem[]>([])
const plugins = ref<AdminPluginItem[]>([])
const actionLoadingMap = ref<Record<string, boolean>>({})

const drawerVisible = ref(false)
const drawerLoading = ref(false)
const drawerSaving = ref(false)
const activePlugin = ref<AdminPluginItem | null>(null)

const tabs = computed(() => buildPluginTabs(pluginTypes.value))
const filteredPlugins = computed(() => filterPlugins(plugins.value, keyword.value, statusFilter.value))
const heroStats = computed(() => [
  { label: '插件总数', value: String(plugins.value.length) },
  { label: '已启用', value: String(countEnabledPlugins(plugins.value)) },
  { label: '可升级', value: String(countUpgradeablePlugins(plugins.value)) },
  { label: '用户上传', value: String(countUserPlugins(plugins.value)) },
])

function getActionKey(code: string, action: PluginAction): string {
  return `${code}:${action}`
}

async function loadPluginTypes() {
  const response = await getPluginTypes()
  pluginTypes.value = response.data ?? []
}

async function syncActivePlugin(code?: string, refreshConfig = false) {
  const targetCode = code ?? activePlugin.value?.code
  if (!targetCode) return

  const latest = plugins.value.find((item) => item.code === targetCode)
  if (!latest) {
    activePlugin.value = null
    drawerVisible.value = false
    return
  }

  if (refreshConfig && latest.is_installed && hasPluginConfig(latest)) {
    const configResponse = await getPluginConfig(latest.code)
    activePlugin.value = {
      ...latest,
      config: configResponse.data as Record<string, AdminPluginConfigField>,
    }
    return
  }

  activePlugin.value = latest
}

async function loadPlugins(mode: 'initial' | 'reload' = 'initial') {
  if (mode === 'initial') {
    loading.value = true
  } else {
    reloading.value = true
  }

  errorMessage.value = ''

  try {
    const response = await getPlugins(typeFilter.value === 'all' ? {} : { type: typeFilter.value })
    plugins.value = response.data ?? []
    await syncActivePlugin(undefined, drawerVisible.value && Boolean(activePlugin.value?.is_installed))
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '插件列表加载失败'
  } finally {
    loading.value = false
    reloading.value = false
  }
}

async function bootstrapPage() {
  try {
    await loadPluginTypes()
  } catch (error) {
    ElMessage.warning(error instanceof Error ? error.message : '插件类型加载失败，将回退到默认文案')
  }

  await loadPlugins()
}

async function openDetail(plugin: AdminPluginItem) {
  drawerVisible.value = true
  drawerLoading.value = true
  activePlugin.value = plugin

  try {
    if (plugin.is_installed && hasPluginConfig(plugin)) {
      const response = await getPluginConfig(plugin.code)
      activePlugin.value = {
        ...plugin,
        config: response.data as Record<string, AdminPluginConfigField>,
      }
      return
    }

    activePlugin.value = plugin
  } catch (error) {
    activePlugin.value = plugin
    ElMessage.warning(error instanceof Error ? error.message : '插件配置读取失败，已展示列表快照')
  } finally {
    drawerLoading.value = false
  }
}

async function runPluginAction(plugin: AdminPluginItem, action: PluginAction) {
  const key = getActionKey(plugin.code, action)
  actionLoadingMap.value[key] = true

  try {
    if (action === 'install') {
      await installPlugin(plugin.code)
      ElMessage.success(`已安装 ${plugin.name}`)
    }

    if (action === 'enable') {
      await enablePlugin(plugin.code)
      ElMessage.success(`已启用 ${plugin.name}`)
    }

    if (action === 'disable') {
      await disablePlugin(plugin.code)
      ElMessage.success(`已禁用 ${plugin.name}`)
    }

    if (action === 'upgrade') {
      await upgradePlugin(plugin.code)
      ElMessage.success(`已升级 ${plugin.name}`)
    }

    if (action === 'uninstall') {
      await ElMessageBox.confirm(`卸载插件「${plugin.name}」后，将移除其当前安装状态。确认继续吗？`, '卸载插件', {
        type: 'warning',
      })
      await uninstallPlugin(plugin.code)
      ElMessage.success(`已卸载 ${plugin.name}`)
    }

    await loadPlugins('reload')
    await syncActivePlugin(plugin.code, drawerVisible.value)
  } catch (error) {
    if (error === 'cancel' || error === 'close') {
      return
    }

    ElMessage.error(error instanceof Error ? error.message : '插件操作失败')
  } finally {
    actionLoadingMap.value[key] = false
  }
}

async function handleSaveConfig(payload: Record<string, unknown>) {
  if (!activePlugin.value) return

  drawerSaving.value = true
  try {
    await savePluginConfig(activePlugin.value.code, payload)
    ElMessage.success('插件配置已保存')
    await loadPlugins('reload')
    await syncActivePlugin(activePlugin.value.code, true)
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '插件配置保存失败')
  } finally {
    drawerSaving.value = false
  }
}

async function handleUploadRequest(options: UploadRequestOptions) {
  uploadLoading.value = true
  try {
    await uploadPluginPackage(options.file as File)
    options.onSuccess?.({ success: true })
    ElMessage.success('插件上传成功')
    typeFilter.value = 'all'
    await loadPlugins('reload')
  } catch (error) {
    const message = error instanceof Error ? error.message : '插件上传失败'
    options.onError?.(Object.assign(new Error(message), {
      status: 500,
      method: 'POST',
      url: '/plugin/upload',
    }) as UploadError)
    ElMessage.error(message)
  } finally {
    uploadLoading.value = false
  }
}

watch(typeFilter, () => {
  void loadPlugins('reload')
})

onMounted(() => {
  void bootstrapPage()
})
</script>

<template>
  <div class="plugins-page">
    <section class="plugins-hero">
      <div class="hero-copy">
        <p class="hero-kicker">System Management</p>
        <h1>插件管理。</h1>
        <span>
          在同一个工作台里查看插件状态、执行安装 / 启停 / 升级动作，并补齐 README 与动态配置编辑。
        </span>
      </div>

      <div class="hero-stats">
        <article v-for="item in heroStats" :key="item.label">
          <span>{{ item.label }}</span>
          <strong>{{ item.value }}</strong>
        </article>
      </div>
    </section>

    <section class="toolbar-shell">
      <div class="toolbar-main">
        <ElInput
          v-model="keyword"
          class="toolbar-search"
          clearable
          placeholder="搜索插件名称、代号或描述..."
        >
          <template #prefix>
            <ElIcon><Search /></ElIcon>
          </template>
        </ElInput>

        <div class="plugin-tabs">
          <button
            v-for="item in tabs"
            :key="item.value"
            type="button"
            class="tab-button"
            :class="{ active: item.value === typeFilter }"
            @click="typeFilter = item.value"
          >
            {{ item.label }}
          </button>
        </div>
      </div>

      <div class="toolbar-actions">
        <ElButton :loading="reloading" @click="loadPlugins('reload')">
          <ElIcon><RefreshRight /></ElIcon>
          刷新列表
        </ElButton>

        <ElSelect v-model="statusFilter" class="status-select">
          <ElOption
            v-for="item in PLUGIN_STATUS_FILTER_OPTIONS"
            :key="item.value"
            :label="item.label"
            :value="item.value"
          />
        </ElSelect>

        <ElUpload
          :show-file-list="false"
          accept=".zip,application/zip"
          :http-request="handleUploadRequest"
        >
          <ElButton type="primary" :loading="uploadLoading">
            <ElIcon><UploadFilled /></ElIcon>
            上传插件
          </ElButton>
        </ElUpload>
      </div>
    </section>

    <ElAlert
      v-if="errorMessage"
      type="error"
      show-icon
      :closable="false"
      :title="errorMessage"
      class="page-alert"
    >
      <template #default>
        <ElButton size="small" @click="loadPlugins('reload')">重新加载</ElButton>
      </template>
    </ElAlert>

    <section v-if="loading" class="plugin-grid plugin-grid--loading">
      <article v-for="index in 3" :key="index" class="plugin-card plugin-card--skeleton">
        <ElSkeleton animated :rows="5" />
      </article>
    </section>

    <section v-else-if="filteredPlugins.length" class="plugin-grid">
      <PluginCard
        v-for="plugin in filteredPlugins"
        :key="plugin.code"
        :plugin="plugin"
        :type-labels="pluginTypes"
        :action-loading-map="actionLoadingMap"
        @detail="openDetail"
        @action="runPluginAction($event.plugin, $event.action)"
      />
    </section>

    <section v-else class="empty-shell">
      <ElEmpty description="当前筛选条件下暂无插件" />
      <ElButton @click="statusFilter = 'all'">重置状态筛选</ElButton>
    </section>

    <PluginDetailDrawer
      v-model:visible="drawerVisible"
      :plugin="activePlugin"
      :loading="drawerLoading"
      :saving="drawerSaving"
      :type-labels="pluginTypes"
      @save-config="handleSaveConfig"
    />
  </div>
</template>

<style scoped lang="scss" src="./PluginManagementView.scss"></style>
