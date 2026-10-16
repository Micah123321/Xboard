import type { AdminNodeType, AdminPlanListItem } from '@/types/api'

export interface NodeOption<T extends string | number = string> {
  value: T
  label: string
  dotColor?: string
}

export interface NodeRateRangeForm {
  key: string
  start: string
  end: string
  rate: number
}

export interface NodeFormModel {
  id?: number
  originalType: AdminNodeType | ''
  type: AdminNodeType | ''
  rawProtocolSettings: Record<string, unknown>
  name: string
  code: string
  rate: number
  trafficLimitEnabled: boolean
  trafficLimitGb: number | null
  trafficLimitResetDay: number | null
  trafficLimitResetTime: string
  trafficLimitTimezone: string
  rateTimeEnable: boolean
  rateTimeRanges: NodeRateRangeForm[]
  tags: string[]
  groupIds: number[]
  planRestrictionEnabled: boolean
  allowedPlanIds: number[]
  subscriptionLabelId?: number | null
  routeIds: number[]
  machineId: number | null
  host: string
  port: string
  serverPort: string
  parentId: number | null
  tcpCheckEnabled: boolean
  bandwidthCheckEnabled: boolean
  show: boolean
  autoOnline: boolean
  autoOnlineCooldownEnabled: boolean
  gfwCheckEnabled: boolean
  enabled: boolean
  tlsMode: number
  tlsServerName: string
  tlsAllowInsecure: boolean
  echEnabled: boolean
  echConfig: string
  echQueryServerName: string
  echKey: string
  utlsEnabled: boolean
  utlsFingerprint: string
  realityServerName: string
  realityServerPort: string
  realityPublicKey: string
  realityPrivateKey: string
  realityShortId: string
  network: string
  tcpHeaderType: string
  tcpRequestPath: string
  tcpRequestHost: string
  wsPath: string
  wsHost: string
  grpcServiceName: string
  h2Path: string
  h2Host: string
  httpupgradePath: string
  httpupgradeHost: string
  xhttpPath: string
  xhttpHost: string
  xhttpMode: string
  xhttpExtra: string
  kcpSeed: string
  kcpHeaderType: string
  shadowsocksCipher: string
  shadowsocksObfs: string
  shadowsocksObfsHost: string
  shadowsocksObfsPath: string
  shadowsocksPlugin: string
  shadowsocksPluginOpts: string
  vlessFlow: string
  vlessEncryptionEnabled: boolean
  vlessEncryption: string
  vlessDecryption: string
  hysteriaVersion: number
  hysteriaUpMbps: number | null
  hysteriaDownMbps: number | null
  hysteriaObfsEnabled: boolean
  hysteriaObfsType: string
  hysteriaObfsPassword: string
  hysteriaHopInterval: number | null
  tuicVersion: number | null
  tuicCongestionControl: string
  tuicAlpn: string[]
  tuicUdpRelayMode: string
  mieruTransport: string
  mieruTrafficPattern: string
  anytlsPaddingSchemeText: string
  snellObfs: 'off' | 'http'
  snellObfsHost: string
  multiplexEnabled: boolean
  multiplexProtocol: string
  multiplexMaxConnections: number | null
  multiplexPadding: boolean
  multiplexBrutalEnabled: boolean
  multiplexBrutalUpMbps: number | null
  multiplexBrutalDownMbps: number | null
}

export const NODE_PROTOCOL_OPTIONS: Array<NodeOption<AdminNodeType>> = [
  { value: 'shadowsocks', label: 'Shadowsocks', dotColor: '#44a35f' },
  { value: 'vmess', label: 'VMess', dotColor: '#d94696' },
  { value: 'trojan', label: 'Trojan', dotColor: '#f3b74f' },
  { value: 'hysteria', label: 'Hysteria', dotColor: '#5d84ff' },
  { value: 'vless', label: 'VLess', dotColor: '#111111' },
  { value: 'tuic', label: 'TUIC', dotColor: '#22c55e' },
  { value: 'socks', label: 'SOCKS', dotColor: '#3b82f6' },
  { value: 'naive', label: 'Naive', dotColor: '#8b3dff' },
  { value: 'http', label: 'HTTP', dotColor: '#ff5c2b' },
  { value: 'mieru', label: 'Mieru', dotColor: '#4caf50' },
  { value: 'anytls', label: 'AnyTLS', dotColor: '#8e59d1' },
  { value: 'snell', label: 'Snell', dotColor: '#0f766e' },
]

export const NODE_TLS_MODE_OPTIONS: Array<NodeOption<number>> = [
  { value: 0, label: '无' },
  { value: 1, label: 'TLS' },
  { value: 2, label: 'Reality' },
]

export const NODE_SIMPLE_TLS_OPTIONS: Array<NodeOption<number>> = [
  { value: 0, label: '无' },
  { value: 1, label: 'TLS' },
]

export const NODE_TRANSPORT_OPTIONS: Record<string, Array<NodeOption>> = {
  vmess: [
    { value: 'tcp', label: 'TCP' },
    { value: 'ws', label: 'WebSocket' },
    { value: 'grpc', label: 'gRPC' },
    { value: 'h2', label: 'HTTP/2' },
    { value: 'httpupgrade', label: 'HTTPUpgrade' },
    { value: 'xhttp', label: 'XHTTP' },
  ],
  vless: [
    { value: 'tcp', label: 'TCP' },
    { value: 'ws', label: 'WebSocket' },
    { value: 'grpc', label: 'gRPC' },
    { value: 'h2', label: 'HTTP/2' },
    { value: 'httpupgrade', label: 'HTTPUpgrade' },
    { value: 'xhttp', label: 'XHTTP' },
    { value: 'kcp', label: 'mKCP' },
    { value: 'quic', label: 'QUIC' },
  ],
  trojan: [
    { value: 'tcp', label: 'TCP' },
    { value: 'ws', label: 'WebSocket' },
    { value: 'grpc', label: 'gRPC' },
    { value: 'h2', label: 'HTTP/2' },
    { value: 'httpupgrade', label: 'HTTPUpgrade' },
    { value: 'xhttp', label: 'XHTTP' },
  ],
}

export const NODE_TCP_HEADER_OPTIONS: Array<NodeOption> = [
  { value: 'none', label: '无头部' },
  { value: 'http', label: 'HTTP 伪装' },
]

export const NODE_KCP_HEADER_OPTIONS: Array<NodeOption> = [
  { value: 'none', label: '无头部' },
  { value: 'srtp', label: 'SRTP' },
  { value: 'utp', label: 'uTP' },
  { value: 'wechat-video', label: '微信视频' },
  { value: 'dtls', label: 'DTLS' },
  { value: 'wireguard', label: 'WireGuard' },
]

export const NODE_XHTTP_MODE_OPTIONS: Array<NodeOption> = [
  { value: 'auto', label: 'auto（自动）' },
  { value: 'packet-up', label: 'packet-up' },
  { value: 'stream-up', label: 'stream-up' },
  { value: 'stream-one', label: 'stream-one' },
]

export const NODE_SHADOWSOCKS_PLUGIN_OPTIONS: Array<NodeOption> = [
  { value: '', label: '无' },
  { value: 'obfs-local', label: 'obfs-local' },
  { value: 'v2ray-plugin', label: 'v2ray-plugin' },
]

export const NODE_TLS_FINGERPRINT_OPTIONS: Array<NodeOption> = [
  { value: 'chrome', label: 'Chrome' },
  { value: 'firefox', label: 'Firefox' },
  { value: 'safari', label: 'Safari' },
  { value: 'ios', label: 'iOS' },
  { value: 'edge', label: 'Edge' },
  { value: 'qq', label: 'QQ' },
  { value: 'random', label: '随机' },
]

export const NODE_SHADOWSOCKS_CIPHER_OPTIONS: Array<NodeOption> = [
  { value: 'aes-128-gcm', label: 'aes-128-gcm' },
  { value: 'aes-256-gcm', label: 'aes-256-gcm' },
  { value: 'chacha20-ietf-poly1305', label: 'chacha20-ietf-poly1305' },
  { value: '2022-blake3-aes-128-gcm', label: '2022-blake3-aes-128-gcm' },
  { value: '2022-blake3-aes-256-gcm', label: '2022-blake3-aes-256-gcm' },
  { value: '2022-blake3-chacha20-poly1305', label: '2022-blake3-chacha20-poly1305' },
]

export const NODE_SHADOWSOCKS_OBFS_OPTIONS: Array<NodeOption> = [
  { value: '', label: '无' },
  { value: 'http', label: 'HTTP' },
  { value: 'tls', label: 'TLS' },
]

export const NODE_VLESS_FLOW_OPTIONS: Array<NodeOption> = [
  { value: '', label: '无' },
  { value: 'xtls-rprx-vision', label: 'xtls-rprx-vision' },
  { value: 'xtls-rprx-vision-udp443', label: 'xtls-rprx-vision-udp443' },
]

export const NODE_CONGESTION_CONTROL_OPTIONS: Array<NodeOption> = [
  { value: 'cubic', label: 'cubic' },
  { value: 'bbr', label: 'bbr' },
  { value: 'new_reno', label: 'new_reno' },
]

export const NODE_UDP_RELAY_MODE_OPTIONS: Array<NodeOption> = [
  { value: 'native', label: 'native' },
  { value: 'quic', label: 'quic' },
]

export const HYSTERIA_DEFAULTS = {
  version: 2,
  upMbps: 0,
  downMbps: 0,
  hopInterval: 30,
  obfsType: 'salamander',
} as const

function randomHex(byteLength: number): string {
  const bytes = crypto.getRandomValues(new Uint8Array(byteLength))
  return Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('')
}

export function generateRandomHysteriaObfsPassword(): string {
  return randomHex(16)
}

export function generateRandomRealityShortId(): string {
  return randomHex(8)
}

export const NODE_TUIC_VERSION_OPTIONS: Array<NodeOption<number>> = [
  { value: 5, label: 'V5' },
  { value: 4, label: 'V4' },
]

export const NODE_ALPN_OPTIONS: Array<NodeOption> = [
  { value: 'h3', label: 'HTTP/3' },
  { value: 'h2', label: 'HTTP/2' },
  { value: 'http/1.1', label: 'HTTP/1.1' },
]

export const NODE_MUX_PROTOCOL_OPTIONS: Array<NodeOption> = [
  { value: 'yamux', label: 'yamux' },
  { value: 'smux', label: 'smux' },
  { value: 'h2mux', label: 'h2mux' },
]

function createRateRange(index = 0): NodeRateRangeForm {
  return {
    key: `range-${Date.now()}-${index}`,
    start: '',
    end: '',
    rate: 1,
  }
}

export function createEmptyNodeForm(): NodeFormModel {
  return {
    id: undefined,
    originalType: '',
    type: '',
    rawProtocolSettings: {},
    name: '',
    code: '',
    rate: 1,
    trafficLimitEnabled: false,
    trafficLimitGb: null,
    trafficLimitResetDay: 1,
    trafficLimitResetTime: '00:00',
    trafficLimitTimezone: 'Asia/Shanghai',
    rateTimeEnable: false,
    rateTimeRanges: [createRateRange()],
    tags: [],
    groupIds: [],
    planRestrictionEnabled: false,
    allowedPlanIds: [],
    subscriptionLabelId: undefined,
    routeIds: [],
    machineId: null,
    host: '',
    port: '',
    serverPort: '',
    parentId: null,
    tcpCheckEnabled: false,
    bandwidthCheckEnabled: false,
    show: true,
    autoOnline: false,
    autoOnlineCooldownEnabled: false,
    gfwCheckEnabled: true,
    enabled: true,
    tlsMode: 0,
    tlsServerName: '',
    tlsAllowInsecure: false,
    echEnabled: false,
    echConfig: '',
    echQueryServerName: '',
    echKey: '',
    utlsEnabled: false,
    utlsFingerprint: 'chrome',
    realityServerName: '',
    realityServerPort: '443',
    realityPublicKey: '',
    realityPrivateKey: '',
    realityShortId: generateRandomRealityShortId(),
    network: '',
    tcpHeaderType: 'none',
    tcpRequestPath: '/',
    tcpRequestHost: '',
    wsPath: '/',
    wsHost: '',
    grpcServiceName: 'grpc',
    h2Path: '/',
    h2Host: '',
    httpupgradePath: '/',
    httpupgradeHost: '',
    xhttpPath: '/',
    xhttpHost: '',
    xhttpMode: 'auto',
    xhttpExtra: '',
    kcpSeed: generateRandomHysteriaObfsPassword(),
    kcpHeaderType: 'none',
    shadowsocksCipher: '2022-blake3-aes-128-gcm',
    shadowsocksObfs: '',
    shadowsocksObfsHost: '',
    shadowsocksObfsPath: '/',
    shadowsocksPlugin: '',
    shadowsocksPluginOpts: '',
    vlessFlow: '',
    vlessEncryptionEnabled: false,
    vlessEncryption: '',
    vlessDecryption: '',
    hysteriaVersion: HYSTERIA_DEFAULTS.version,
    hysteriaUpMbps: HYSTERIA_DEFAULTS.upMbps,
    hysteriaDownMbps: HYSTERIA_DEFAULTS.downMbps,
    hysteriaObfsEnabled: true,
    hysteriaObfsType: HYSTERIA_DEFAULTS.obfsType,
    hysteriaObfsPassword: generateRandomHysteriaObfsPassword(),
    hysteriaHopInterval: HYSTERIA_DEFAULTS.hopInterval,
    tuicVersion: 5,
    tuicCongestionControl: 'cubic',
    tuicAlpn: ['h3'],
    tuicUdpRelayMode: 'native',
    mieruTransport: 'TCP',
    mieruTrafficPattern: '',
    anytlsPaddingSchemeText: [
      'stop=8',
      '0=30-30',
      '1=100-400',
      '2=400-500,c,500-1000,c,500-1000,c,500-1000,c,500-1000',
      '3=9-9,500-1000',
      '4=500-1000',
      '5=500-1000',
      '6=500-1000',
      '7=500-1000',
    ].join('\n'),
    snellObfs: 'off',
    snellObfsHost: '',
    multiplexEnabled: false,
    multiplexProtocol: 'yamux',
    multiplexMaxConnections: 4,
    multiplexPadding: false,
    multiplexBrutalEnabled: false,
    multiplexBrutalUpMbps: 10,
    multiplexBrutalDownMbps: 10,
  }
}

export function applySnellNodeConstraints(form: NodeFormModel): void {
  form.rawProtocolSettings = {}
  form.machineId = null
  form.routeIds = []
  form.network = ''
  form.tlsMode = 0
  form.tlsServerName = ''
  form.tlsAllowInsecure = false
  form.echEnabled = false
  form.echConfig = ''
  form.echQueryServerName = ''
  form.echKey = ''
  form.utlsEnabled = false
  form.utlsFingerprint = 'chrome'
  form.realityServerName = ''
  form.realityServerPort = ''
  form.realityPublicKey = ''
  form.realityPrivateKey = ''
  form.realityShortId = ''
  form.multiplexEnabled = false
  form.multiplexBrutalEnabled = false
}

export function applyNodeProtocolDefaults(form: NodeFormModel): void {
  if (form.type === 'snell') {
    applySnellNodeConstraints(form)
    return
  }
  const transports = getNodeTransportOptions(form.type)
  if (!transports.some((option) => option.value === form.network) && (form.type !== form.originalType || !form.network)) {
    form.network = transports[0]?.value ?? ''
  }
  if (!supportsNodeSecurity(form.type)) {
    form.tlsMode = 0
  } else if (!getNodeTlsOptions(form.type).some((option) => option.value === form.tlsMode)) {
    form.tlsMode = 0
  }
  if ((form.type === 'trojan' || (form.type === 'naive' && !form.id)) && form.tlsMode === 0) {
    form.tlsMode = 1
  }
}

export function createNodeRateRange(): NodeRateRangeForm {
  return createRateRange()
}

export function getNodeProtocolLabel(type: AdminNodeType | '' | string): string {
  return NODE_PROTOCOL_OPTIONS.find((item) => item.value === type)?.label ?? String(type ?? '')
}

export function getNodeProtocolOptions(): Array<NodeOption<AdminNodeType>> {
  return NODE_PROTOCOL_OPTIONS
}

export function getNodeTlsOptions(type: AdminNodeType | '' | string): Array<NodeOption<number>> {
  return type === 'vless' || type === 'trojan'
    ? NODE_TLS_MODE_OPTIONS
    : NODE_SIMPLE_TLS_OPTIONS
}

export function getNodeTransportOptions(type: AdminNodeType | '' | string): Array<NodeOption> {
  return NODE_TRANSPORT_OPTIONS[type] ?? []
}

export function supportsNodeSecurity(type: AdminNodeType | '' | string): boolean {
  return ['vmess', 'vless', 'trojan', 'hysteria', 'tuic', 'anytls', 'socks', 'naive', 'http'].includes(type)
}

export function supportsNodeTransport(type: AdminNodeType | '' | string): boolean {
  return ['vmess', 'vless', 'trojan'].includes(type)
}

export function supportsNodeMultiplex(type: AdminNodeType | '' | string): boolean {
  return ['vmess', 'vless', 'trojan', 'mieru'].includes(type)
}

export function shouldShowTlsSettings(type: AdminNodeType | '' | string, tlsMode: number): boolean {
  if (['hysteria', 'tuic', 'anytls'].includes(type)) {
    return true
  }
  if (['vmess', 'socks', 'naive', 'http'].includes(type)) {
    return tlsMode === 1
  }
  if (['vless', 'trojan'].includes(type)) {
    return tlsMode === 1
  }
  return false
}

export function shouldShowRealitySettings(type: AdminNodeType | '' | string, tlsMode: number): boolean {
  return ['vless', 'trojan'].includes(type) && tlsMode === 2
}

export function getNodeProtocolHint(type: AdminNodeType | '' | string): string {
  const hints: Record<string, string> = {
    shadowsocks: '配置 cipher、混淆与 plugin，适合传统 SS 节点维护。',
    vmess: '配置 TLS 与传输层参数，适合 VMess 客户端场景。',
    trojan: '配置 TLS / Reality 与传输层，适合 Trojan 高兼容场景。',
    hysteria: '配置版本、带宽、混淆与 TLS 信息。',
    vless: '配置安全性、传输协议、Flow、Reality 与加密模式。',
    tuic: '配置版本、拥塞控制、ALPN 与 UDP relay。',
    socks: '配置基础 SOCKS 节点，支持可选 TLS。',
    naive: '配置 NaiveProxy 基础 TLS 信息。',
    http: '配置 HTTP 节点与可选 TLS。',
    mieru: '配置传输方式、流量模式与多路复用。',
    anytls: '配置 AnyTLS 的 TLS 信息与 Padding Scheme。',
    snell: 'Snell v4，仅 TCP。每位用户使用独立密钥，客户端需 Mihomo 1.19.26+。',
  }
  return hints[type] ?? '请选择协议后继续配置。'
}

export function validateNodeForm(form: NodeFormModel, plans: readonly Pick<AdminPlanListItem, 'id'>[] = []): string | null {
  if (form.subscriptionLabelId == null && form.planRestrictionEnabled) {
    if (form.allowedPlanIds.length === 0) return '启用套餐限制后，请至少选择一个套餐'
    const planIds = new Set(plans.map((plan) => plan.id))
    if (form.allowedPlanIds.some((id) => !Number.isInteger(id) || !planIds.has(id))) {
      return '所选套餐已不存在，请重新选择允许使用的套餐'
    }
  }
  if (!form.type) {
    return '请选择协议类型'
  }
  if (form.rateTimeEnable) {
    const validRanges = form.rateTimeRanges.filter((item) => item.start.trim() && item.end.trim() && Number(item.rate) > 0)
    if (validRanges.length === 0) {
      return '请至少填写一条有效的动态倍率规则'
    }
  }
  if (form.trafficLimitEnabled) {
    if (!Number.isFinite(Number(form.trafficLimitGb)) || Number(form.trafficLimitGb) <= 0) {
      return '请输入大于 0 的月流量额度'
    }
    if (!Number.isInteger(Number(form.trafficLimitResetDay)) || Number(form.trafficLimitResetDay) < 1 || Number(form.trafficLimitResetDay) > 31) {
      return '重置日期需为 1-31'
    }
    if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(form.trafficLimitResetTime)) {
      return '重置时间格式需为 HH:mm'
    }
  }
  if (form.type === 'snell') {
    if (form.machineId !== null) return 'Snell 请使用独立 Agent，按节点 ID 接入'
    if (form.routeIds.length > 0) return 'Snell 不支持路由组'
    if (!['off', 'http'].includes(form.snellObfs)) return '请选择 Snell 混淆方式'
    if (form.snellObfs === 'http' && (form.snellObfsHost.trim().length > 255 || /[\r\n]/.test(form.snellObfsHost))) {
      return '混淆 Host 最长为 255 个字符，且需保持单行'
    }
  }
  if (form.type === 'shadowsocks' && !form.shadowsocksCipher.trim()) {
    return '请选择 Shadowsocks 加密方式'
  }
  if (['vmess', 'trojan', 'vless'].includes(form.type) && !form.network.trim()) {
    return '请选择传输协议'
  }
  if (['vmess', 'socks', 'naive', 'http'].includes(form.type) && form.tlsMode === 1 && !form.tlsServerName.trim()) {
    return '启用 TLS 时请输入服务器名称（SNI）'
  }
  if (['vless', 'trojan'].includes(form.type) && form.tlsMode === 2) {
    if (!form.realityServerName.trim()) return 'Reality 模式下请输入服务器名称'
    if (!form.realityPublicKey.trim()) return 'Reality 模式下请输入公钥'
    if (!form.realityShortId.trim()) return 'Reality 模式下请输入 Short ID'
    if (!form.id && !form.realityPrivateKey.trim()) return '新建 Reality 节点时请输入与公钥配对的私钥'
    if (!/^(?:[0-9a-fA-F]{2}){1,8}$/.test(form.realityShortId.trim())) return 'Short ID 需为 2-16 位偶数长度的十六进制字符'
  }
  if (form.network === 'xhttp' && form.xhttpExtra.trim()) {
    try {
      JSON.parse(form.xhttpExtra)
    } catch {
      return 'XHTTP 额外参数必须是合法 JSON'
    }
  }
  if (form.type === 'vless' && form.vlessEncryptionEnabled && (!form.vlessEncryption.trim() || !form.vlessDecryption.trim())) {
    return '启用 VLess 自定义加密后，请填写配对的客户端公钥和服务端私钥'
  }
  if (supportsNodeMultiplex(form.type) && form.multiplexEnabled && form.multiplexBrutalEnabled) {
    if (!(Number(form.multiplexBrutalUpMbps) > 0) || !(Number(form.multiplexBrutalDownMbps) > 0)) {
      return '启用 Brutal 后，请填写大于 0 的上下行带宽'
    }
  }
  if (form.type === 'hysteria' && form.hysteriaObfsEnabled && !form.hysteriaObfsPassword.trim()) {
    return '启用 Hysteria 混淆时请输入混淆密码'
  }
  if (form.type === 'tuic' && form.tuicAlpn.length === 0) {
    return '请至少保留一个 TUIC ALPN'
  }
  return null
}
