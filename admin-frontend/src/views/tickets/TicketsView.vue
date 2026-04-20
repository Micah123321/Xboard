<script setup lang="ts">
const features = [
  '工单列表与状态筛选',
  '工单会话详情与回复',
  '关闭工单与处理记录',
]
</script>

<template>
  <section class="tickets-placeholder">
    <div class="placeholder-copy">
      <p>Tickets</p>
      <h1>工单管理将在下一步补齐。</h1>
      <span>本轮先把导航和路由结构铺平，避免后续再改后台信息架构。</span>
    </div>

    <div class="placeholder-card">
      <strong>预留能力</strong>
      <ul>
        <li v-for="item in features" :key="item">{{ item }}</li>
      </ul>
    </div>
  </section>
</template>

<style scoped>
.tickets-placeholder {
  display: grid;
  gap: 20px;
  padding: 32px;
  border-radius: 26px;
  background: #ffffff;
  box-shadow: var(--xboard-shadow);
}

.placeholder-copy {
  display: grid;
  gap: 8px;
}

.placeholder-copy p {
  font-size: 11px;
  letter-spacing: 0.24em;
  text-transform: uppercase;
  color: var(--xboard-text-muted);
}

.placeholder-copy h1 {
  font-size: clamp(30px, 5vw, 44px);
  line-height: 1.1;
  letter-spacing: -0.28px;
}

.placeholder-copy span {
  color: var(--xboard-text-secondary);
  max-width: 620px;
  line-height: 1.47;
}

.placeholder-card {
  padding: 22px 24px;
  border-radius: 22px;
  background: #f5f5f7;
}

.placeholder-card strong {
  display: block;
  margin-bottom: 12px;
}

.placeholder-card ul {
  display: grid;
  gap: 8px;
  padding-left: 18px;
  color: var(--xboard-text-secondary);
}
</style>
