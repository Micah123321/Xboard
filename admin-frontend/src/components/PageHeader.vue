<script setup lang="ts">
withDefaults(defineProps<{
  title: string
  stats?: ReadonlyArray<{ label: string; value: string | number }>
}>(), { stats: () => [] })
</script>

<template>
  <header class="page-header">
    <h1 class="page-header__title">{{ title }}</h1>
    <dl v-if="stats.length" class="page-header__stats" aria-label="页面统计">
      <div v-for="item in stats" :key="item.label" class="page-header__stat">
        <dt>{{ item.label }}</dt>
        <dd>{{ item.value }}</dd>
      </div>
    </dl>
    <div v-if="$slots.actions" class="page-header__actions">
      <slot name="actions" />
    </div>
    <div v-if="$slots.default" class="page-header__meta">
      <slot />
    </div>
  </header>
</template>

<style scoped>
.page-header {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px 24px;
  min-width: 0;
  padding: 16px 20px;
  border: 1px solid var(--xboard-border);
  border-radius: 16px;
  background: var(--xboard-surface);
}

.page-header__title {
  flex-shrink: 0;
  margin: 0;
  color: var(--xboard-text-strong);
  font-size: 20px;
  font-weight: 600;
  line-height: 1.4;
}

.page-header__stats {
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: 8px 24px;
  min-width: 0;
  margin: 0 0 0 auto;
}

.page-header__stat {
  display: flex;
  align-items: baseline;
  gap: 8px;
}

.page-header__stat dt {
  color: var(--xboard-text-muted);
  font-size: 13px;
  white-space: nowrap;
}

.page-header__stat dd {
  margin: 0;
  color: var(--xboard-text-strong);
  font-size: 20px;
  font-weight: 600;
  line-height: 1.4;
  font-variant-numeric: tabular-nums;
  overflow-wrap: anywhere;
}

.page-header__actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  min-width: 0;
  margin-left: auto;
}

.page-header__actions :deep(.el-button + .el-button) {
  margin-left: 0;
}

.page-header__meta {
  flex-basis: 100%;
  min-width: 0;
  color: var(--xboard-text-muted);
  font-size: 13px;
  line-height: 1.5;
}

@media (max-width: 767px) {
  .page-header {
    gap: 12px;
    padding: 14px 16px;
  }

  .page-header__stats {
    flex-basis: 100%;
    margin-left: 0;
    gap: 8px 20px;
  }

  .page-header__stat dd {
    font-size: 18px;
  }

  .page-header__actions {
    flex-basis: 100%;
    margin-left: 0;
  }
}
</style>
