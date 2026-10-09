<script setup lang="ts">
import { reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import type { Artifact, ReleaseInput } from '@/types/nodeUpdates'
import { validateRelease } from './model'
defineProps<{ busy: boolean }>()
const emit = defineEmits<{ publish: [data: ReleaseInput] }>()
const version = ref('')
const protocol = ref(1)
const arches = ref(['amd64', 'arm64'])
const artifacts = reactive<Artifact[]>(['amd64', 'arm64'].flatMap(arch => ['mi-node', 'xbctl'].map(component => ({ arch, component, https_url: '', sha256: '', size_bytes: 1 } as Artifact))))
function submit() {
 try {
  const selected = artifacts.filter(a => arches.value.includes(a.arch))
  validateRelease(version.value, selected)
  emit('publish', { version: version.value, os: 'linux', min_agent_protocol: protocol.value, artifacts: JSON.parse(JSON.stringify(selected)) })
 } catch (error) { ElMessage.error(error instanceof Error ? error.message : '请检查制品信息') }
}
</script>
<template>
 <el-form label-position="top" :disabled="busy" @submit.prevent="submit">
  <el-alert title="发布后不可修改，只能撤销。请使用可信发布构建的散列，文件为纯二进制。" type="warning" :closable="false" />
  <el-form-item label="固定版本"><el-input v-model="version" placeholder="v1.2.3" /></el-form-item>
  <el-form-item label="最低 agent 协议版本"><el-input-number v-model="protocol" :min="1" :max="9007199254740991" :precision="0" /></el-form-item>
  <el-checkbox-group v-model="arches"><el-checkbox value="amd64">amd64</el-checkbox><el-checkbox value="arm64">arm64</el-checkbox></el-checkbox-group>
  <template v-for="artifact in artifacts" :key="artifact.arch + artifact.component">
   <el-card v-if="arches.includes(artifact.arch)" shadow="never">
    <strong>{{ artifact.arch }} / {{ artifact.component }}</strong>
    <el-form-item label="HTTPS 下载地址"><el-input v-model="artifact.https_url" maxlength="2048" /></el-form-item>
    <el-form-item label="SHA256"><el-input v-model="artifact.sha256" maxlength="64" /></el-form-item>
    <el-form-item label="文件字节数"><el-input-number v-model="artifact.size_bytes" :min="1" :max="536870912" :precision="0" /></el-form-item>
   </el-card>
  </template>
  <el-button type="primary" native-type="submit" :loading="busy">发布固定版本</el-button>
 </el-form>
</template>
