import type { AdminNodeItem } from '@/types/api'

export type NodeRelationFilter = 'all' | 'parent' | 'child'

export interface NodeStatusMeta {
  label: string
  dotClass: 'online' | 'pending' | 'offline' | 'disabled'
  tagType: 'success' | 'warning' | 'danger' | 'info'
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
  relationFilter: NodeRelationFilter = 'all',
): AdminNodeItem[] {
  const normalizedKeyword = normalizeText(keyword)
  const normalizedType = normalizeText(typeFilter)
  const normalizedGroup = normalizeText(groupFilter)
  const normalizedRelation = normalizeText(relationFilter)

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

    if (normalizedRelation === 'parent' && node.parent_id) {
      return false
    }

    if (normalizedRelation === 'child' && !node.parent_id) {
      return false
    }

    return true
  })
}

export function countOnlineNodes(nodes: AdminNodeItem[]): number {
  return nodes.filter((node) => getNodeStatusMeta(node).dotClass === 'online').length
}

export function countVisibleNodes(nodes: AdminNodeItem[]): number {
  return nodes.filter((node) => Boolean(node.show)).length
}
