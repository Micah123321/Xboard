<script setup lang="ts">
const milestones = [
  '接入权限组列表与用户 / 节点引用统计',
  '补齐新增、编辑、删除与使用冲突提示',
  '联动节点页的权限组筛选与维护闭环',
]
</script>

<template>
  <div class="placeholder-page">
    <section class="placeholder-hero">
      <div class="placeholder-copy">
        <p class="placeholder-kicker">Node Groups</p>
        <h1>权限组管理</h1>
        <span>入口已预留。本轮先完成节点列表主链路，下一阶段继续接入权限组的真实维护能力。</span>
      </div>
    </section>

    <section class="placeholder-card">
      <header>
        <h2>下一阶段计划</h2>
        <p>这一页不会空着结束，而是明确告诉你后续要接什么。</p>
      </header>

      <ol>
        <li v-for="item in milestones" :key="item">{{ item }}</li>
      </ol>
    </section>
  </div>
</template>

<style scoped>
.placeholder-page {
  display: grid;
  gap: 24px;
}

.placeholder-hero {
  padding: 30px 32px;
  border-radius: 28px;
  background: #000000;
}

.placeholder-copy {
  display: grid;
  gap: 10px;
  max-width: 720px;
}

.placeholder-kicker {
  font-size: 11px;
  letter-spacing: 0.24em;
  text-transform: uppercase;
  color: rgba(255, 255, 255, 0.68);
}

.placeholder-copy h1 {
  font-size: clamp(34px, 5vw, 48px);
  line-height: 1.08;
  letter-spacing: -0.28px;
  color: #ffffff;
}

.placeholder-copy span {
  color: rgba(255, 255, 255, 0.72);
  line-height: 1.47;
}

.placeholder-card {
  display: grid;
  gap: 18px;
  padding: 28px;
  border-radius: 24px;
  background: #ffffff;
  box-shadow: var(--xboard-shadow);
}

.placeholder-card header {
  display: grid;
  gap: 8px;
}

.placeholder-card h2 {
  font-size: 28px;
  line-height: 1.1;
  color: var(--xboard-text-strong);
}

.placeholder-card p,
.placeholder-card li {
  color: var(--xboard-text-secondary);
  line-height: 1.6;
}

.placeholder-card ol {
  display: grid;
  gap: 12px;
  padding-left: 20px;
}
</style>
