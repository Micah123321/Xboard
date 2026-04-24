<script setup lang="ts">
const milestones = [
  '接入路由规则列表、动作类型与备注字段',
  '补齐新增 / 编辑 / 删除路由的操作台',
  '与节点页建立路由引用可视化关系，方便运营判断影响面',
]
</script>

<template>
  <div class="placeholder-page">
    <section class="placeholder-hero">
      <div class="placeholder-copy">
        <p class="placeholder-kicker">Node Routes</p>
        <h1>路由管理</h1>
        <span>侧边栏入口已对齐，下一阶段将继续补齐路由规则列表与节点引用关系。</span>
      </div>
    </section>

    <section class="placeholder-card">
      <header>
        <h2>接下来会补什么</h2>
        <p>本轮先把节点管理主链路落稳，路由管理不留空白，先把后续接入方向固定下来。</p>
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
