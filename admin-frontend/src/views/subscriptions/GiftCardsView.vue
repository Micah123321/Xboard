<script setup lang="ts">
import { reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { updateGiftCardCode } from '@/api/admin'
import type { AdminGiftCardCodeItem, AdminGiftCardCodeStatus } from '@/types/api'
import GiftCardCodeBatchDialog from './GiftCardCodeBatchDialog.vue'
import GiftCardCodesTab from './GiftCardCodesTab.vue'
import GiftCardStatsTab from './GiftCardStatsTab.vue'
import GiftCardTemplateDrawer from './GiftCardTemplateDrawer.vue'
import GiftCardTemplatesTab from './GiftCardTemplatesTab.vue'
import GiftCardUsagesTab from './GiftCardUsagesTab.vue'
import { useGiftCardsManagement } from './useGiftCardsManagement'

const vm = reactive(useGiftCardsManagement())
const codeEditorVisible = ref(false)
const codeSaving = ref(false)
const codeForm = reactive({
  id: 0,
  expires_at: '' as string | number,
  max_usage: 1,
  status: 0 as AdminGiftCardCodeStatus,
})

function openCodeEditor(code: AdminGiftCardCodeItem) {
  codeForm.id = code.id
  codeForm.max_usage = code.max_usage
  codeForm.status = code.status
  codeForm.expires_at = code.expires_at ? String(Number(code.expires_at)) : ''
  codeEditorVisible.value = true
}

async function submitCodeEdit() {
  codeSaving.value = true
  try {
    await updateGiftCardCode({
      id: codeForm.id,
      expires_at: codeForm.expires_at ? Number(codeForm.expires_at) : null,
      max_usage: codeForm.max_usage,
      status: codeForm.status,
    })
    ElMessage.success('兑换码信息已更新')
    codeEditorVisible.value = false
    await Promise.all([vm.loadCodes(), vm.loadStatistics()])
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '兑换码更新失败')
  } finally {
    codeSaving.value = false
  }
}
</script>

<template>
  <div class="gift-cards-page">
    <section class="page-intro">
      <div class="intro-copy">
        <h1>礼品卡管理</h1>
        <p>在这里可以管理礼品卡模板、兑换码和使用记录等功能。</p>
      </div>
    </section>

    <section class="segment-shell">
      <div class="segment-tabs">
        <button type="button" :class="['segment-tab', { 'is-active': vm.activeTab === 'templates' }]" @click="vm.activeTab = 'templates'">模板管理</button>
        <button type="button" :class="['segment-tab', { 'is-active': vm.activeTab === 'codes' }]" @click="vm.activeTab = 'codes'">兑换码管理</button>
        <button type="button" :class="['segment-tab', { 'is-active': vm.activeTab === 'usages' }]" @click="vm.activeTab = 'usages'">使用记录</button>
        <button type="button" :class="['segment-tab', { 'is-active': vm.activeTab === 'statistics' }]" @click="vm.activeTab = 'statistics'">统计数据</button>
      </div>

      <GiftCardTemplatesTab
        v-if="vm.activeTab === 'templates'"
        :loading="vm.templateLoading"
        :error="vm.templateError"
        :templates="vm.visibleTemplates"
        :keyword="vm.templateKeyword"
        :type-filter="vm.templateTypeFilter"
        :status-filter="vm.templateStatusFilter"
        :current="vm.templateCurrent"
        :page-size="vm.templatePageSize"
        :total="vm.filteredTemplates.length"
        :type-options="vm.typeOptions"
        :plans="vm.plans"
        @update:keyword="vm.templateKeyword = $event"
        @update:type-filter="vm.templateTypeFilter = $event"
        @update:status-filter="vm.templateStatusFilter = $event"
        @update:current="vm.templateCurrent = $event"
        @update:page-size="vm.templatePageSize = $event"
        @create="vm.openCreateTemplate"
        @reset="vm.resetTemplateFilters"
        @edit="vm.openEditTemplate"
        @delete="vm.handleTemplateDelete"
        @toggle="vm.handleTemplateToggle"
      />

      <GiftCardCodesTab
        v-else-if="vm.activeTab === 'codes'"
        :loading="vm.codesLoading"
        :error="vm.codesError"
        :codes="vm.visibleCodes"
        :keyword="vm.codeKeyword"
        :template-filter="vm.codeTemplateFilter"
        :status-filter="vm.codeStatusFilter"
        :current="vm.codeCurrent"
        :page-size="vm.codePageSize"
        :total="vm.filteredCodes.length"
        :templates="vm.templates"
        :resolved-batch-id="vm.resolvedBatchId"
        @update:keyword="vm.codeKeyword = $event"
        @update:template-filter="vm.codeTemplateFilter = $event"
        @update:status-filter="vm.codeStatusFilter = $event"
        @update:current="vm.codeCurrent = $event"
        @update:page-size="vm.codePageSize = $event"
        @create="vm.batchDialogVisible = true"
        @export="vm.handleExportBatch"
        @reset="vm.resetCodeFilters"
        @copy="vm.copyCode"
        @select-batch="vm.setSelectedBatchId($event)"
        @edit="openCodeEditor"
        @delete="vm.handleCodeDelete"
        @toggle="vm.handleCodeToggle"
      />

      <GiftCardUsagesTab
        v-else-if="vm.activeTab === 'usages'"
        :loading="vm.usagesLoading"
        :error="vm.usagesError"
        :usages="vm.visibleUsages"
        :keyword="vm.usageKeyword"
        :current="vm.usageCurrent"
        :page-size="vm.usagePageSize"
        :total="vm.filteredUsages.length"
        @update:keyword="vm.usageKeyword = $event"
        @update:current="vm.usageCurrent = $event"
        @update:page-size="vm.usagePageSize = $event"
        @reset="vm.resetUsageFilters"
        @refresh="vm.loadUsages"
      />

      <GiftCardStatsTab
        v-else
        :loading="vm.statsLoading"
        :error="vm.statsError"
        :statistics="vm.statistics"
      />
    </section>

    <GiftCardTemplateDrawer
      v-model:visible="vm.templateDrawerVisible"
      :mode="vm.templateDrawerMode"
      :template="vm.activeTemplate"
      :plans="vm.plans"
      :type-map="vm.typeMap"
      @success="vm.handleTemplateSuccess"
    />

    <GiftCardCodeBatchDialog
      v-model:visible="vm.batchDialogVisible"
      :templates="vm.templates.filter((item) => Boolean(item.status))"
      @success="vm.handleBatchGenerated"
    />

    <ElDialog v-model="codeEditorVisible" title="编辑兑换码" width="440px">
      <ElForm label-position="top" class="code-edit-form">
        <ElFormItem label="过期时间">
          <ElDatePicker
            v-model="codeForm.expires_at"
            type="datetime"
            value-format="X"
            class="full-width"
            placeholder="留空则长期有效"
          />
        </ElFormItem>

        <ElFormItem label="最大使用次数">
          <ElInputNumber v-model="codeForm.max_usage" :min="1" :max="1000" class="full-width" />
        </ElFormItem>

        <ElFormItem label="状态">
          <ElSelect v-model="codeForm.status" class="full-width">
            <ElOption label="未使用" :value="0" />
            <ElOption label="已使用" :value="1" />
            <ElOption label="已过期" :value="2" />
            <ElOption label="已禁用" :value="3" />
          </ElSelect>
        </ElFormItem>
      </ElForm>

      <template #footer>
        <div class="dialog-footer">
          <ElButton @click="codeEditorVisible = false">取消</ElButton>
          <ElButton type="primary" :loading="codeSaving" @click="submitCodeEdit">保存修改</ElButton>
        </div>
      </template>
    </ElDialog>
  </div>
</template>

<style lang="scss" src="./GiftCardsView.scss"></style>
