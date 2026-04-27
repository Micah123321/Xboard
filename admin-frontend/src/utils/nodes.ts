import type { AdminNodeItem } from '@/types/api'

export type NodeRelationFilter = 'all' | 'parent' | 'child'
export type NodeGfwFilter = 'all' | 'normal' | 'blocked' | 'partial' | 'failed' | 'unchecked' | 'checking' | 'inherited'
export type NodeStatusFilter = 'all' | 'online' | 'offline'

export interface NodeStatusMeta {
  label: string
  dotClass: 'online' | 'pending' | 'offline' | 'disabled'
  tagType: 'success' | 'warning' | 'danger' | 'info'
}

type NodeStatusClass = NodeStatusMeta['dotClass']

export interface NodeGfwMeta {
  label: string
  searchText: string
  tagType: 'success' | 'warning' | 'danger' | 'info' | 'primary'
  tone: 'normal' | 'blocked' | 'partial' | 'failed' | 'unchecked' | 'checking'
  inherited: boolean
}

const NODE_TYPE_LABELS: Record<string, string> = {
  shadowsocks: 'Shadowsocks',
  trojan: 'Trojan',
  vmess: 'VMess',
  vless: 'VLess',
  hysteria: 'Hysteria',
  tuic: 'TUIC',
  anytls: 'AnyTLS',
  socks: 'SOCKS',
  http: 'HTTP',
  naive: 'Naive',
  mieru: 'Mieru',
}

function normalizeText(value: unknown): string {
  return String(value ?? '').trim().toLowerCase()
}

export function getNodeTypeLabel(type: string): string {
  const normalized = normalizeText(type)
  return NODE_TYPE_LABELS[normalized] ?? String(type || '未知协议').toUpperCase()
}

export function getNodeStatusMeta(node: AdminNodeItem): NodeStatusMeta {
  if (node.enabled === false) {
    return {
      label: '已停用',
      dotClass: 'disabled',
      tagType: 'info',
    }
  }

  if (node.available_status === 2) {
    return {
      label: '在线',
      dotClass: 'online',
      tagType: 'success',
    }
  }

  if (node.available_status === 1) {
    return {
      label: '待同步',
      dotClass: 'pending',
      tagType: 'warning',
    }
  }

  return {
    label: '离线',
    dotClass: 'offline',
    tagType: 'danger',
  }
}

export function getNodeGfwMeta(node: AdminNodeItem): NodeGfwMeta {
  const status = normalizeText(node.gfw_check?.status || 'unchecked')
  const inherited = Boolean(node.gfw_check?.inherited)
  const inheritedPrefix = inherited ? '随父节点 · ' : ''

  if (status === 'normal') {
    return {
      label: `${inheritedPrefix}正常`,
      searchText: `${inherited ? '随父节点 继承 ' : ''}正常 未被墙 墙正常 gfw normal`,
      tagType: 'success',
      tone: 'normal',
      inherited,
    }
  }

  if (status === 'blocked') {
    return {
      label: `${inheritedPrefix}疑似被墙`,
      searchText: `${inherited ? '随父节点 继承 ' : ''}被墙 疑似被墙 gfw blocked`,
      tagType: 'danger',
      tone: 'blocked',
      inherited,
    }
  }

  if (status === 'partial') {
    return {
      label: `${inheritedPrefix}部分异常`,
      searchText: `${inherited ? '随父节点 继承 ' : ''}异常 部分异常 gfw partial`,
      tagType: 'warning',
      tone: 'partial',
      inherited,
    }
  }

  if (status === 'failed') {
    return {
      label: `${inheritedPrefix}检测失败`,
      searchText: `${inherited ? '随父节点 继承 ' : ''}失败 检测失败 异常 gfw failed`,
      tagType: 'danger',
      tone: 'failed',
      inherited,
    }
  }

  if (status === 'pending' || status === 'checking') {
    return {
      label: `${inheritedPrefix}检测中`,
      searchText: `${inherited ? '随父节点 继承 ' : ''}检测中 等待检测 gfw checking pending`,
      tagType: 'primary',
      tone: 'checking',
      inherited,
    }
  }

  return {
    label: inherited ? '随父节点 · 未检测' : '未检测',
    searchText: `${inherited ? '随父节点 继承 ' : ''}未检测 unchecked`,
    tagType: 'info',
    tone: 'unchecked',
    inherited,
  }
}

export function getNodeGfwTooltip(node: AdminNodeItem): string {
  const meta = getNodeGfwMeta(node)
  const source = node.gfw_check?.source_node_id
  const checkedAt = node.gfw_check?.checked_at
    ? new Date(Number(node.gfw_check.checked_at) * 1000).toLocaleString()
    : ''
  const actionAt = node.gfw_auto_action_at
    ? new Date(Number(node.gfw_auto_action_at) * 1000).toLocaleString()
    : ''
  const sourceText = meta.inherited && source ? `，来源父节点 #${source}` : ''
  const timeText = checkedAt ? `，检测时间 ${checkedAt}` : ''
  const autoText = node.gfw_auto_hidden ? `，已自动隐藏${actionAt ? `（${actionAt}）` : ''}` : ''
  const errorText = node.gfw_check?.error_message ? `，错误：${node.gfw_check.error_message}` : ''

  return `${meta.label}${sourceText}${timeText}${autoText}${errorText}`
}

function isNodeOnlineStatus(status: NodeStatusClass): boolean {
  return status === 'online' || status === 'pending'
}

export function getNodeIdLabel(node: AdminNodeItem): string {
  return node.parent_id ? `${node.id} → ${node.parent_id}` : String(node.id)
}

export function getNodeAddress(node: AdminNodeItem): { primary: string; secondary: string } {
  const host = node.host || '--'
  const publicPort = node.server_port ?? node.port ?? '--'
  const innerPort = node.port ?? node.server_port ?? '--'

  return {
    primary: `${host}:${publicPort}`,
    secondary: `内部端口 ${innerPort}`,
  }
}

export function formatNodeRate(rate?: number | null): string {
  const normalized = Number.isFinite(Number(rate)) ? Number(rate) : 1
  return `${normalized.toFixed(2)} x`
}

export function getNodeGroupNames(node: AdminNodeItem): string[] {
  return (node.groups ?? [])
    .map((group) => group.name)
    .filter(Boolean)
}

export function buildNodeTypeOptions(nodes: AdminNodeItem[]): Array<{ label: string; value: string }> {
  const uniqueTypes = [...new Set(nodes.map((node) => normalizeText(node.type)).filter(Boolean))]
  return uniqueTypes.map((value) => ({
    value,
    label: getNodeTypeLabel(value),
  }))
}

function buildNodeSearchText(node: AdminNodeItem): string {
  return [
    node.id,
    node.parent_id,
    node.name,
    node.host,
    node.port,
    node.server_port,
    getNodeTypeLabel(node.type),
    node.auto_online ? '自动上线 自动托管 auto online' : '',
    node.gfw_check_enabled === false ? '关闭墙检测 关闭自动墙检 gfw disabled' : '自动墙检 墙检测托管 gfw enabled',
    node.gfw_auto_hidden ? '自动隐藏 墙检测隐藏 疑似被墙已隐藏 gfw auto hidden' : '',
    getNodeGfwMeta(node).searchText,
    ...getNodeGroupNames(node),
  ]
    .map((item) => String(item ?? '').trim())
    .filter(Boolean)
    .join(' ')
    .toLowerCase()
}

export function filterNodes(
  nodes: AdminNodeItem[],
  keyword: string,
  typeFilter: string,
  groupFilter: string,
  statusFilter: NodeStatusFilter = 'all',
  relationFilter: NodeRelationFilter = 'all',
  gfwFilter: NodeGfwFilter = 'all',
): AdminNodeItem[] {
  const normalizedKeyword = normalizeText(keyword)
  const normalizedType = normalizeText(typeFilter)
  const normalizedGroup = normalizeText(groupFilter)
  const normalizedStatus = normalizeText(statusFilter)
  const normalizedRelation = normalizeText(relationFilter)
  const normalizedGfw = normalizeText(gfwFilter)

  return nodes.filter((node) => {
    if (normalizedKeyword && !buildNodeSearchText(node).includes(normalizedKeyword)) {
      return false
    }

    if (normalizedType !== '' && normalizedType !== 'all' && normalizeText(node.type) !== normalizedType) {
      return false
    }

    if (normalizedGroup !== '' && normalizedGroup !== 'all') {
      const belongsToGroup = (node.groups ?? []).some((group) => String(group.id) === normalizedGroup)
      if (!belongsToGroup) {
        return false
      }
    }

    const nodeStatus = getNodeStatusMeta(node).dotClass
    if (normalizedStatus === 'online' && !isNodeOnlineStatus(nodeStatus)) {
      return false
    }

    if (normalizedStatus === 'offline' && nodeStatus !== 'offline') {
      return false
    }

    if (normalizedRelation === 'parent' && node.parent_id) {
      return false
    }

    if (normalizedRelation === 'child' && !node.parent_id) {
      return false
    }

    const gfwMeta = getNodeGfwMeta(node)
    if (normalizedGfw === 'inherited' && !gfwMeta.inherited) {
      return false
    }

    if (normalizedGfw !== '' && normalizedGfw !== 'all' && normalizedGfw !== 'inherited' && gfwMeta.tone !== normalizedGfw) {
      return false
    }

    return true
  })
}

export function countOnlineNodes(nodes: AdminNodeItem[]): number {
  return nodes.filter((node) => isNodeOnlineStatus(getNodeStatusMeta(node).dotClass)).length
}

export function countVisibleNodes(nodes: AdminNodeItem[]): number {
  return nodes.filter((node) => Boolean(node.show)).length
}

export function countAutoOnlineNodes(nodes: AdminNodeItem[]): number {
  return nodes.filter((node) => Boolean(node.auto_online)).length
}

export function countAutoGfwCheckNodes(nodes: AdminNodeItem[]): number {
  return nodes.filter((node) => node.gfw_check_enabled !== false).length
}
