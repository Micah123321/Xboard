<script setup lang="ts">
import { computed } from 'vue'
import {
  getNodeProtocolHint,
  NODE_CONGESTION_CONTROL_OPTIONS,
  NODE_MUX_PROTOCOL_OPTIONS,
  NODE_SHADOWSOCKS_CIPHER_OPTIONS,
  NODE_SHADOWSOCKS_OBFS_OPTIONS,
  NODE_TCP_HEADER_OPTIONS,
  NODE_TLS_FINGERPRINT_OPTIONS,
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

      <ElFormItem
        v-if="['hysteria', 'tuic', 'anytls'].includes(props.form.type)"
        label="服务器名称（SNI）"
      >
        <ElInput v-model="props.form.tlsServerName" placeholder="example.com" />
      </ElFormItem>

      <ElFormItem v-if="showTlsSection" label="服务器名称（SNI）">
        <ElInput v-model="props.form.tlsServerName" placeholder="example.com" />
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
          <ElInput v-model="props.form.realityPrivateKey" placeholder="仅服务端维护时填写" />
        </ElFormItem>
        <ElFormItem label="Reality Short ID">
          <ElInput v-model="props.form.realityShortId" placeholder="请输入 Short ID" />
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
          <ElInput v-model="props.form.xhttpMode" placeholder="auto" />
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
          <ElInput v-model="props.form.kcpSeed" placeholder="请输入 mKCP seed" />
        </ElFormItem>
        <ElFormItem label="KCP 头部类型">
          <ElSelect v-model="props.form.kcpHeaderType" placeholder="请选择头部类型">
            <ElOption
              v-for="option in NODE_TCP_HEADER_OPTIONS"
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
          <ElInput v-model="props.form.shadowsocksPlugin" placeholder="v2ray-plugin" />
        </ElFormItem>
        <ElFormItem label="Plugin 参数">
          <ElInput v-model="props.form.shadowsocksPluginOpts" placeholder="server;tls;host=example.com" />
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
          <ElInputNumber v-model="props.form.hysteriaUpMbps" :min="0" :controls="false" class="full-width" />
        </ElFormItem>
        <ElFormItem label="下行带宽 Mbps">
          <ElInputNumber v-model="props.form.hysteriaDownMbps" :min="0" :controls="false" class="full-width" />
        </ElFormItem>
        <ElFormItem label="端口跳跃间隔（秒）">
          <ElInputNumber v-model="props.form.hysteriaHopInterval" :min="0" :controls="false" class="full-width" />
        </ElFormItem>
        <ElFormItem label="启用混淆" class="form-grid--full">
          <div class="switch-row">
            <div>
              <strong>Obfs</strong>
              <span>Hysteria 2 默认推荐 Salamander；开启后需提供密码。</span>
            </div>
            <ElSwitch v-model="props.form.hysteriaObfsEnabled" />
          </div>
        </ElFormItem>
        <ElFormItem v-if="props.form.hysteriaObfsEnabled" label="混淆类型">
          <ElInput v-model="props.form.hysteriaObfsType" placeholder="salamander" />
        </ElFormItem>
        <ElFormItem v-if="props.form.hysteriaObfsEnabled" label="混淆密码">
          <ElInput v-model="props.form.hysteriaObfsPassword" placeholder="请输入混淆密码" />
        </ElFormItem>
      </template>

      <template v-else-if="props.form.type === 'tuic'">
        <ElFormItem label="协议版本">
          <ElInputNumber v-model="props.form.tuicVersion" :min="1" :controls="false" class="full-width" />
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
          />
        </ElFormItem>
      </template>

      <template v-else-if="props.form.type === 'mieru'">
        <ElFormItem label="传输方式">
          <ElSelect v-model="props.form.mieruTransport" placeholder="请选择传输方式">
            <ElOption value="TCP" label="TCP" />
            <ElOption value="UDP" label="UDP" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="Traffic Pattern">
          <ElInput v-model="props.form.mieruTrafficPattern" placeholder="例如：steady" />
        </ElFormItem>
      </template>

      <template v-else-if="props.form.type === 'anytls'">
        <ElFormItem label="Padding Scheme" class="form-grid--full">
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
