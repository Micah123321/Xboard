import type {
  AdminNodeItem,
  AdminNodeRateTimeRange,
  AdminNodeSavePayload,
  AdminNodeType,
} from '@/types/api'
import { applySnellNodeConstraints, createEmptyNodeForm, HYSTERIA_DEFAULTS, type NodeFormModel } from './nodeEditorOptions'

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

function toRecord(value: unknown): Record<string, unknown> {
  return isRecord(value) ? value : {}
}

function mergeSettings(original: Record<string, unknown>, updates: Record<string, unknown>): Record<string, unknown> {
  const merged = { ...original }
  for (const [key, value] of Object.entries(updates)) {
    merged[key] = isRecord(value) ? mergeSettings(toRecord(original[key]), value) : value
  }
  return merged
}

function toStringValue(value: unknown): string {
  return typeof value === 'string' ? value : value === null || value === undefined ? '' : String(value)
}

function toNumberValue(value: unknown, fallback = 0): number {
  if (value == null || (typeof value === 'string' && !value.trim())) return fallback
  const normalized = Number(value)
  return Number.isFinite(normalized) ? normalized : fallback
}

function toNullableNumber(value: unknown): number | null {
  if (value == null || (typeof value === 'string' && !value.trim())) return null
  const normalized = Number(value)
  return Number.isFinite(normalized) ? normalized : null
}

function bytesToGigabytes(value: unknown): number | null {
  const normalized = Number(value)
  if (!Number.isFinite(normalized) || normalized <= 0) return null
  return Number((normalized / 1073741824).toFixed(2))
}

function gigabytesToBytes(value: unknown): number {
  const normalized = Number(value)
  if (!Number.isFinite(normalized) || normalized <= 0) return 0
  return Math.round(normalized * 1073741824)
}

function toBooleanValue(value: unknown, fallback = false): boolean {
  if (typeof value === 'boolean') return value
  if (typeof value === 'number') return value !== 0
  if (typeof value === 'string') {
    const normalized = value.trim().toLowerCase()
    if (['1', 'true', 'yes', 'on'].includes(normalized)) return true
    if (['0', 'false', 'no', 'off', ''].includes(normalized)) return false
  }
  return fallback
}

function toStringArray(value: unknown): string[] {
  if (!Array.isArray(value)) return []
  return value
    .map((item) => toStringValue(item).trim())
    .filter(Boolean)
}

function toNumberArray(value: unknown): number[] {
  if (!Array.isArray(value)) return []
  return [...new Set(
    value
      .map((item) => Number(item))
      .filter((item) => Number.isFinite(item)),
  )]
}

function toJsonIdArray(value: unknown[]): string[] {
  return [...new Set(
    value
      .map((item) => String(item ?? '').trim())
      .filter(Boolean),
  )]
}

function splitMultiline(value: string): string[] {
  return [...new Set(
    value
      .split(/\r?\n/)
      .map((item) => item.trim())
      .filter(Boolean),
  )]
}

function splitInlineList(value: string): string[] {
  return [...new Set(
    value
      .split(/[,\n]/)
      .map((item) => item.trim())
      .filter(Boolean),
  )]
}

function joinInlineList(value: unknown): string {
  return toStringArray(value).join(', ')
}

function joinMultilineList(value: unknown): string {
  return toStringArray(value).join('\n')
}

function cloneProtocolSettings(value: unknown): Record<string, unknown> {
  if (!isRecord(value)) return {}
  try {
    return JSON.parse(JSON.stringify(value)) as Record<string, unknown>
  } catch {
    return { ...value }
  }
}

export function sortNodesByOrder(nodes: AdminNodeItem[]): AdminNodeItem[] {
  return [...nodes].sort((left, right) => {
    const leftSort = Number(left.sort ?? Number.MAX_SAFE_INTEGER)
    const rightSort = Number(right.sort ?? Number.MAX_SAFE_INTEGER)
    if (leftSort !== rightSort) {
      return leftSort - rightSort
    }
    return left.id - right.id
  })
}

export function moveNodeOrder<T>(items: T[], fromIndex: number, direction: -1 | 1): T[] {
  const targetIndex = fromIndex + direction
  if (targetIndex < 0 || targetIndex >= items.length) {
    return items
  }
  const next = [...items]
  const [current] = next.splice(fromIndex, 1)
  next.splice(targetIndex, 0, current)
  return next
}

export function toNodeFormModel(node?: AdminNodeItem | null): NodeFormModel {
  const form = createEmptyNodeForm()
  if (!node) {
    return form
  }

  const protocolSettings = cloneProtocolSettings(node.protocol_settings)
  const tlsSettings = toRecord(protocolSettings.tls_settings)
  const tlsObject = toRecord(protocolSettings.tls)
  const realitySettings = toRecord(protocolSettings.reality_settings)
  const utlsSettings = toRecord(protocolSettings.utls)
  const multiplexSettings = toRecord(protocolSettings.multiplex)
  const multiplexBrutal = toRecord(multiplexSettings.brutal)
  const networkSettings = toRecord(protocolSettings.network_settings)
  const tcpHeader = toRecord(networkSettings.header)
  const tcpRequest = toRecord(tcpHeader.request)
  const tcpHeaders = toRecord(tcpRequest.headers)
  const wsHeaders = toRecord(networkSettings.headers)
  const echFromTls = toRecord(tlsSettings.ech)
  const echFromTlsObject = toRecord(tlsObject.ech)
  const hysteriaObfs = toRecord(protocolSettings.obfs)
  const hysteriaBandwidth = toRecord(protocolSettings.bandwidth)
  const encryption = toRecord(protocolSettings.encryption)

  form.id = node.id
  form.originalType = (node.type as AdminNodeType) ?? ''
  form.type = (node.type as AdminNodeType) ?? ''
  form.rawProtocolSettings = protocolSettings
  form.name = toStringValue(node.name)
  form.code = toStringValue(node.code)
  form.rate = toNumberValue(node.rate, 1)
  form.trafficLimitEnabled = toBooleanValue(node.traffic_limit_enabled)
  form.trafficLimitGb = bytesToGigabytes(node.transfer_enable)
  form.trafficLimitResetDay = toNullableNumber(node.traffic_limit_reset_day) ?? 1
  form.trafficLimitResetTime = toStringValue(node.traffic_limit_reset_time || '00:00')
  form.trafficLimitTimezone = toStringValue(node.traffic_limit_timezone || 'Asia/Shanghai')
  form.rateTimeEnable = toBooleanValue(node.rate_time_enable)
  form.rateTimeRanges = Array.isArray(node.rate_time_ranges) && node.rate_time_ranges.length > 0
    ? node.rate_time_ranges.map((item, index) => ({
      key: `range-${node.id}-${index}`,
      start: toStringValue(item.start),
      end: toStringValue(item.end),
      rate: toNumberValue(item.rate, 1),
    }))
    : createEmptyNodeForm().rateTimeRanges
  form.tags = toStringArray(node.tags)
  form.groupIds = toNumberArray(node.group_ids)
  if (form.groupIds.length === 0 && Array.isArray(node.groups)) {
    form.groupIds = toNumberArray(node.groups.map((group) => group.id))
  }
  form.planRestrictionEnabled = toBooleanValue(node.plan_restriction_enabled)
  form.allowedPlanIds = toNumberArray(node.allowed_plan_ids)
  form.subscriptionLabelId = node.subscription_label_id === undefined
    ? node.subscription_label?.id
    : toNullableNumber(node.subscription_label_id)
  form.routeIds = toNumberArray(node.route_ids)
  form.machineId = node.machine_id ?? null
  form.host = toStringValue(node.host)
  form.port = toStringValue(node.port)
  form.serverPort = toStringValue(node.server_port)
  form.parentId = node.parent_id ?? null
  form.tcpCheckEnabled = toBooleanValue(node.tcp_check_enabled)
  form.bandwidthCheckEnabled = toBooleanValue(node.bandwidth_check_enabled)
  form.show = toBooleanValue(node.show, true)
  form.autoOnline = toBooleanValue(node.auto_online)
  form.autoOnlineCooldownEnabled = toBooleanValue(node.auto_online_cooldown_enabled)
  form.gfwCheckEnabled = toBooleanValue(node.gfw_check_enabled, true)
  form.enabled = toBooleanValue(node.enabled, true)
  if (form.type === 'snell') {
    form.snellObfs = protocolSettings.obfs === 'http' ? 'http' : 'off'
    form.snellObfsHost = form.snellObfs === 'http' ? toStringValue(protocolSettings.obfs_host) : ''
    applySnellNodeConstraints(form)
    return form
  }

  form.tlsMode = isRecord(protocolSettings.tls) ? 0 : toNumberValue(protocolSettings.tls)
  form.tlsServerName = toStringValue(tlsSettings.server_name || tlsObject.server_name)
  form.tlsAllowInsecure = toBooleanValue(tlsSettings.allow_insecure ?? tlsObject.allow_insecure)
  form.echEnabled = toBooleanValue(echFromTls.enabled ?? echFromTlsObject.enabled)
  form.echConfig = toStringValue(echFromTls.config ?? echFromTlsObject.config)
  form.echQueryServerName = toStringValue(echFromTls.query_server_name ?? echFromTlsObject.query_server_name)
  form.echKey = toStringValue(echFromTls.key ?? echFromTlsObject.key)
  form.utlsEnabled = toBooleanValue(utlsSettings.enabled)
  form.utlsFingerprint = toStringValue(utlsSettings.fingerprint || 'chrome')
  form.realityServerName = toStringValue(realitySettings.server_name)
  form.realityServerPort = toStringValue(realitySettings.server_port ?? form.realityServerPort)
  form.realityPublicKey = toStringValue(realitySettings.public_key)
  form.realityPrivateKey = toStringValue(realitySettings.private_key)
  form.realityShortId = toStringValue(realitySettings.short_id)
  form.network = toStringValue(protocolSettings.network)
  switch (form.network) {
    case 'tcp':
      form.tcpHeaderType = toStringValue(tcpHeader.type || form.tcpHeaderType)
      form.tcpRequestPath = tcpRequest.path == null ? form.tcpRequestPath : joinMultilineList(tcpRequest.path)
      form.tcpRequestHost = joinInlineList(tcpHeaders.Host)
      break
    case 'ws':
      form.wsPath = toStringValue(networkSettings.path ?? form.wsPath)
      form.wsHost = toStringValue(wsHeaders.Host)
      break
    case 'grpc':
      form.grpcServiceName = toStringValue(networkSettings.serviceName ?? form.grpcServiceName)
      break
    case 'h2':
      form.h2Path = toStringValue(networkSettings.path ?? form.h2Path)
      form.h2Host = joinInlineList(networkSettings.host)
      break
    case 'httpupgrade':
      form.httpupgradePath = toStringValue(networkSettings.path ?? form.httpupgradePath)
      form.httpupgradeHost = toStringValue(networkSettings.host)
      break
    case 'xhttp':
      form.xhttpPath = toStringValue(networkSettings.path ?? form.xhttpPath)
      form.xhttpHost = toStringValue(networkSettings.host)
      form.xhttpMode = toStringValue(networkSettings.mode || form.xhttpMode)
      form.xhttpExtra = networkSettings.extra ? JSON.stringify(networkSettings.extra, null, 2) : ''
      break
    case 'kcp':
      form.kcpSeed = toStringValue(networkSettings.seed)
      form.kcpHeaderType = toStringValue(toRecord(networkSettings.header).type || form.kcpHeaderType)
      break
  }
  if (form.type === 'shadowsocks') {
    form.shadowsocksCipher = toStringValue(protocolSettings.cipher || form.shadowsocksCipher)
    form.shadowsocksObfs = toStringValue(protocolSettings.obfs)
    form.shadowsocksObfsHost = toStringValue(toRecord(protocolSettings.obfs_settings).host)
    form.shadowsocksObfsPath = toStringValue(toRecord(protocolSettings.obfs_settings).path ?? form.shadowsocksObfsPath)
    form.shadowsocksPlugin = toStringValue(protocolSettings.plugin)
    form.shadowsocksPluginOpts = toStringValue(protocolSettings.plugin_opts)
  }
  if (form.type === 'vless') {
    form.vlessFlow = toStringValue(protocolSettings.flow)
    form.vlessEncryptionEnabled = toBooleanValue(encryption.enabled)
    form.vlessEncryption = toStringValue(encryption.encryption)
    form.vlessDecryption = toStringValue(encryption.decryption)
  }
  if (form.type === 'hysteria') {
    form.hysteriaVersion = protocolSettings.version == null
      ? HYSTERIA_DEFAULTS.version
      : toNumberValue(protocolSettings.version, HYSTERIA_DEFAULTS.version)
    form.hysteriaUpMbps = toNullableNumber(hysteriaBandwidth.up) ?? HYSTERIA_DEFAULTS.upMbps
    form.hysteriaDownMbps = toNullableNumber(hysteriaBandwidth.down) ?? HYSTERIA_DEFAULTS.downMbps
    form.hysteriaObfsEnabled = toBooleanValue(hysteriaObfs.open)
    form.hysteriaObfsType = toStringValue(hysteriaObfs.type || HYSTERIA_DEFAULTS.obfsType)
    form.hysteriaObfsPassword = toStringValue(hysteriaObfs.password)
    form.hysteriaHopInterval = protocolSettings.hop_interval == null
      ? HYSTERIA_DEFAULTS.hopInterval
      : toNullableNumber(protocolSettings.hop_interval) ?? HYSTERIA_DEFAULTS.hopInterval
  }
  if (form.type === 'tuic') {
    form.tuicVersion = toNullableNumber(protocolSettings.version) ?? form.tuicVersion
    form.tuicCongestionControl = toStringValue(protocolSettings.congestion_control || form.tuicCongestionControl)
    form.tuicAlpn = toStringArray(protocolSettings.alpn).length ? toStringArray(protocolSettings.alpn) : form.tuicAlpn
    form.tuicUdpRelayMode = toStringValue(protocolSettings.udp_relay_mode || form.tuicUdpRelayMode)
  }
  form.mieruTransport = toStringValue(protocolSettings.transport || 'TCP')
  form.mieruTrafficPattern = toStringValue(protocolSettings.traffic_pattern)
  form.anytlsPaddingSchemeText = joinMultilineList(protocolSettings.padding_scheme) || form.anytlsPaddingSchemeText
  form.multiplexEnabled = toBooleanValue(multiplexSettings.enabled)
  form.multiplexProtocol = toStringValue(multiplexSettings.protocol || 'yamux')
  form.multiplexMaxConnections = toNullableNumber(multiplexSettings.max_connections) ?? form.multiplexMaxConnections
  form.multiplexPadding = toBooleanValue(multiplexSettings.padding)
  form.multiplexBrutalEnabled = toBooleanValue(multiplexBrutal.enabled)
  form.multiplexBrutalUpMbps = toNullableNumber(multiplexBrutal.up_mbps) ?? form.multiplexBrutalUpMbps
  form.multiplexBrutalDownMbps = toNullableNumber(multiplexBrutal.down_mbps) ?? form.multiplexBrutalDownMbps

  return form
}

function buildTlsEchPayload(form: NodeFormModel): Record<string, unknown> | undefined {
  if (!form.echEnabled) return undefined
  return {
    enabled: true,
    config: form.echConfig.trim() || undefined,
    query_server_name: form.echQueryServerName.trim() || undefined,
    key: form.echKey.trim() || undefined,
  }
}

function buildTlsSettingsPayload(form: NodeFormModel): Record<string, unknown> {
  return {
    server_name: form.tlsServerName.trim() || undefined,
    allow_insecure: form.tlsAllowInsecure,
    ech: buildTlsEchPayload(form),
  }
}

function buildTlsObjectPayload(form: NodeFormModel): Record<string, unknown> {
  return {
    server_name: form.tlsServerName.trim() || undefined,
    allow_insecure: form.tlsAllowInsecure,
    ech: buildTlsEchPayload(form),
  }
}

function buildRealityPayload(form: NodeFormModel): Record<string, unknown> {
  return {
    server_name: form.realityServerName.trim() || undefined,
    server_port: form.realityServerPort.trim() ? Number(form.realityServerPort) : undefined,
    public_key: form.realityPublicKey.trim() || undefined,
    private_key: form.realityPrivateKey.trim() || undefined,
    short_id: form.realityShortId.trim() || undefined,
    allow_insecure: form.tlsAllowInsecure,
  }
}

function buildUtlsPayload(form: NodeFormModel): Record<string, unknown> {
  return {
    enabled: form.utlsEnabled,
    fingerprint: form.utlsEnabled ? form.utlsFingerprint : undefined,
  }
}

function buildMultiplexPayload(form: NodeFormModel): Record<string, unknown> {
  return {
    enabled: form.multiplexEnabled,
    protocol: form.multiplexProtocol,
    max_connections: form.multiplexEnabled ? form.multiplexMaxConnections ?? undefined : undefined,
    padding: form.multiplexPadding,
    brutal: {
      enabled: form.multiplexBrutalEnabled,
      up_mbps: form.multiplexBrutalEnabled ? form.multiplexBrutalUpMbps ?? undefined : undefined,
      down_mbps: form.multiplexBrutalEnabled ? form.multiplexBrutalDownMbps ?? undefined : undefined,
    },
  }
}

function buildNetworkSettingsPayload(form: NodeFormModel): Record<string, unknown> | undefined {
  switch (form.network) {
    case 'tcp': {
      const paths = splitMultiline(form.tcpRequestPath)
      return form.tcpHeaderType === 'http'
        ? {
          header: {
            type: 'http',
            request: {
              path: paths.length ? paths : ['/'],
              headers: { Host: splitInlineList(form.tcpRequestHost) },
            },
          },
        }
        : { header: { type: 'none' } }
    }
    case 'ws':
      return {
        path: form.wsPath.trim() || undefined,
        headers: form.wsHost.trim() ? { Host: form.wsHost.trim() } : undefined,
      }
    case 'grpc':
      return { serviceName: form.grpcServiceName.trim() || undefined }
    case 'h2':
      return {
        path: form.h2Path.trim() || undefined,
        host: splitInlineList(form.h2Host),
      }
    case 'httpupgrade':
      return {
        path: form.httpupgradePath.trim() || undefined,
        host: form.httpupgradeHost.trim() || undefined,
      }
    case 'xhttp':
      return {
        path: form.xhttpPath.trim() || undefined,
        host: form.xhttpHost.trim() || undefined,
        mode: form.xhttpMode.trim() || 'auto',
        extra: form.xhttpExtra.trim() ? JSON.parse(form.xhttpExtra) : undefined,
      }
    case 'kcp':
      return {
        seed: form.kcpSeed.trim() || undefined,
        header: { type: form.kcpHeaderType },
      }
    case 'quic':
      return { security: 'none' }
    default:
      return undefined
  }
}

function buildRateRanges(form: NodeFormModel): AdminNodeRateTimeRange[] {
  return form.rateTimeRanges
    .map((item) => ({
      start: item.start.trim(),
      end: item.end.trim(),
      rate: toNumberValue(item.rate, 1),
    }))
    .filter((item) => item.start && item.end && Number.isFinite(item.rate) && item.rate > 0)
}

function buildProtocolSettings(form: NodeFormModel): Record<string, unknown> {
  if (form.type === 'snell') {
    return {
      version: 4,
      obfs: form.snellObfs,
      obfs_host: form.snellObfs === 'http' ? form.snellObfsHost.trim() : '',
      udp: false,
    }
  }

  const preserved = form.type === form.originalType ? cloneProtocolSettings(form.rawProtocolSettings) : {}
  const networkUpdates = buildNetworkSettingsPayload(form)
  const networkSettings = networkUpdates
    ? mergeSettings(form.network === preserved.network ? toRecord(preserved.network_settings) : {}, networkUpdates)
    : undefined
  const tlsSettings = mergeSettings(toRecord(preserved.tls_settings), buildTlsSettingsPayload(form))
  const tlsObject = mergeSettings(toRecord(preserved.tls), buildTlsObjectPayload(form))
  const realitySettings = mergeSettings(toRecord(preserved.reality_settings), buildRealityPayload(form))
  const utls = mergeSettings(toRecord(preserved.utls), buildUtlsPayload(form))
  const multiplex = mergeSettings(toRecord(preserved.multiplex), buildMultiplexPayload(form))

  switch (form.type) {
    case 'shadowsocks':
      return {
        ...preserved,
        cipher: form.shadowsocksCipher,
        obfs: form.shadowsocksObfs || undefined,
        obfs_settings: form.shadowsocksObfs
          ? {
            host: form.shadowsocksObfsHost.trim() || undefined,
            path: form.shadowsocksObfsPath.trim() || undefined,
          }
          : undefined,
        plugin: form.shadowsocksPlugin.trim() || undefined,
        plugin_opts: form.shadowsocksPluginOpts.trim() || undefined,
      }
    case 'vmess':
      return {
        ...preserved,
        tls: form.tlsMode,
        network: form.network,
        network_settings: networkSettings,
        tls_settings: tlsSettings,
        rules: Array.isArray(preserved.rules) ? preserved.rules : [],
        utls,
        multiplex,
      }
    case 'trojan':
      return {
        ...preserved,
        tls: form.tlsMode,
        network: form.network,
        network_settings: networkSettings,
        server_name: form.tlsMode === 1 ? form.tlsServerName.trim() || undefined : undefined,
        allow_insecure: form.tlsMode === 1 ? form.tlsAllowInsecure : undefined,
        tls_settings: tlsSettings,
        reality_settings: form.tlsMode === 2 ? realitySettings : undefined,
        utls,
        multiplex,
      }
    case 'hysteria':
      return {
        ...preserved,
        version: form.hysteriaVersion,
        bandwidth: {
          up: form.hysteriaUpMbps ?? HYSTERIA_DEFAULTS.upMbps,
          down: form.hysteriaDownMbps ?? HYSTERIA_DEFAULTS.downMbps,
        },
        obfs: {
          open: form.hysteriaObfsEnabled,
          type: form.hysteriaObfsType.trim() || 'salamander',
          password: form.hysteriaObfsEnabled ? form.hysteriaObfsPassword.trim() || undefined : undefined,
        },
        tls: tlsObject,
        hop_interval: form.hysteriaHopInterval ?? HYSTERIA_DEFAULTS.hopInterval,
      }
    case 'vless':
      return {
        ...preserved,
        tls: form.tlsMode,
        tls_settings: tlsSettings,
        flow: form.vlessFlow.trim() || undefined,
        encryption: {
          enabled: form.vlessEncryptionEnabled,
          encryption: form.vlessEncryptionEnabled ? form.vlessEncryption.trim() || undefined : undefined,
          decryption: form.vlessEncryptionEnabled ? form.vlessDecryption.trim() || undefined : undefined,
        },
        network: form.network,
        network_settings: networkSettings,
        reality_settings: form.tlsMode === 2 ? realitySettings : undefined,
        utls,
        multiplex,
      }
    case 'socks':
    case 'naive':
    case 'http':
      return {
        ...preserved,
        tls: form.tlsMode,
        tls_settings: tlsSettings,
      }
    case 'tuic':
      return {
        ...preserved,
        version: form.tuicVersion ?? 5,
        congestion_control: form.tuicCongestionControl.trim() || 'cubic',
        alpn: form.tuicAlpn.length ? form.tuicAlpn : ['h3'],
        udp_relay_mode: form.tuicUdpRelayMode.trim() || 'native',
        tls: tlsObject,
      }
    case 'mieru':
      return {
        ...preserved,
        transport: form.mieruTransport,
        traffic_pattern: form.mieruTrafficPattern.trim(),
        multiplex,
      }
    case 'anytls':
      return {
        ...preserved,
        padding_scheme: splitMultiline(form.anytlsPaddingSchemeText),
        tls: tlsObject,
      }
    default:
      return preserved
  }
}

export function toNodeSavePayload(form: NodeFormModel): AdminNodeSavePayload {
  return {
    id: form.id,
    type: form.type as AdminNodeType,
    code: form.code.trim() || undefined,
    name: form.name.trim(),
    group_ids: toJsonIdArray(form.groupIds),
    plan_restriction_enabled: form.planRestrictionEnabled,
    allowed_plan_ids: toNumberArray(form.allowedPlanIds),
    ...(form.subscriptionLabelId !== undefined ? { subscription_label_id: form.subscriptionLabelId } : {}),
    route_ids: form.type === 'snell' ? [] : toJsonIdArray(form.routeIds),
    ...(form.type === 'snell' ? { machine_id: null } : {}),
    parent_id: form.parentId ?? undefined,
    enabled: form.enabled,
    host: form.host.trim(),
    port: form.port.trim(),
    server_port: form.serverPort.trim(),
    tags: [...new Set(form.tags.map((item) => item.trim()).filter(Boolean))],
    rate: Math.max(0.01, Number(form.rate) || 1),
    rate_time_enable: form.rateTimeEnable,
    rate_time_ranges: form.rateTimeEnable ? buildRateRanges(form) : [],
    protocol_settings: buildProtocolSettings(form),
    show: form.show ? 1 : 0,
    auto_online: form.autoOnline,
    auto_online_cooldown_enabled: form.autoOnline && form.autoOnlineCooldownEnabled,
    gfw_check_enabled: form.gfwCheckEnabled,
    tcp_check_enabled: Boolean(form.parentId) && form.tcpCheckEnabled,
    bandwidth_check_enabled: form.bandwidthCheckEnabled,
    transfer_enable: form.trafficLimitEnabled ? gigabytesToBytes(form.trafficLimitGb) : 0,
    traffic_limit_enabled: form.trafficLimitEnabled,
    traffic_limit_reset_day: form.trafficLimitEnabled ? form.trafficLimitResetDay : null,
    traffic_limit_reset_time: form.trafficLimitEnabled ? form.trafficLimitResetTime : null,
    traffic_limit_timezone: form.trafficLimitEnabled ? form.trafficLimitTimezone.trim() || undefined : undefined,
  }
}
