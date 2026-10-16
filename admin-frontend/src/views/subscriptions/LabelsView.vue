<script setup lang="ts">
import PageHeader from '@/components/PageHeader.vue'
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, RefreshRight } from '@element-plus/icons-vue'
import { deleteSubscriptionLabel, fetchSubscriptionLabels, getPlans, saveSubscriptionLabel } from '@/api/admin'
import type { AdminPlanListItem, AdminSubscriptionLabel, AdminSubscriptionLabelSavePayload, ApiResponse } from '@/types/api'
import { formatSubscriptionPlan, validateSubscriptionLabel } from '@/utils/subscriptionLabels'

const labels = ref<AdminSubscriptionLabel[]>([])
const plans = ref<AdminPlanListItem[]>([])
const loading = ref(false)
const saving = ref(false)
const deletingId = ref<number | null>(null)
const labelsLoaded = ref(false)
const plansLoaded = ref(false)
const labelLoadError = ref('')
const planLoadError = ref('')
const saveError = ref('')
const editorVisible = ref(false)
const keyword = ref('')
const form = reactive<AdminSubscriptionLabelSavePayload>({ name: '', plan_ids: [] })
const busy = computed(() => loading.value || saving.value || deletingId.value !== null)
const canEdit = computed(() => labelsLoaded.value && plansLoaded.value && !labelLoadError.value && !planLoadError.value && !busy.value)
const visibleLabels = computed(() => labels.value.filter((label) => label.name.toLowerCase().includes(keyword.value.trim().toLowerCase())))
const missingPlanIds = computed(() => form.plan_ids.filter((id) => !plans.value.some((plan) => plan.id === id)))

function checkedData<T>(response: ApiResponse<T>): T {
  if (response.status === 'fail' || response.error) throw new Error(response.message || response.error || '请求失败')
  return response.data
}

async function loadOptions() {
  if (loading.value) return
  loading.value = true
  const results = await Promise.allSettled([
    fetchSubscriptionLabels().then((response) => {
      const data = checkedData(response)
      if (!Array.isArray(data)) throw new Error('订阅标签响应格式异常')
      labels.value = data
      labelsLoaded.value = true
      labelLoadError.value = ''
    }),
    getPlans().then((response) => {
      const data = checkedData(response)
      if (!Array.isArray(data)) throw new Error('套餐响应格式异常')
      plans.value = data
      plansLoaded.value = true
      planLoadError.value = ''
    }),
  ])
  if (results[0].status === 'rejected') labelLoadError.value = '订阅标签加载失败：' + (results[0].reason instanceof Error ? results[0].reason.message : '请重试')
  if (results[1].status === 'rejected') planLoadError.value = '套餐加载失败：' + (results[1].reason instanceof Error ? results[1].reason.message : '请重试')
  loading.value = false
}

function openEditor(label?: AdminSubscriptionLabel) {
  if (!canEdit.value) return
  form.id = label?.id
  form.name = label?.name ?? ''
  form.plan_ids = [...(label?.plan_ids ?? [])]
  saveError.value = ''
  editorVisible.value = true
}

async function handleSave() {
  if (!canEdit.value) return
  const message = validateSubscriptionLabel(form, plans.value)
  if (message) {
    saveError.value = message
    return
  }
  saving.value = true
  saveError.value = ''
  try {
    const label = checkedData(await saveSubscriptionLabel({
      ...(form.id !== undefined ? { id: form.id } : {}), name: form.name.trim(), plan_ids: [...new Set(form.plan_ids)],
    }))
    labels.value = [...labels.value.filter((item) => item.id !== label.id), label].sort((a, b) => a.id - b.id)
    editorVisible.value = false
    ElMessage.success('订阅标签已保存；绑定该标签的节点将使用更新后的套餐')
  } catch (error) {
    saveError.value = error instanceof Error ? error.message : '订阅标签保存失败，请重试'
  } finally {
    saving.value = false
  }
}

async function handleDelete(label: AdminSubscriptionLabel) {
  if (busy.value) return
  if (label.servers_count > 0) {
    ElMessage.warning('该标签仍有节点使用，请先在节点管理中解绑或更换标签')
    return
  }
  deletingId.value = label.id
  try {
    await ElMessageBox.confirm('删除订阅标签“' + label.name + '”？', '删除订阅标签', { type: 'warning', confirmButtonText: '删除标签', cancelButtonText: '取消' })
    if (!checkedData(await deleteSubscriptionLabel(label.id))) throw new Error('删除未成功，请刷新后重试')
    labels.value = labels.value.filter((item) => item.id !== label.id)
    ElMessage.success('订阅标签已删除')
  } catch (error) {
    if (error === 'cancel' || error === 'close') return
    ElMessage.error((error instanceof Error ? error.message : '删除失败') + '；使用中的标签请先在节点管理中解绑或更换。')
    await loadOptions()
  } finally {
    deletingId.value = null
  }
}

onMounted(() => { void loadOptions() })
</script>

<template>
  <div class="labels-page" data-testid="subscription-labels-page">
    <PageHeader title="订阅标签">
      <template #actions>
        <ElButton type="primary" :icon="Plus" :disabled="!canEdit" data-testid="create-subscription-label" @click="openEditor()">新建标签</ElButton>
      </template>
    </PageHeader>
    <section class="labels-shell" :aria-busy="loading">
      <div class="labels-toolbar">
        <ElInput v-model="keyword" clearable placeholder="搜索标签名称" aria-label="搜索订阅标签" />
        <ElButton :icon="RefreshRight" :loading="loading" :disabled="busy" data-testid="refresh-subscription-labels" @click="loadOptions">刷新标签与套餐</ElButton>
      </div>
      <ElAlert v-if="labelLoadError || planLoadError" type="error" :closable="false" show-icon>
        <template #title>{{ [labelLoadError, planLoadError].filter(Boolean).join('；') }}。已有数据与编辑内容保留，请重试。</template>
        <ElButton size="small" :loading="loading" :disabled="busy" @click="loadOptions">重试</ElButton>
      </ElAlert>
      <ElSkeleton v-if="loading && !labelsLoaded" :rows="6" animated />
      <ElEmpty v-else-if="!visibleLabels.length" :description="labelLoadError ? '标签加载失败，请重试' : (keyword ? '没有匹配的标签' : '暂无订阅标签')" />
      <div v-else class="labels-grid">
        <article v-for="label in visibleLabels" :key="label.id" class="label-card" :data-label-id="label.id">
          <div class="label-card__heading"><h2>{{ label.name }}</h2><ElTag effect="plain">{{ label.servers_count }} 个节点使用</ElTag></div>
          <div class="label-plans" data-testid="label-plan-details">
            <ElTag v-for="id in label.plan_ids" :key="id" :type="plans.some((plan) => plan.id === id) ? 'info' : 'warning'" effect="plain">{{ formatSubscriptionPlan(id, plans, planLoadError || (loading && !plansLoaded ? '加载中' : '')) }}</ElTag>
            <span v-if="!label.plan_ids.length" class="warning-copy">未绑定套餐，无人可用</span>
          </div>
          <p v-if="label.servers_count > 0" class="muted-copy">使用中，请先在节点管理中解绑或更换标签，再删除。</p>
          <footer class="label-card__actions">
            <ElButton :disabled="!canEdit" data-testid="edit-subscription-label" @click="openEditor(label)">编辑</ElButton>
            <ElButton type="danger" plain :disabled="busy || Boolean(labelLoadError) || label.servers_count > 0" :loading="deletingId === label.id" data-testid="delete-subscription-label" @click="handleDelete(label)">删除</ElButton>
          </footer>
        </article>
      </div>
    </section>

    <ElDialog v-model="editorVisible" :title="form.id === undefined ? '新建订阅标签' : '编辑订阅标签'" width="min(600px, calc(100vw - 24px))" :show-close="!saving" :close-on-click-modal="!saving" :close-on-press-escape="!saving" destroy-on-close class="subscription-label-editor">
      <ElAlert v-if="saveError" :title="saveError" type="error" :closable="false" show-icon />
      <ElAlert v-if="planLoadError || labelLoadError" type="warning" title="标签或套餐加载失败，当前编辑内容已保留" :closable="false">
        <ElButton :loading="loading" @click="loadOptions">重试加载</ElButton>
      </ElAlert>
      <ElForm label-position="top" :disabled="saving" class="label-editor-form" @submit.prevent="handleSave">
        <ElFormItem label="标签名称" required><ElInput v-model="form.name" placeholder="例如：海外优选" data-testid="subscription-label-name" /></ElFormItem>
        <ElFormItem label="绑定套餐（至少一个有效套餐）" required>
          <ElSelect v-model="form.plan_ids" multiple filterable collapse-tags collapse-tags-tooltip :disabled="loading || Boolean(planLoadError)" placeholder="搜索套餐名称或 ID" data-testid="subscription-label-plan-select">
            <ElOption v-for="plan in plans" :key="plan.id" :value="plan.id" :label="formatSubscriptionPlan(plan.id, plans)" />
            <ElOption v-for="id in missingPlanIds" :key="id" :value="id" :label="formatSubscriptionPlan(id, plans, planLoadError || (loading && !plansLoaded ? '加载中' : ''))" disabled />
          </ElSelect>
        </ElFormItem>
        <div class="label-plans"><ElTag v-for="id in form.plan_ids" :key="id" effect="plain" :closable="!saving" @close="form.plan_ids = form.plan_ids.filter((value) => value !== id)">{{ formatSubscriptionPlan(id, plans, planLoadError || (loading && !plansLoaded ? '加载中' : '')) }}</ElTag></div>
        <p class="muted-copy">隐藏套餐仍可绑定；已删除套餐须移除。修改标签会同步影响所有绑定节点。</p>
      </ElForm>
      <template #footer><div class="label-editor-actions"><ElButton :disabled="saving" @click="editorVisible = false">取消</ElButton><ElButton type="primary" :loading="saving" :disabled="!canEdit" data-testid="save-subscription-label" @click="handleSave">保存标签</ElButton></div></template>
    </ElDialog>
  </div>
</template>

<style scoped>
.labels-page, .labels-shell { display: grid; gap: 16px; }
.labels-toolbar,
.label-card__heading,
.label-card__actions,
.label-editor-actions { display: flex; align-items: center; justify-content: space-between; gap: 12px; }

h2 { margin: 0; color: var(--xboard-text-strong); overflow-wrap: anywhere; }
h2 { font-size: 18px; }
.muted-copy { color: var(--xboard-text-muted); line-height: 1.6; font-size: 13px; }
.labels-shell { padding: 24px; background: #fff; border-radius: 24px; }
.labels-toolbar :deep(.el-input) { max-width: 360px; }
.labels-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 340px), 1fr)); gap: 16px; }
.label-card { display: flex; flex-direction: column; gap: 14px; padding: 20px; border: 1px solid var(--el-border-color-light); border-radius: 18px; min-width: 0; }
.label-card__heading { flex-wrap: wrap; align-items: flex-start; }
.label-card__actions { justify-content: flex-end; margin-top: auto; }
.label-plans { display: flex; gap: 6px; flex-wrap: wrap; }
.label-plans :deep(.el-tag) { height: auto; padding: 4px 8px; line-height: 1.5; white-space: normal; overflow-wrap: anywhere; }
.warning-copy { color: var(--el-color-danger); }
.label-editor-form { margin-top: 18px; }
.label-editor-form :deep(.el-select) { width: 100%; }
.label-editor-actions { justify-content: flex-end; }
@media (max-width: 640px) {
  .labels-toolbar { align-items: stretch; flex-direction: column; }
  .labels-shell { padding: 18px; }
  .labels-toolbar :deep(.el-input) { max-width: none; }
  .label-card { padding: 16px; }
  .label-card__actions > *, .label-editor-actions > * { flex: 1; }
}
</style>
