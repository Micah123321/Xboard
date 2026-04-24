import { computed, onMounted, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteGiftCardCode,
  deleteGiftCardTemplate,
  exportGiftCardCodes,
  fetchGiftCardCodes,
  fetchGiftCardTemplates,
  fetchGiftCardUsages,
  getGiftCardStatistics,
  getGiftCardTypes,
  getPlans,
  toggleGiftCardCode,
  updateGiftCardTemplate,
} from '@/api/admin'
import type {
  AdminGiftCardCodeItem,
  AdminGiftCardStatistics,
  AdminGiftCardTemplateItem,
  AdminGiftCardTemplateType,
  AdminGiftCardUsageItem,
  AdminPlanOption,
} from '@/types/api'
import {
  buildGiftCardTypeOptions,
  filterGiftCardCodes,
  filterGiftCardTemplates,
  filterGiftCardUsages,
  type GiftCardCodeStatusFilter,
  type GiftCardTemplateStatusFilter,
} from '@/utils/giftCards'

export type GiftCardTabKey = 'templates' | 'codes' | 'usages' | 'statistics'
type TemplateDrawerMode = 'create' | 'edit'

export function useGiftCardsManagement() {
  const activeTab = ref<GiftCardTabKey>('templates')

  const templateLoading = ref(false)
  const codesLoading = ref(false)
  const usagesLoading = ref(false)
  const statsLoading = ref(false)

  const templateError = ref('')
  const codesError = ref('')
  const usagesError = ref('')
  const statsError = ref('')

  const templates = ref<AdminGiftCardTemplateItem[]>([])
  const codes = ref<AdminGiftCardCodeItem[]>([])
  const usages = ref<AdminGiftCardUsageItem[]>([])
  const statistics = ref<AdminGiftCardStatistics | null>(null)
  const plans = ref<AdminPlanOption[]>([])
  const typeMap = ref<Record<string, string>>({})

  const templateKeyword = ref('')
  const templateTypeFilter = ref<AdminGiftCardTemplateType | 'all'>('all')
  const templateStatusFilter = ref<GiftCardTemplateStatusFilter>('all')
  const templateCurrent = ref(1)
  const templatePageSize = ref(20)

  const codeKeyword = ref('')
  const codeTemplateFilter = ref<number | 'all'>('all')
  const codeStatusFilter = ref<GiftCardCodeStatusFilter>('all')
  const codeCurrent = ref(1)
  const codePageSize = ref(20)
  const selectedBatchId = ref('')

  const usageKeyword = ref('')
  const usageCurrent = ref(1)
  const usagePageSize = ref(20)

  const templateDrawerVisible = ref(false)
  const templateDrawerMode = ref<TemplateDrawerMode>('create')
  const activeTemplate = ref<AdminGiftCardTemplateItem | null>(null)

  const batchDialogVisible = ref(false)

  const typeOptions = computed(() => buildGiftCardTypeOptions(typeMap.value))

  const filteredTemplates = computed(() => filterGiftCardTemplates(
    templates.value,
    templateKeyword.value,
    templateTypeFilter.value,
    templateStatusFilter.value,
  ))

  const visibleTemplates = computed(() => {
    const start = (templateCurrent.value - 1) * templatePageSize.value
    return filteredTemplates.value.slice(start, start + templatePageSize.value)
  })

  const filteredCodes = computed(() => filterGiftCardCodes(
    codes.value,
    codeKeyword.value,
    codeTemplateFilter.value,
    codeStatusFilter.value,
  ))

  const visibleCodes = computed(() => {
    const start = (codeCurrent.value - 1) * codePageSize.value
    return filteredCodes.value.slice(start, start + codePageSize.value)
  })

  const filteredUsages = computed(() => filterGiftCardUsages(usages.value, usageKeyword.value))
  const visibleUsages = computed(() => {
    const start = (usageCurrent.value - 1) * usagePageSize.value
    return filteredUsages.value.slice(start, start + usagePageSize.value)
  })

  const resolvedBatchId = computed(() => {
    if (selectedBatchId.value) {
      return selectedBatchId.value
    }
    const batchIds = [...new Set(filteredCodes.value.map((item) => item.batch_id).filter(Boolean))]
    return batchIds.length === 1 ? String(batchIds[0]) : ''
  })

  async function loadMeta() {
    try {
      const [plansResponse, typeResponse] = await Promise.all([
        getPlans(),
        getGiftCardTypes(),
      ])
      plans.value = plansResponse.data ?? []
      typeMap.value = typeResponse.data ?? {}
    } catch (error) {
      ElMessage.warning(error instanceof Error ? error.message : '礼品卡元数据加载失败')
    }
  }

  async function loadTemplates() {
    templateLoading.value = true
    templateError.value = ''
    try {
      const response = await fetchGiftCardTemplates({
        page: 1,
        per_page: 500,
        type: templateTypeFilter.value === 'all' ? undefined : templateTypeFilter.value,
        status: templateStatusFilter.value === 'all'
          ? undefined
          : (templateStatusFilter.value === 'enabled' ? 1 : 0),
      })
      templates.value = response.data ?? []
    } catch (error) {
      templateError.value = error instanceof Error ? error.message : '模板列表加载失败'
    } finally {
      templateLoading.value = false
    }
  }

  async function loadCodes() {
    codesLoading.value = true
    codesError.value = ''
    try {
      const response = await fetchGiftCardCodes({
        page: 1,
        per_page: 500,
        template_id: codeTemplateFilter.value === 'all' ? undefined : codeTemplateFilter.value,
        status: codeStatusFilter.value === 'all' ? undefined : codeStatusFilter.value,
      })
      codes.value = response.data ?? []

      if (selectedBatchId.value && !codes.value.some((item) => item.batch_id === selectedBatchId.value)) {
        selectedBatchId.value = ''
      }
    } catch (error) {
      codesError.value = error instanceof Error ? error.message : '兑换码列表加载失败'
    } finally {
      codesLoading.value = false
    }
  }

  async function loadUsages() {
    usagesLoading.value = true
    usagesError.value = ''
    try {
      const response = await fetchGiftCardUsages({
        page: 1,
        per_page: 500,
      })
      usages.value = response.data ?? []
    } catch (error) {
      usagesError.value = error instanceof Error ? error.message : '使用记录加载失败'
    } finally {
      usagesLoading.value = false
    }
  }

  async function loadStatistics() {
    statsLoading.value = true
    statsError.value = ''
    try {
      const response = await getGiftCardStatistics()
      statistics.value = response.data
    } catch (error) {
      statsError.value = error instanceof Error ? error.message : '统计数据加载失败'
    } finally {
      statsLoading.value = false
    }
  }

  function openCreateTemplate() {
    templateDrawerMode.value = 'create'
    activeTemplate.value = null
    templateDrawerVisible.value = true
  }

  function openEditTemplate(template: AdminGiftCardTemplateItem) {
    templateDrawerMode.value = 'edit'
    activeTemplate.value = template
    templateDrawerVisible.value = true
  }

  async function handleTemplateToggle(template: AdminGiftCardTemplateItem, nextValue: string | number | boolean) {
    const normalized = Boolean(nextValue)
    if (Boolean(template.status) === normalized) {
      return
    }
    try {
      await updateGiftCardTemplate({
        id: template.id,
        name: template.name,
        type: template.type,
        status: normalized,
        rewards: template.rewards ?? {},
      })
      template.status = normalized
      ElMessage.success('模板状态已更新')
      await loadStatistics()
    } catch (error) {
      ElMessage.error(error instanceof Error ? error.message : '模板状态更新失败')
    }
  }

  async function handleTemplateDelete(template: AdminGiftCardTemplateItem) {
    try {
      await ElMessageBox.confirm(`删除模板「${template.name}」后无法恢复，确认继续吗？`, '删除模板', {
        type: 'warning',
      })
      await deleteGiftCardTemplate(template.id)
      ElMessage.success('模板已删除')
      await Promise.all([loadTemplates(), loadCodes(), loadStatistics()])
    } catch (error) {
      if (error === 'cancel' || error === 'close') {
        return
      }
      ElMessage.error(error instanceof Error ? error.message : '模板删除失败')
    }
  }

  async function handleTemplateSuccess() {
    await Promise.all([loadTemplates(), loadCodes(), loadStatistics()])
  }

  async function handleCodeToggle(code: AdminGiftCardCodeItem, nextValue: string | number | boolean) {
    const targetEnabled = Boolean(nextValue)
    if (targetEnabled === (code.status !== 3)) {
      return
    }

    try {
      await toggleGiftCardCode(code.id, targetEnabled ? 'enable' : 'disable')
      code.status = targetEnabled ? 0 : 3
      ElMessage.success(targetEnabled ? '兑换码已启用' : '兑换码已禁用')
      await loadStatistics()
    } catch (error) {
      ElMessage.error(error instanceof Error ? error.message : '兑换码状态更新失败')
    }
  }

  async function copyCode(code: string) {
    try {
      await navigator.clipboard.writeText(code)
      ElMessage.success('兑换码已复制')
    } catch {
      ElMessage.error('复制失败，请手动复制')
    }
  }

  async function handleCodeDelete(code: AdminGiftCardCodeItem) {
    try {
      await ElMessageBox.confirm(`删除兑换码 ${code.code} 后无法恢复，确认继续吗？`, '删除兑换码', {
        type: 'warning',
      })
      await deleteGiftCardCode(code.id)
      ElMessage.success('兑换码已删除')
      await Promise.all([loadCodes(), loadStatistics()])
    } catch (error) {
      if (error === 'cancel' || error === 'close') {
        return
      }
      ElMessage.error(error instanceof Error ? error.message : '兑换码删除失败')
    }
  }

  async function handleExportBatch() {
    if (!resolvedBatchId.value) {
      ElMessage.warning('请先选中一个批次后再导出')
      return
    }

    try {
      const blob = await exportGiftCardCodes(resolvedBatchId.value)
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `gift_cards_${resolvedBatchId.value}.txt`
      link.click()
      URL.revokeObjectURL(url)
      ElMessage.success('兑换码文本已导出')
    } catch (error) {
      ElMessage.error(error instanceof Error ? error.message : '兑换码导出失败')
    }
  }

  function handleBatchGenerated(payload: { batchId: string }) {
    selectedBatchId.value = payload.batchId
    void Promise.all([loadCodes(), loadStatistics()])
  }

  function resetTemplateFilters() {
    templateKeyword.value = ''
    templateTypeFilter.value = 'all'
    templateStatusFilter.value = 'all'
  }

  function resetCodeFilters() {
    codeKeyword.value = ''
    codeTemplateFilter.value = 'all'
    codeStatusFilter.value = 'all'
    selectedBatchId.value = ''
  }

  function resetUsageFilters() {
    usageKeyword.value = ''
  }

  watch([templateKeyword, templateTypeFilter, templateStatusFilter, templatePageSize], () => {
    templateCurrent.value = 1
  })

  watch([codeKeyword, codeTemplateFilter, codeStatusFilter, codePageSize], () => {
    codeCurrent.value = 1
  })

  watch([usageKeyword, usagePageSize], () => {
    usageCurrent.value = 1
  })

  watch([templateTypeFilter, templateStatusFilter], () => {
    void loadTemplates()
  })

  watch([codeTemplateFilter, codeStatusFilter], () => {
    void loadCodes()
  })

  onMounted(() => {
    void Promise.allSettled([
      loadMeta(),
      loadTemplates(),
      loadCodes(),
      loadUsages(),
      loadStatistics(),
    ])
  })

  return {
    activeTab,
    templateLoading,
    codesLoading,
    usagesLoading,
    statsLoading,
    templateError,
    codesError,
    usagesError,
    statsError,
    templates,
    statistics,
    plans,
    typeMap,
    typeOptions,
    templateKeyword,
    templateTypeFilter,
    templateStatusFilter,
    templateCurrent,
    templatePageSize,
    filteredTemplates,
    visibleTemplates,
    codeKeyword,
    codeTemplateFilter,
    codeStatusFilter,
    codeCurrent,
    codePageSize,
    filteredCodes,
    visibleCodes,
    usageKeyword,
    usageCurrent,
    usagePageSize,
    filteredUsages,
    visibleUsages,
    resolvedBatchId,
    templateDrawerVisible,
    templateDrawerMode,
    activeTemplate,
    batchDialogVisible,
    loadUsages,
    loadCodes,
    loadStatistics,
    openCreateTemplate,
    openEditTemplate,
    handleTemplateToggle,
    handleTemplateDelete,
    handleTemplateSuccess,
    handleCodeToggle,
    copyCode,
    handleCodeDelete,
    handleExportBatch,
    handleBatchGenerated,
    resetTemplateFilters,
    resetCodeFilters,
    resetUsageFilters,
    setSelectedBatchId: (batchId: string) => {
      selectedBatchId.value = batchId
    },
  }
}
