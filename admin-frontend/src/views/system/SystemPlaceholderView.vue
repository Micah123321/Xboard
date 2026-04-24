<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { Compass, Connection, Document, Setting } from '@element-plus/icons-vue'

interface PlaceholderState {
  title: string
  description: string
  summary: Array<{ label: string; value: string }>
  endpoints: string[]
  nextSteps: string[]
}

const route = useRoute()

const placeholderMap: Record<string, PlaceholderState> = {
  SystemPlugins: {
    title: '插件管理',
    description: '本轮先稳定菜单入口与信息架构。下一阶段会接入插件扫描、启停、配置编辑与上传工作流。',
    summary: [
      { label: '当前阶段', value: '结构化占位' },
      { label: '下一阶段', value: '插件列表与启停' },
      { label: '重点边界', value: '配置编辑与上传' },
    ],
    endpoints: ['GET /plugin/getPlugins', 'POST /plugin/config', 'POST /plugin/upload'],
    nextSteps: ['展示已安装 / 可安装插件列表', '接入启用、禁用、安装、升级动作', '补齐插件配置表单与 README 说明面板'],
  },
  SystemThemes: {
    title: '主题配置',
    description: '主题管理本轮仅保留入口。后续会接入主题列表、切换、配置编辑与上传能力。',
    summary: [
      { label: '当前阶段', value: '结构化占位' },
      { label: '下一阶段', value: '主题列表与切换' },
      { label: '重点边界', value: '主题配置保存' },
    ],
    endpoints: ['GET /theme/getThemes', 'POST /theme/getThemeConfig', 'POST /theme/saveThemeConfig'],
    nextSteps: ['展示主题列表与当前启用主题', '接入主题配置动态表单', '补齐主题上传与删除的安全边界'],
  },
  SystemNotices: {
    title: '公告管理',
    description: '公告管理入口已预留。下一阶段会补齐公告列表、显隐切换、排序与编辑工作台。',
    summary: [
      { label: '当前阶段', value: '结构化占位' },
      { label: '下一阶段', value: '公告列表' },
      { label: '重点边界', value: '排序与显隐' },
    ],
    endpoints: ['GET /notice/fetch', 'POST /notice/save', 'POST /notice/sort'],
    nextSteps: ['接入公告列表和编辑抽屉', '补齐显隐切换与排序反馈', '明确弹窗公告与普通公告的字段边界'],
  },
  SystemPayments: {
    title: '支付配置',
    description: '支付配置本轮只保留入口。下一阶段会接入支付方式列表、配置表单、显隐与排序。',
    summary: [
      { label: '当前阶段', value: '结构化占位' },
      { label: '下一阶段', value: '支付方式列表' },
      { label: '重点边界', value: '网关配置安全性' },
    ],
    endpoints: ['GET /payment/fetch', 'POST /payment/save', 'POST /payment/show'],
    nextSteps: ['展示支付方式列表与状态', '接入网关配置表单', '补齐排序、通知地址与风险提示'],
  },
  SystemKnowledge: {
    title: '知识库管理',
    description: '知识库管理入口已预留。下一阶段会补齐分类筛选、文档列表、显隐和编辑工作流。',
    summary: [
      { label: '当前阶段', value: '结构化占位' },
      { label: '下一阶段', value: '知识库列表' },
      { label: '重点边界', value: '分类与排序' },
    ],
    endpoints: ['GET /knowledge/fetch', 'GET /knowledge/getCategory', 'POST /knowledge/save'],
    nextSteps: ['接入分类与文档列表', '补齐显隐、排序与删除动作', '明确 Markdown / 富文本编辑策略'],
  },
}

const pageState = computed(() => {
  const fallbackTitle = String(route.meta.title || '系统管理')
  return placeholderMap[String(route.name)] ?? {
    title: fallbackTitle,
    description: '该模块已经预留导航入口，本轮先完成结构化占位，后续继续接入真实管理能力。',
    summary: [
      { label: '当前阶段', value: '结构化占位' },
      { label: '下一阶段', value: '真实管理页' },
      { label: '重点边界', value: '接口与权限' },
    ],
    endpoints: [],
    nextSteps: ['补齐列表与编辑能力', '补齐保存、排序与删除工作流'],
  }
})
</script>

<template>
  <div class="placeholder-page">
    <section class="placeholder-hero">
      <div class="placeholder-copy">
        <p class="placeholder-kicker">System Management</p>
        <h1>{{ pageState.title }}。</h1>
        <p>{{ pageState.description }}</p>
      </div>

      <div class="placeholder-summary">
        <article v-for="item in pageState.summary" :key="item.label">
          <span>{{ item.label }}</span>
          <strong>{{ item.value }}</strong>
        </article>
      </div>
    </section>

    <section class="placeholder-shell">
      <article class="info-card">
        <div class="card-header">
          <ElIcon><Compass /></ElIcon>
          <div>
            <h2>本轮已就绪</h2>
            <p>菜单入口、路由结构和页面骨架已稳定下来，后续可以在这个基础上继续扩展。</p>
          </div>
        </div>

        <ul class="info-list">
          <li>
            <ElIcon><Setting /></ElIcon>
            <span>已接入系统管理分组，保持 Apple 风格后台的信息架构一致性。</span>
          </li>
          <li>
            <ElIcon><Connection /></ElIcon>
            <span>当前页面作为结构化占位页存在，保证导航与后续模块边界先稳定。</span>
          </li>
        </ul>
      </article>

      <article class="info-card">
        <div class="card-header">
          <ElIcon><Document /></ElIcon>
          <div>
            <h2>下一阶段接入</h2>
            <p>后续优先接入真实列表、编辑表单和状态反馈闭环。</p>
          </div>
        </div>

        <ol class="next-list">
          <li v-for="item in pageState.nextSteps" :key="item">{{ item }}</li>
        </ol>
      </article>

      <article v-if="pageState.endpoints.length" class="info-card">
        <div class="card-header">
          <ElIcon><Setting /></ElIcon>
          <div>
            <h2>已确认的后端接口</h2>
            <p>后续页面会优先对齐这些现有 Laravel 管理接口，不额外猜测后端契约。</p>
          </div>
        </div>

        <div class="endpoint-list">
          <code v-for="item in pageState.endpoints" :key="item">{{ item }}</code>
        </div>
      </article>
    </section>
  </div>
</template>

<style scoped>
.placeholder-page {
  display: grid;
  gap: 24px;
}

.placeholder-hero {
  display: flex;
  justify-content: space-between;
  gap: 24px;
  padding: 32px;
  border-radius: 28px;
  background: #000000;
}

.placeholder-copy {
  display: grid;
  gap: 12px;
  max-width: 620px;
}

.placeholder-kicker {
  margin: 0;
  color: rgba(255, 255, 255, 0.68);
  font-size: 11px;
  letter-spacing: 0.24em;
  text-transform: uppercase;
}

.placeholder-copy h1 {
  margin: 0;
  color: #ffffff;
  font-size: clamp(34px, 5vw, 52px);
  line-height: 1.08;
  letter-spacing: -0.28px;
}

.placeholder-copy p:last-child {
  margin: 0;
  color: rgba(255, 255, 255, 0.72);
  line-height: 1.6;
}

.placeholder-summary {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  min-width: 360px;
}

.placeholder-summary article {
  display: grid;
  gap: 6px;
  padding: 18px;
  border-radius: 18px;
  background: rgba(255, 255, 255, 0.08);
}

.placeholder-summary span {
  color: rgba(255, 255, 255, 0.64);
  font-size: 12px;
}

.placeholder-summary strong {
  color: #ffffff;
  font-size: 20px;
  line-height: 1.2;
}

.placeholder-shell {
  display: grid;
  gap: 18px;
}

.info-card {
  display: grid;
  gap: 18px;
  padding: 26px 28px;
  border-radius: 24px;
  background: #ffffff;
  box-shadow: var(--xboard-shadow);
}

.card-header {
  display: flex;
  align-items: flex-start;
  gap: 14px;
}

.card-header :deep(.el-icon) {
  margin-top: 4px;
  font-size: 18px;
  color: #0071e3;
}

.card-header h2 {
  margin: 0;
  color: var(--xboard-text-strong);
  font-size: 28px;
  line-height: 1.12;
  letter-spacing: -0.28px;
}

.card-header p {
  margin: 8px 0 0;
  color: var(--xboard-text-secondary);
  line-height: 1.6;
}

.info-list,
.next-list {
  display: grid;
  gap: 12px;
  padding: 0;
  margin: 0;
}

.info-list {
  list-style: none;
}

.info-list li,
.next-list li {
  display: flex;
  gap: 12px;
  color: var(--xboard-text-secondary);
  line-height: 1.6;
}

.info-list li :deep(.el-icon) {
  margin-top: 3px;
  color: var(--xboard-text-muted);
}

.next-list {
  padding-left: 18px;
}

.endpoint-list {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.endpoint-list code {
  padding: 10px 14px;
  border-radius: 999px;
  background: #f5f5f7;
  color: var(--xboard-text-secondary);
  font-family: var(--xboard-font-mono);
  font-size: 12px;
}

@media (max-width: 1080px) {
  .placeholder-hero {
    flex-direction: column;
  }

  .placeholder-summary {
    min-width: 0;
    grid-template-columns: 1fr;
  }
}
</style>
