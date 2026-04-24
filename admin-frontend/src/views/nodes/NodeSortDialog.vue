<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { ArrowDown, ArrowUp } from '@element-plus/icons-vue'
import { sortNodes } from '@/api/admin'
import type { AdminNodeItem } from '@/types/api'
import { getNodeProtocolLabel, moveNodeOrder, sortNodesByOrder } from '@/utils/nodeEditor'

const props = defineProps<{
  visible: boolean
  nodes: AdminNodeItem[]
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  success: [message: string]
}>()

const submitting = ref(false)
const draft = ref<AdminNodeItem[]>([])

const sortedDraft = computed(() => draft.value)

function closeDialog() {
  emit('update:visible', false)
}

function moveDraft(index: number, direction: -1 | 1) {
  draft.value = moveNodeOrder(draft.value, index, direction)
}

async function handleSubmit() {
  submitting.value = true
  try {
    await sortNodes(
      draft.value.map((item, index) => ({
        id: item.id,
        order: index + 1,
      })),
    )
    const message = '节点排序已保存'
    ElMessage.success(message)
    emit('success', message)
    closeDialog()
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '节点排序保存失败')
  } finally {
    submitting.value = false
  }
}

watch(
  () => [props.visible, props.nodes],
  ([visible]) => {
    if (!visible) {
      return
    }

    draft.value = sortNodesByOrder(props.nodes).map((item) => ({ ...item }))
  },
  { immediate: true },
)
</script>

<template>
  <ElDialog
    :model-value="props.visible"
    width="min(720px, calc(100vw - 32px))"
    class="node-sort-dialog"
    title="编辑排序"
    @close="closeDialog"
    @update:model-value="emit('update:visible', $event)"
  >
    <div class="sort-shell">
      <p class="sort-copy">按照当前展示顺序调整节点排序，保存后会同步到后台 `/server/manage/sort`。</p>

      <div class="sort-list">
        <article
          v-for="(item, index) in sortedDraft"
          :key="item.id"
          class="sort-item"
        >
          <div class="sort-item__main">
            <span class="sort-index">{{ index + 1 }}</span>
            <div class="sort-meta">
              <strong>{{ item.name }}</strong>
              <span>
                {{ getNodeProtocolLabel(item.type) }}
                · {{ item.host }}:{{ item.server_port || item.port }}
              </span>
            </div>
          </div>

          <div class="sort-actions">
            <ElButton :disabled="index === 0" @click="moveDraft(index, -1)">
              <ElIcon><ArrowUp /></ElIcon>
              上移
            </ElButton>
            <ElButton :disabled="index === sortedDraft.length - 1" @click="moveDraft(index, 1)">
              <ElIcon><ArrowDown /></ElIcon>
              下移
            </ElButton>
          </div>
        </article>
      </div>
    </div>

    <template #footer>
      <div class="sort-footer">
        <ElButton @click="closeDialog">取消</ElButton>
        <ElButton type="primary" :loading="submitting" @click="handleSubmit">
          保存排序
        </ElButton>
      </div>
    </template>
  </ElDialog>
</template>

<style scoped lang="scss" src="./NodeSortDialog.scss"></style>
