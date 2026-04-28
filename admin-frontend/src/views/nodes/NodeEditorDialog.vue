<script setup lang="ts">
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { saveNode } from '@/api/admin'
import type { AdminNodeItem, AdminNodeRouteItem, AdminNodeType, AdminServerGroupItem } from '@/types/api'
import NodeEditorProtocolSection from './NodeEditorProtocolSection.vue'
import {
  createEmptyNodeForm,
  createNodeRateRange,
  getNodeProtocolLabel,
  getNodeProtocolOptions,
  toNodeFormModel,
  toNodeSavePayload,
  type NodeFormModel,
  validateNodeForm,
} from '@/utils/nodeEditor'

const props = defineProps<{
  visible: boolean
  mode: 'create' | 'edit'
  node?: AdminNodeItem | null
  groups: AdminServerGroupItem[]
  routes: AdminNodeRouteItem[]
  nodes: AdminNodeItem[]
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  success: [message: string]
}>()

const formRef = ref<FormInstance>()
const submitting = ref(false)
const form = reactive<NodeFormModel>(createEmptyNodeForm())

const protocolOptions = computed(() => getNodeProtocolOptions())
const dialogTitle = computed(() => props.mode === 'create' ? '新建节点' : '编辑节点')
const dialogDescription = computed(() => props.mode === 'create'
  ? '管理所有节点，包括添加、删除、编辑等操作。'
  : '调整节点基础配置、协议细节与排序前置参数。')
const currentProtocolLabel = computed(() => getNodeProtocolLabel(form.type))
const parentNodeOptions = computed(() => props.nodes.filter((item) => item.id !== props.node?.id))

const rules = computed<FormRules<NodeFormModel>>(() => ({
  type: [{ required: true, message: '请选择协议类型', trigger: 'change' }],
  name: [{ required: true, message: '请输入节点名称', trigger: 'blur' }],
  host: [{ required: true, message: '请输入节点地址', trigger: 'blur' }],
  port: [{ required: true, message: '请输入连接端口', trigger: 'blur' }],
  serverPort: [{ required: true, message: '请输入服务端口', trigger: 'blur' }],
  rate: [
    {
      validator: (_rule, value, callback) => {
        if (!Number.isFinite(Number(value)) || Number(value) <= 0) {
          callback(new Error('请输入大于 0 的倍率'))
          return
        }
        callback()
      },
      trigger: 'blur',
    },
  ],
}))

function closeDialog() {
  emit('update:visible', false)
}

function syncForm() {
  Object.assign(form, toNodeFormModel(props.node))
}

function applyProtocolDefaults(type: AdminNodeType | '') {
  if (!type) {
    form.network = ''
    form.tlsMode = 0
    return
  }

  if (['vmess', 'vless', 'trojan'].includes(type) && !form.network) {
    form.network = 'tcp'
  }

  if (!['vmess', 'vless', 'trojan'].includes(type)) {
    form.network = ''
  }

  if (!['vmess', 'vless', 'trojan', 'hysteria', 'tuic', 'anytls', 'socks', 'naive', 'http'].includes(type)) {
    form.tlsMode = 0
  }

  if (type === 'trojan' && form.tlsMode === 0) {
    form.tlsMode = 1
  }
}

function addRateRange() {
  form.rateTimeRanges.push(createNodeRateRange())
}

function removeRateRange(index: number) {
  if (form.rateTimeRanges.length === 1) {
    form.rateTimeRanges.splice(0, 1, createNodeRateRange())
    return
  }
  form.rateTimeRanges.splice(index, 1)
}

async function handleSubmit() {
  const instance = formRef.value
  if (!instance) {
    return
  }

  const valid = await instance.validate().catch(() => false)
  if (!valid) {
    return
  }

  const validationMessage = validateNodeForm(form)
  if (validationMessage) {
    ElMessage.warning(validationMessage)
    return
  }

  submitting.value = true
  try {
    await saveNode(toNodeSavePayload(form))
    const message = props.mode === 'create' ? '节点已创建' : '节点已更新'
    ElMessage.success(message)
    emit('success', message)
    closeDialog()
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '节点保存失败')
  } finally {
    submitting.value = false
  }
}

watch(
  () => [props.visible, props.node, props.mode],
  ([visible]) => {
    if (!visible) {
      return
    }

    syncForm()
    applyProtocolDefaults(form.type)
    nextTick(() => {
      formRef.value?.clearValidate()
    })
  },
  { immediate: true },
)

watch(
  () => form.type,
  (value) => {
    applyProtocolDefaults(value)
  },
)

watch(
  () => form.tlsMode,
  (value) => {
    if (value !== 2) {
      form.realityServerName = ''
      form.realityServerPort = ''
      form.realityPublicKey = ''
      form.realityPrivateKey = ''
      form.realityShortId = ''
    }
    if (value === 0) {
      form.tlsServerName = ''
      form.tlsAllowInsecure = false
      form.echEnabled = false
      form.echConfig = ''
      form.echQueryServerName = ''
      form.echKey = ''
      form.utlsEnabled = false
    }
  },
)
</script>

<template>
  <ElDialog
    :model-value="props.visible"
    width="min(960px, calc(100vw - 24px))"
    top="4vh"
    destroy-on-close
    class="node-editor-dialog"
    @close="closeDialog"
    @update:model-value="emit('update:visible', $event)"
  >
    <div class="node-editor-shell">
      <header class="node-editor-hero">
        <div class="hero-copy">
          <div class="hero-copy__title">
            <h2>{{ dialogTitle }}</h2>
            <ElTag v-if="form.type" round effect="dark" class="protocol-badge">
              {{ currentProtocolLabel }}
            </ElTag>
          </div>
          <p>{{ dialogDescription }}</p>
        </div>

        <div class="hero-protocol">
          <span class="hero-protocol__label">选择协议类型</span>
          <ElSelect v-model="form.type" placeholder="选择协议类型">
            <ElOption
              v-for="option in protocolOptions"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            >
              <div class="protocol-option">
                <span class="protocol-option__dot" :style="{ background: option.dotColor }" />
                <span>{{ option.label }}</span>
              </div>
            </ElOption>
          </ElSelect>
        </div>
      </header>

      <ElForm
        ref="formRef"
        :model="form"
        :rules="rules"
        label-position="top"
        class="node-editor-form"
      >
        <section class="form-section">
          <div class="section-head">
            <div>
              <h3>基础信息</h3>
              <p>先完成节点标识、地址、权限组与展示状态等通用配置。</p>
            </div>
          </div>

          <div class="form-grid">
            <ElFormItem label="节点名称" prop="name">
              <ElInput v-model="form.name" placeholder="请输入节点名称" />
            </ElFormItem>
            <ElFormItem label="基础倍率" prop="rate">
              <ElInputNumber
                v-model="form.rate"
                :min="0.01"
                :step="0.01"
                :precision="2"
                :controls="false"
                class="full-width"
              />
            </ElFormItem>

            <ElFormItem label="启用动态倍率" class="form-grid--full">
              <div class="switch-row">
                <div>
                  <strong>根据时间段设置不同的倍率乘数</strong>
                  <span>关闭后仅使用基础倍率；开启后可配置多个倍率区间。</span>
                </div>
                <ElSwitch v-model="form.rateTimeEnable" />
              </div>
            </ElFormItem>

            <ElFormItem label="自定义节点 ID（选填）">
              <ElInput v-model="form.code" placeholder="请输入自定义节点 ID" />
            </ElFormItem>
            <ElFormItem label="节点标签">
              <ElSelect
                v-model="form.tags"
                multiple
                filterable
                allow-create
                default-first-option
                collapse-tags
                collapse-tags-tooltip
                placeholder="输入后回车添加标签"
              />
            </ElFormItem>

            <ElFormItem label="权限组">
              <ElSelect v-model="form.groupIds" multiple collapse-tags collapse-tags-tooltip placeholder="请选择权限组">
                <ElOption
                  v-for="group in props.groups"
                  :key="group.id"
                  :label="group.name"
                  :value="group.id"
                />
              </ElSelect>
            </ElFormItem>
            <ElFormItem label="父级节点">
              <ElSelect v-model="form.parentId" clearable placeholder="无">
                <ElOption
                  v-for="node in parentNodeOptions"
                  :key="node.id"
                  :label="`${node.name} (#${node.id})`"
                  :value="node.id"
                />
              </ElSelect>
            </ElFormItem>

            <ElFormItem label="节点地址" prop="host" class="form-grid--full">
              <ElInput v-model="form.host" placeholder="请输入节点域名或者 IP" />
            </ElFormItem>
            <ElFormItem label="连接端口" prop="port">
              <ElInput v-model="form.port" placeholder="用户连接端口" />
            </ElFormItem>
            <ElFormItem label="服务端口" prop="serverPort">
              <ElInput v-model="form.serverPort" placeholder="请输入服务端口" />
            </ElFormItem>

            <ElFormItem label="路由组" class="form-grid--full">
              <ElSelect v-model="form.routeIds" multiple collapse-tags collapse-tags-tooltip placeholder="选择路由组">
                <ElOption
                  v-for="route in props.routes"
                  :key="route.id"
                  :label="route.remarks"
                  :value="route.id"
                />
              </ElSelect>
            </ElFormItem>

            <ElFormItem label="节点状态" class="form-grid--full">
              <div class="switch-panel">
                <label class="switch-card">
                  <div>
                    <strong>前台显示</strong>
                    <span>{{ form.autoOnline ? '已由自动上线托管，后台会按在线状态同步。' : '开启后节点会出现在可展示列表中。' }}</span>
                  </div>
                  <ElSwitch v-model="form.show" :disabled="form.autoOnline" />
                </label>
                <label class="switch-card">
                  <div>
                    <strong>自动上线</strong>
                    <span>开启后后台会自动同步显示状态：在线显示，离线隐藏。</span>
                  </div>
                  <ElSwitch v-model="form.autoOnline" />
                </label>
                <label class="switch-card">
                  <div>
                    <strong>墙检测托管</strong>
                    <span>{{ form.parentId ? '子节点不独立检测，只控制是否随父节点自动隐藏或恢复。' : '开启后后台会自动检测并在疑似被墙时隐藏。' }}</span>
                  </div>
                  <ElSwitch v-model="form.gfwCheckEnabled" />
                </label>
                <label class="switch-card">
                  <div>
                    <strong>启用节点</strong>
                    <span>关闭后节点仍保留配置，但视为停用状态。</span>
                  </div>
                  <ElSwitch v-model="form.enabled" />
                </label>
              </div>
            </ElFormItem>
          </div>
        </section>

        <section class="form-section">
          <div class="section-head">
            <div>
              <h3>流量限额</h3>
              <p>按节点月流量控制 mi-node 内核下线与恢复。</p>
            </div>
          </div>

          <div class="form-grid">
            <ElFormItem label="启用限额" class="form-grid--full">
              <div class="switch-row">
                <div>
                  <strong>月流量限额</strong>
                  <span>达到额度后节点内核停止，重置后恢复。</span>
                </div>
                <ElSwitch v-model="form.trafficLimitEnabled" />
              </div>
            </ElFormItem>

            <ElFormItem label="月流量额度（GB）">
              <ElInputNumber
                v-model="form.trafficLimitGb"
                :min="1"
                :step="10"
                :precision="2"
                :controls="false"
                :disabled="!form.trafficLimitEnabled"
                class="full-width"
              />
            </ElFormItem>
            <ElFormItem label="重置日期">
              <ElInputNumber
                v-model="form.trafficLimitResetDay"
                :min="1"
                :max="31"
                :step="1"
                :precision="0"
                :controls="false"
                :disabled="!form.trafficLimitEnabled"
                class="full-width"
              />
            </ElFormItem>
            <ElFormItem label="重置时间">
              <ElTimePicker
                v-model="form.trafficLimitResetTime"
                value-format="HH:mm"
                format="HH:mm"
                placeholder="00:00"
                :disabled="!form.trafficLimitEnabled"
                class="full-width"
              />
            </ElFormItem>
            <ElFormItem label="时区">
              <ElInput
                v-model="form.trafficLimitTimezone"
                placeholder="Asia/Shanghai"
                :disabled="!form.trafficLimitEnabled"
              />
            </ElFormItem>
          </div>
        </section>

        <section v-if="form.rateTimeEnable" class="form-section">
          <div class="section-head">
            <div>
              <h3>动态倍率</h3>
              <p>按时间段定义倍率规则，保存时会序列化为 `rate_time_ranges`。</p>
            </div>
            <ElButton @click="addRateRange">
              <ElIcon><Plus /></ElIcon>
              添加时间段
            </ElButton>
          </div>

          <div class="rate-list">
            <article
              v-for="(item, index) in form.rateTimeRanges"
              :key="item.key"
              class="rate-item"
            >
              <div class="rate-item__grid">
                <ElFormItem label="开始时间">
                  <ElTimePicker v-model="item.start" value-format="HH:mm" format="HH:mm" placeholder="09:00" />
                </ElFormItem>
                <ElFormItem label="结束时间">
                  <ElTimePicker v-model="item.end" value-format="HH:mm" format="HH:mm" placeholder="18:00" />
                </ElFormItem>
                <ElFormItem label="倍率">
                  <ElInputNumber
                    v-model="item.rate"
                    :min="0.01"
                    :step="0.01"
                    :precision="2"
                    :controls="false"
                    class="full-width"
                  />
                </ElFormItem>
              </div>

              <div class="rate-item__footer">
                <span>规则 {{ index + 1 }}</span>
                <ElButton text type="danger" @click="removeRateRange(index)">删除</ElButton>
              </div>
            </article>
          </div>
        </section>

        <section v-if="!form.type" class="form-placeholder">
          <ElEmpty description="请选择协议类型后继续配置协议参数。">
            <p class="placeholder-copy">不同协议会自动切换不同的安全层、传输层与专属配置项。</p>
          </ElEmpty>
        </section>

        <NodeEditorProtocolSection v-else :form="form" />
      </ElForm>
    </div>

    <template #footer>
      <div class="node-editor-footer">
        <span class="footer-hint">当前协议：{{ form.type ? currentProtocolLabel : '未选择' }}</span>
        <div class="footer-actions">
          <ElButton @click="closeDialog">取消</ElButton>
          <ElButton type="primary" :loading="submitting" @click="handleSubmit">
            {{ props.mode === 'create' ? '提交' : '保存修改' }}
          </ElButton>
        </div>
      </div>
    </template>
  </ElDialog>
</template>

<style lang="scss" src="./NodeEditorDialog.scss"></style>
