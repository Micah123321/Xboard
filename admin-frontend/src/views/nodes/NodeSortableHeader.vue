<script setup lang="ts">
import { computed } from 'vue'
import { ArrowDown, ArrowUp, Sort } from '@element-plus/icons-vue'

const props = defineProps<{
  label: string
  state: '默认' | '置顶' | '置底'
  active: boolean
  sortAriaLabel: string
}>()

defineEmits<{
  click: []
}>()

const iconComponent = computed(() => {
  if (!props.active) {
    return Sort
  }
  return props.state === '置顶' ? ArrowUp : ArrowDown
})
</script>

<template>
  <button
    type="button"
    class="sortable-header"
    :class="{ 'sortable-header--active': active }"
    :aria-label="sortAriaLabel"
    @click="$emit('click')"
  >
    <span>{{ label }}</span>
    <ElIcon><component :is="iconComponent" /></ElIcon>
    <small>{{ state }}</small>
  </button>
</template>

<style scoped>
.sortable-header {
  display: inline-flex;
  align-items: center;
  max-width: 100%;
  gap: 5px;
  padding: 0;
  border: 0;
  background: transparent;
  color: inherit;
  font: inherit;
  line-height: 1.2;
  cursor: pointer;
}

.sortable-header span {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.sortable-header .el-icon {
  flex: 0 0 auto;
  font-size: 13px;
  color: #8a8f98;
  transition: color 0.18s ease;
}

.sortable-header small {
  flex: 0 0 auto;
  padding: 2px 6px;
  border-radius: 999px;
  background: #f1f5f9;
  color: #6b7280;
  font-size: 11px;
  font-weight: 600;
  line-height: 1.35;
}

.sortable-header:hover,
.sortable-header:focus-visible,
.sortable-header--active {
  color: #0071e3;
}

.sortable-header:hover .el-icon,
.sortable-header:focus-visible .el-icon,
.sortable-header--active .el-icon {
  color: #0071e3;
}

.sortable-header--active small {
  background: rgba(0, 113, 227, 0.12);
  color: #0071e3;
}

.sortable-header:focus-visible {
  outline: 2px solid #0071e3;
  outline-offset: 3px;
  border-radius: 8px;
}
</style>
