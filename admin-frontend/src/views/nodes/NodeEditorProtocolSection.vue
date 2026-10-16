<script setup lang="ts">
import { computed } from 'vue'
import { MagicStick, RefreshRight } from '@element-plus/icons-vue'
import { generateMieruTrafficPattern as createMieruTrafficPattern } from '@/utils/mieruTrafficPattern'
import {
  generateRandomAnytlsPaddingScheme,
  generateRandomAnytlsSni,
} from '@/utils/nodeEditorRandomizers'
import {
  generateRandomHysteriaObfsPassword,
  generateRandomRealityShortId,
  getNodeProtocolHint,
  NODE_ALPN_OPTIONS,
  NODE_CONGESTION_CONTROL_OPTIONS,
  NODE_MUX_PROTOCOL_OPTIONS,
  NODE_KCP_HEADER_OPTIONS,
  NODE_XHTTP_MODE_OPTIONS,
  NODE_SHADOWSOCKS_PLUGIN_OPTIONS,
  NODE_SHADOWSOCKS_CIPHER_OPTIONS,
  NODE_SHADOWSOCKS_OBFS_OPTIONS,
  NODE_TCP_HEADER_OPTIONS,
  NODE_TLS_FINGERPRINT_OPTIONS,
  NODE_TUIC_VERSION_OPTIONS,
  NODE_UDP_RELAY_MODE_OPTIONS,
  NODE_VLESS_FLOW_OPTIONS,
  shouldShowRealitySettings,
  shouldShowTlsSettings,
  supportsNodeMultiplex,
  supportsNodeSecurity,
  supportsNodeTransport,
  getNodeTlsOptions,
  getNodeTransportOptions,
  type NodeFormModel,
} from '@/utils/nodeEditor'

const props = defineProps<{
  form: NodeFormModel
}>()

const transportOptions = computed(() => getNodeTransportOptions(props.form.type))
const tlsOptions = computed(() => getNodeTlsOptions(props.form.type))
const showSecuritySection = computed(() => supportsNodeSecurity(props.form.type))
const showTransportSection = computed(() => supportsNodeTransport(props.form.type))
const showMultiplexSection = computed(() => supportsNodeMultiplex(props.form.type))
const showTlsSection = computed(() => shouldShowTlsSettings(props.form.type, props.form.tlsMode))
const showRealitySection = computed(() => shouldShowRealitySettings(props.form.type, props.form.tlsMode))
const currentProtocolHint = computed(() => getNodeProtocolHint(props.form.type))

function generateMieruTrafficPattern() {
  props.form.mieruTrafficPattern = createMieruTrafficPattern()
}

function fillRandomTlsServerName() {
  props.form.tlsServerName = generateRandomAnytlsSni()
}

function fillRandomAnytlsPaddingScheme() {
  props.form.anytlsPaddingSchemeText = generateRandomAnytlsPaddingScheme()
}

function fillRandomHysteriaObfsPassword() {
  props.form.hysteriaObfsPassword = generateRandomHysteriaObfsPassword()
}

function fillRandomRealityShortId() {
  props.form.realityShortId = generateRandomRealityShortId()
}

function fillRandomKcpSeed() {
  props.form.kcpSeed = generateRandomHysteriaObfsPassword()
}

function ensureHysteriaObfsPassword() {
  if (props.form.hysteriaObfsEnabled && !props.form.hysteriaObfsPassword.trim()) {
    fillRandomHysteriaObfsPassword()
  }
}
</script>

<template>
  <section v-if="showSecuritySection" class="form-section">
    <div class="section-head">
      <div>
        <h3>安全层</h3>
        <p>根据协议切换 TLS / Reality / ECH / uTLS 等安全配置。</p>
      </div>
    </div>

    <div class="form-grid">
      <ElFormItem
        v-if="['vmess', 'vless', 'trojan', 'socks', 'naive', 'http'].includes(props.form.type)"
        label="安全性"
      >
        <ElSelect v-model="props.form.tlsMode" placeholder="请选择安全性">
          <ElOption
            v-for="option in tlsOptions"
            :key="option.value"
            :label="option.label"
            :value="option.value"
          />
        </ElSelect>
      </ElFormItem>

      <ElFormItem v-if="showTlsSection" label="服务器名称（SNI）">
        <ElInput v-model="props.form.tlsServerName" clearable placeholder="example.com">
          <template #append>
            <ElButton :icon="RefreshRight" @click="fillRandomTlsServerName">随机域名</ElButton>
          </template>
        </ElInput>
      </ElFormItem>

      <ElFormItem v-if="showTlsSection || ['hysteria', 'tuic', 'anytls'].includes(props.form.type)" label="允许不安全连接">
        <ElSwitch v-model="props.form.tlsAllowInsecure" />
      </ElFormItem>

      <ElFormItem v-if="showTlsSection || ['hysteria', 'tuic', 'anytls'].includes(props.form.type)" label="启用 ECH" class="form-grid--full">
        <div class="switch-row">
          <div>
            <strong>Encrypted Client Hello</strong>
            <span>用于支持 ECH 的 TLS 场景；关闭时不会写入 ECH 配置。</span>
          </div>
          <ElSwitch v-model="props.form.echEnabled" />
        </div>
      </ElFormItem>

      <template v-if="props.form.echEnabled">
        <ElFormItem label="ECH Config" class="form-grid--full">
          <ElInput
            v-model="props.form.echConfig"
            type="textarea"
            :autosize="{ minRows: 3, maxRows: 5 }"
            placeholder="粘贴 ECH Config"
          />
        </ElFormItem>
        <ElFormItem label="ECH 查询域名">
          <ElInput v-model="props.form.echQueryServerName" placeholder="ech.example.com" />
        </ElFormItem>
        <ElFormItem label="ECH Key（可选）">
          <ElInput v-model="props.form.echKey" placeholder="仅服务端维护时填写" />
        </ElFormItem>
      </template>

      <ElFormItem
        v-if="showTlsSection || showRealitySection"
        label="启用 uTLS"
        class="form-grid--full"
      >
        <div class="switch-row">
          <div>
            <strong>uTLS 指纹伪装</strong>
            <span>适用于需要模拟客户端指纹的连接场景。</span>
          </div>
          <ElSwitch v-model="props.form.utlsEnabled" />
        </div>
      </ElFormItem>

      <ElFormItem v-if="props.form.utlsEnabled" label="uTLS 指纹">
        <ElSelect v-model="props.form.utlsFingerprint" placeholder="请选择指纹">
          <ElOption
            v-for="option in NODE_TLS_FINGERPRINT_OPTIONS"
            :key="option.value"
            :label="option.label"
            :value="option.value"
          />
        </ElSelect>
      </ElFormItem>

      <template v-if="showRealitySection">
        <ElFormItem label="Reality 服务器名称">
          <ElInput v-model="props.form.realityServerName" placeholder="www.cloudflare.com" />
        </ElFormItem>
        <ElFormItem label="Reality 服务器端口">
          <ElInput v-model="props.form.realityServerPort" placeholder="443" />
        </ElFormItem>
        <ElFormItem label="Reality 公钥" class="form-grid--full">
          <ElInput v-model="props.form.realityPublicKey" placeholder="请输入公钥" />
        </ElFormItem>
        <ElFormItem label="Reality 私钥" class="form-grid--full">
          <ElInput v-model="props.form.realityPrivateKey" placeholder="填写与公钥配对的服务端私钥" autocomplete="off" />
        </ElFormItem>
        <ElFormItem label="Reality Short ID">
          <ElInput v-model="props.form.realityShortId" placeholder="请输入或随机生成 Short ID" maxlength="16">
            <template #append>
              <ElButton :icon="RefreshRight" @click="fillRandomRealityShortId">随机生成</ElButton>
            </template>
          </ElInput>
        </ElFormItem>
      </template>
    </div>
  </section>

  <section v-if="showTransportSection" class="form-section">
    <div class="section-head">
      <div>
        <h3>传输层</h3>
        <p>按不同传输协议切换对应字段，避免把所有参数堆到同一层。</p>
      </div>
    </div>

    <div class="form-grid">
      <ElFormItem label="传输协议">
        <ElSelect v-model="props.form.network" placeholder="请选择传输协议">
          <ElOption
            v-for="option in transportOptions"
            :key="option.value"
            :label="option.label"
            :value="option.value"
          />
        </ElSelect>
      </ElFormItem>

      <ElFormItem v-if="props.form.network === 'tcp'" label="TCP 头部类型">
        <ElSelect v-model="props.form.tcpHeaderType" placeholder="请选择头部类型">
          <ElOption
            v-for="option in NODE_TCP_HEADER_OPTIONS"
            :key="option.value"
            :label="option.label"
            :value="option.value"
          />
        </ElSelect>
      </ElFormItem>

      <template v-if="props.form.network === 'tcp' && props.form.tcpHeaderType === 'http'">
        <ElFormItem label="请求路径" class="form-grid--full">
          <ElInput
            v-model="props.form.tcpRequestPath"
            type="textarea"
            :autosize="{ minRows: 3, maxRows: 4 }"
            placeholder="每行一个 path，例如：&#10;/api&#10;/ws"
          />
        </ElFormItem>
        <ElFormItem label="Host 列表" class="form-grid--full">
          <ElInput v-model="props.form.tcpRequestHost" placeholder="多个 Host 用逗号分隔" />
        </ElFormItem>
      </template>

      <template v-if="props.form.network === 'ws'">
        <ElFormItem label="路径">
          <ElInput v-model="props.form.wsPath" placeholder="/ws" />
        </ElFormItem>
        <ElFormItem label="Host">
          <ElInput v-model="props.form.wsHost" placeholder="ws.example.com" />
        </ElFormItem>
      </template>

      <ElFormItem v-if="props.form.network === 'grpc'" label="Service Name">
        <ElInput v-model="props.form.grpcServiceName" placeholder="grpc-service" />
      </ElFormItem>

      <template v-if="props.form.network === 'h2'">
        <div class="protocol-default-hint form-grid--full">H2 需要兼容的节点内核；当前 mi-node Xray 已移除此传输，可选择 HTTPUpgrade 或 XHTTP。</div>
        <ElFormItem label="路径">
          <ElInput v-model="props.form.h2Path" placeholder="/" />
        </ElFormItem>
        <ElFormItem label="Host 列表">
          <ElInput v-model="props.form.h2Host" placeholder="多个 Host 用逗号分隔" />
        </ElFormItem>
      </template>

      <template v-if="props.form.network === 'httpupgrade'">
        <ElFormItem label="路径">
          <ElInput v-model="props.form.httpupgradePath" placeholder="/upgrade" />
        </ElFormItem>
        <ElFormItem label="Host">
          <ElInput v-model="props.form.httpupgradeHost" placeholder="upgrade.example.com" />
        </ElFormItem>
      </template>

      <template v-if="props.form.network === 'xhttp'">
        <ElFormItem label="路径">
          <ElInput v-model="props.form.xhttpPath" placeholder="/connect" />
        </ElFormItem>
        <ElFormItem label="Host">
          <ElInput v-model="props.form.xhttpHost" placeholder="xhttp.example.com" />
        </ElFormItem>
        <ElFormItem label="模式">
          <ElSelect v-model="props.form.xhttpMode" placeholder="请选择 XHTTP 模式">
            <ElOption v-for="option in NODE_XHTTP_MODE_OPTIONS" :key="option.value" :value="option.value" :label="option.label" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="额外参数 JSON" class="form-grid--full">
          <ElInput
            v-model="props.form.xhttpExtra"
            type="textarea"
            :autosize="{ minRows: 3, maxRows: 5 }"
            placeholder='例如：{ "mode": "stream-one" }'
          />
        </ElFormItem>
      </template>

      <template v-if="props.form.network === 'kcp'">
        <ElFormItem label="Seed">
          <ElInput v-model="props.form.kcpSeed" placeholder="请输入或随机生成 mKCP seed">
            <template #append>
              <ElButton :icon="RefreshRight" @click="fillRandomKcpSeed">随机生成</ElButton>
            </template>
          </ElInput>
        </ElFormItem>
        <ElFormItem label="KCP 头部类型">
          <ElSelect v-model="props.form.kcpHeaderType" placeholder="请选择头部类型">
            <ElOption
              v-for="option in NODE_KCP_HEADER_OPTIONS"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            />
          </ElSelect>
        </ElFormItem>
      </template>
    </div>
  </section>

  <section class="form-section">
    <div class="section-head">
      <div>
        <h3>协议配置</h3>
        <p>{{ currentProtocolHint }}</p>
        <p v-if="props.form.type === 'snell'">使用独立 Snell Agent，按节点 ID 接入。</p>
      </div>
    </div>

    <div class="form-grid">
      <template v-if="props.form.type === 'shadowsocks'">
        <ElFormItem label="加密方式">
          <ElSelect v-model="props.form.shadowsocksCipher" placeholder="请选择加密方式">
            <ElOption
              v-for="option in NODE_SHADOWSOCKS_CIPHER_OPTIONS"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="混淆方式">
          <ElSelect v-model="props.form.shadowsocksObfs" placeholder="请选择混淆">
            <ElOption
              v-for="option in NODE_SHADOWSOCKS_OBFS_OPTIONS"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem v-if="props.form.shadowsocksObfs" label="混淆 Host">
          <ElInput v-model="props.form.shadowsocksObfsHost" placeholder="obfs host" />
        </ElFormItem>
        <ElFormItem v-if="props.form.shadowsocksObfs" label="混淆路径">
          <ElInput v-model="props.form.shadowsocksObfsPath" placeholder="/path" />
        </ElFormItem>
        <ElFormItem label="Plugin">
          <ElSelect v-model="props.form.shadowsocksPlugin" filterable allow-create default-first-option placeholder="选择插件或输入自定义名称">
            <ElOption v-for="option in NODE_SHADOWSOCKS_PLUGIN_OPTIONS" :key="option.value" :value="option.value" :label="option.label" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="Plugin 参数">
          <ElInput v-model="props.form.shadowsocksPluginOpts" placeholder="server;tls;host=example.com" />
        </ElFormItem>
      </template>

      <template v-else-if="props.form.type === 'snell'">
        <ElFormItem label="版本 / 传输">
          <ElInput model-value="v4 / TCP" readonly />
        </ElFormItem>
        <ElFormItem label="混淆方式">
          <ElSelect v-model="props.form.snellObfs">
            <ElOption value="off" label="关闭" />
            <ElOption value="http" label="HTTP" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem v-if="props.form.snellObfs === 'http'" label="混淆 Host">
          <ElInput v-model="props.form.snellObfsHost" clearable maxlength="255" placeholder="可选，例如 example.com" />
        </ElFormItem>
      </template>

      <template v-else-if="props.form.type === 'vless'">
        <ElFormItem label="Flow">
          <ElSelect v-model="props.form.vlessFlow" placeholder="请选择 Flow">
            <ElOption
              v-for="option in NODE_VLESS_FLOW_OPTIONS"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="启用自定义加密" class="form-grid--full">
          <div class="switch-row">
            <div>
              <strong>VLess Encryption</strong>
              <span>适用于需要额外加解密配置的场景。</span>
            </div>
            <ElSwitch v-model="props.form.vlessEncryptionEnabled" />
          </div>
        </ElFormItem>
        <ElFormItem v-if="props.form.vlessEncryptionEnabled" label="客户端公钥">
          <ElInput v-model="props.form.vlessEncryption" placeholder="encryption key" />
        </ElFormItem>
        <ElFormItem v-if="props.form.vlessEncryptionEnabled" label="服务端私钥">
          <ElInput v-model="props.form.vlessDecryption" placeholder="decryption key" />
        </ElFormItem>
      </template>

      <template v-else-if="props.form.type === 'hysteria'">
        <ElFormItem label="协议版本">
          <ElSelect v-model="props.form.hysteriaVersion" placeholder="请选择版本">
            <ElOption :value="1" label="Hysteria 1" />
            <ElOption :value="2" label="Hysteria 2" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="上行带宽 Mbps">
          <ElInputNumber v-model="props.form.hysteriaUpMbps" :min="0" :precision="0" :controls="false" class="full-width" />
        </ElFormItem>
        <ElFormItem label="下行带宽 Mbps">
          <ElInputNumber v-model="props.form.hysteriaDownMbps" :min="0" :precision="0" :controls="false" class="full-width" />
        </ElFormItem>
        <ElFormItem label="端口跳跃间隔（秒）">
          <ElInputNumber v-model="props.form.hysteriaHopInterval" :min="0" :precision="0" :controls="false" class="full-width" />
        </ElFormItem>
        <div class="protocol-default-hint form-grid--full">
          <p>Hysteria 2 上下行默认均为 0，使用 BBR 自适应流控；填写带宽时请按实际链路能力设置。</p>
          <p>跳跃间隔默认 30 秒，仅配置端口范围时生效；固定单端口不跳跃，填 0 使用客户端默认间隔。</p>
        </div>
        <ElFormItem label="启用混淆" class="form-grid--full">
          <div class="switch-row">
            <div>
              <strong>Obfs</strong>
              <span>Hysteria 2 默认使用 Salamander，新建时自动生成混淆密码。</span>
            </div>
            <ElSwitch v-model="props.form.hysteriaObfsEnabled" @change="ensureHysteriaObfsPassword" />
          </div>
        </ElFormItem>
        <ElFormItem v-if="props.form.hysteriaObfsEnabled && props.form.hysteriaVersion === 2" label="混淆类型">
          <ElSelect v-model="props.form.hysteriaObfsType" placeholder="请选择混淆类型">
            <ElOption value="salamander" label="Salamander" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem v-if="props.form.hysteriaObfsEnabled" label="混淆密码">
          <ElInput v-model="props.form.hysteriaObfsPassword" placeholder="请输入或随机生成混淆密码" autocomplete="off">
            <template #append>
              <ElButton :icon="RefreshRight" @click="fillRandomHysteriaObfsPassword">随机生成</ElButton>
            </template>
          </ElInput>
        </ElFormItem>
      </template>

      <template v-else-if="props.form.type === 'tuic'">
        <ElFormItem label="协议版本">
          <ElSelect v-model="props.form.tuicVersion" placeholder="请选择版本">
            <ElOption
              v-for="option in NODE_TUIC_VERSION_OPTIONS"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="拥塞控制">
          <ElSelect v-model="props.form.tuicCongestionControl" placeholder="请选择拥塞控制">
            <ElOption
              v-for="option in NODE_CONGESTION_CONTROL_OPTIONS"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="UDP Relay Mode">
          <ElSelect v-model="props.form.tuicUdpRelayMode" placeholder="请选择模式">
            <ElOption
              v-for="option in NODE_UDP_RELAY_MODE_OPTIONS"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="ALPN">
          <ElSelect
            v-model="props.form.tuicAlpn"
            multiple
            filterable
            allow-create
            default-first-option
            placeholder="输入后回车添加 ALPN"
          >
            <ElOption
              v-for="option in NODE_ALPN_OPTIONS"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            />
          </ElSelect>
        </ElFormItem>
        <div class="protocol-default-hint form-grid--full">mi-node 的 TUIC 入站使用 V5；选择 V4 时请确认节点内核支持。</div>
      </template>

      <template v-else-if="props.form.type === 'mieru'">
        <ElFormItem label="传输方式">
          <ElSelect v-model="props.form.mieruTransport" placeholder="请选择传输方式">
            <ElOption value="TCP" label="TCP" />
            <ElOption value="UDP" label="UDP" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="Traffic Pattern">
          <ElInput v-model="props.form.mieruTrafficPattern" clearable placeholder="点击随机生成或输入 Base64">
            <template #append>
              <ElButton @click="generateMieruTrafficPattern">随机生成</ElButton>
            </template>
          </ElInput>
        </ElFormItem>
        <div class="protocol-default-hint form-grid--full">Traffic Pattern 可留空使用核心默认模式，也可点击随机生成。</div>
      </template>

      <template v-else-if="props.form.type === 'anytls'">
        <ElFormItem label="Padding Scheme" class="form-grid--full">
          <div class="switch-row">
            <div>
              <strong>随机填充</strong>
              <span>点击后会写入一组非默认的多行 Padding Scheme。</span>
            </div>
            <ElButton :icon="MagicStick" @click="fillRandomAnytlsPaddingScheme">随机生成</ElButton>
          </div>
          <ElInput
            v-model="props.form.anytlsPaddingSchemeText"
            type="textarea"
            :autosize="{ minRows: 4, maxRows: 8 }"
            placeholder="每行一条 padding scheme"
          />
        </ElFormItem>
      </template>
    </div>
  </section>

  <section v-if="showMultiplexSection" class="form-section">
    <div class="section-head">
      <div>
        <h3>多路复用</h3>
        <p>对支持的协议开放多路复用与 Brutal 加速配置。</p>
      </div>
    </div>

    <div class="form-grid">
      <ElFormItem label="启用多路复用" class="form-grid--full">
        <div class="switch-row">
          <div>
            <strong>Multiplex</strong>
            <span>适用于 VLess / VMess / Trojan / Mieru 的复用场景。</span>
          </div>
          <ElSwitch v-model="props.form.multiplexEnabled" />
        </div>
      </ElFormItem>

      <template v-if="props.form.multiplexEnabled">
        <div class="protocol-default-hint form-grid--full">默认使用 yamux 和 4 个连接。Brutal 默认关闭；启用后请按实际线路调整上下行带宽。</div>
        <ElFormItem label="复用协议">
          <ElSelect v-model="props.form.multiplexProtocol" placeholder="请选择协议">
            <ElOption
              v-for="option in NODE_MUX_PROTOCOL_OPTIONS"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="最大连接数">
          <ElInputNumber
            v-model="props.form.multiplexMaxConnections"
            :min="1"
            :controls="false"
            class="full-width"
          />
        </ElFormItem>
        <ElFormItem label="填充">
          <ElSwitch v-model="props.form.multiplexPadding" />
        </ElFormItem>
        <ElFormItem label="启用 Brutal">
          <ElSwitch v-model="props.form.multiplexBrutalEnabled" />
        </ElFormItem>

        <template v-if="props.form.multiplexBrutalEnabled">
          <ElFormItem label="Brutal 上行 Mbps">
            <ElInputNumber
              v-model="props.form.multiplexBrutalUpMbps"
              :min="1"
              :controls="false"
              class="full-width"
            />
          </ElFormItem>
          <ElFormItem label="Brutal 下行 Mbps">
            <ElInputNumber
              v-model="props.form.multiplexBrutalDownMbps"
              :min="1"
              :controls="false"
              class="full-width"
            />
          </ElFormItem>
        </template>
      </template>
    </div>
  </section>
</template>
