<script setup lang="ts">
import { useAppStore } from '@/stores/app'

const app = useAppStore()
</script>

<template>
  <div class="dashboard">
    <ElRow :gutter="20">
      <ElCol :span="24">
        <ElCard shadow="never">
          <template #header>
            <div class="card-header">
              <span>欢迎使用</span>
            </div>
          </template>
          <div class="welcome">
            <h2>{{ app.title }}</h2>
            <p class="version" v-if="app.version">版本：{{ app.version }}</p>
            <ElDivider />
            <p class="hint">管理后台正在建设中，更多功能即将上线。</p>
          </div>
        </ElCard>
      </ElCol>
    </ElRow>
  </div>
</template>

<style scoped>
.dashboard {
  padding: 0;
}

.card-header {
  display: flex;
  align-items: center;
  font-weight: 600;
}

.welcome {
  text-align: center;
  padding: 40px 0;
}

.welcome h2 {
  margin: 0 0 8px;
  font-size: 22px;
  color: var(--el-text-color-primary);
}

.version {
  color: var(--el-text-color-secondary);
  font-size: 14px;
}

.hint {
  color: var(--el-text-color-secondary);
  font-size: 14px;
}
</style>
