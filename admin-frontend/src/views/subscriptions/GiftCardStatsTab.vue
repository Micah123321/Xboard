<script setup lang="ts">
import { computed } from 'vue'
import type { AdminGiftCardStatistics } from '@/types/api'

const props = defineProps<{
  loading: boolean
  error: string
  statistics: AdminGiftCardStatistics | null
}>()

const statsCards = computed(() => {
  const total = props.statistics?.total_stats
  return [
    { label: '模板总数', value: total?.templates_count ?? 0 },
    { label: '活跃模板数', value: total?.active_templates_count ?? 0 },
    { label: '兑换码总数', value: total?.codes_count ?? 0 },
    { label: '已使用兑换码', value: total?.used_codes_count ?? 0 },
  ]
})
</script>

<template>
  <div class="tab-panel">
    <div class="panel-copy">
      <h2>统计数据</h2>
      <p>查看礼品卡的统计数据和使用情况分析。</p>
    </div>

    <ElAlert v-if="error" type="error" :closable="false" show-icon :title="error" />

    <div class="stats-grid" v-loading="loading">
      <article v-for="item in statsCards" :key="item.label" class="stats-card">
        <span>{{ item.label }}</span>
        <strong>{{ item.value }}</strong>
      </article>
    </div>

    <div class="stats-secondary" v-if="statistics">
      <article class="stats-list-card">
        <header>
          <strong>最近 30 天使用走势</strong>
          <span>按天汇总兑换使用次数</span>
        </header>
        <ul v-if="statistics.daily_usages.length > 0">
          <li v-for="item in statistics.daily_usages.slice(-7)" :key="item.date">
            <span>{{ item.date }}</span>
            <strong>{{ item.count }}</strong>
          </li>
        </ul>
        <p v-else class="empty-copy">最近 30 天暂无使用记录</p>
      </article>

      <article class="stats-list-card">
        <header>
          <strong>模板消耗排行</strong>
          <span>按模板汇总兑换次数</span>
        </header>
        <ul v-if="statistics.type_stats.length > 0">
          <li v-for="item in statistics.type_stats.slice(0, 6)" :key="`${item.template_name}-${item.type_name}`">
            <div>
              <strong>{{ item.template_name || '未命名模板' }}</strong>
              <span>{{ item.type_name }}</span>
            </div>
            <b>{{ item.count }}</b>
          </li>
        </ul>
        <p v-else class="empty-copy">暂无模板使用数据</p>
      </article>
    </div>
  </div>
</template>
