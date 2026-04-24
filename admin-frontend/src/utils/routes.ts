import type {
  AdminNodeItem,
  AdminNodeRouteAction,
  AdminNodeRouteItem,
  AdminNodeRouteSavePayload,
} from '@/types/api'

export interface NodeRouteActionMeta {
  label: string
  tagType: 'danger' | 'success' | 'warning' | 'info'
}

export interface NodeRouteReferenceSummary {
  count: number
  names: string[]
  preview: string
}

export interface NodeRouteFormModel {
  id?: number
  remarks: string
  matchText: string
  action: AdminNodeRouteAction
  actionValue: string
}

export const NODE_ROUTE_ACTION_OPTIONS: Array<{
  label: string
  value: AdminNodeRouteAction
}> = [
  { label: '禁止访问', value: 'block' },
  { label: '指定DNS服务器进行解析', value: 'dns' },
  { label: '直连', value: 'direct' },
  { label: '转发', value: 'proxy' },
]

const ROUTE_ACTION_META: Record<AdminNodeRouteAction, NodeRouteActionMeta> = {
  block: { label: '禁止访问', tagType: 'danger' },
  dns: { label: '指定DNS服务器进行解析', tagType: 'info' },
  direct: { label: '直连', tagType: 'success' },
  proxy: { label: '转发', tagType: 'warning' },
}

function normalizeText(value: unknown): string {
  return typeof value === 'string' ? value.trim() : ''
}

function normalizeMatchList(value: unknown): string[] {
  if (!Array.isArray(value)) {
    return []
  }

  return [...new Set(value
    .map((item) => normalizeText(item))
    .filter(Boolean))]
}

/**
 * 将多行文本转换为路由匹配规则数组。
 *
 * @param value 多行输入文本。
 * @returns 去重并去空后的规则数组。
 */
export function parseRouteMatchLines(value: string): string[] {
  return [...new Set(value
    .split(/\r?\n/g)
    .map((line) => line.trim())
    .filter(Boolean))]
}

/**
 * 归一化后端返回的路由实体，确保表格与表单层只消费稳定结构。
 *
 * @param route 后端返回的原始路由对象。
 * @returns 归一化后的路由实体。
 */
export function normalizeNodeRoute(route: AdminNodeRouteItem): AdminNodeRouteItem {
  return {
    ...route,
    remarks: normalizeText(route.remarks),
    match: normalizeMatchList(route.match),
    action_value: normalizeText(route.action_value),
  }
}

/**
 * 创建默认的路由表单模型。
 *
 * @returns 空表单模型。
 */
export function createEmptyNodeRouteForm(): NodeRouteFormModel {
  return {
    remarks: '',
    matchText: '',
    action: 'block',
    actionValue: '',
  }
}

/**
 * 将路由实体转换为编辑表单模型。
 *
 * @param route 当前编辑的路由；为空时返回默认模型。
 * @returns 可直接绑定到表单的模型。
 */
export function toNodeRouteFormModel(route?: AdminNodeRouteItem | null): NodeRouteFormModel {
  if (!route) {
    return createEmptyNodeRouteForm()
  }

  const normalized = normalizeNodeRoute(route)
  return {
    id: normalized.id,
    remarks: normalized.remarks,
    matchText: normalized.match.join('\n'),
    action: normalized.action,
    actionValue: normalizeText(normalized.action_value),
  }
}

/**
 * 将表单模型序列化为保存接口需要的载荷。
 *
 * @param form 当前表单模型。
 * @returns 可直接提交给后端的保存载荷。
 */
export function toNodeRouteSavePayload(form: NodeRouteFormModel): AdminNodeRouteSavePayload {
  return {
    id: form.id,
    remarks: form.remarks.trim(),
    match: parseRouteMatchLines(form.matchText),
    action: form.action,
    action_value: requiresNodeRouteActionValue(form.action)
      ? form.actionValue.trim()
      : null,
  }
}

/**
 * 判断某个动作是否要求额外填写动作值。
 *
 * @param action 路由动作。
 * @returns 当前动作是否需要动作值。
 */
export function requiresNodeRouteActionValue(action: AdminNodeRouteAction): boolean {
  return action === 'dns' || action === 'proxy'
}

/**
 * 获取动作标签与颜色元信息。
 *
 * @param action 路由动作。
 * @returns 动作标签展示元信息。
 */
export function getNodeRouteActionMeta(action: AdminNodeRouteAction): NodeRouteActionMeta {
  return ROUTE_ACTION_META[action] ?? ROUTE_ACTION_META.block
}

/**
 * 格式化列表中的动作值摘要。
 *
 * @param route 路由实体。
 * @returns 面向列表展示的动作值文本。
 */
export function formatNodeRouteActionValue(route: AdminNodeRouteItem): string {
  const actionValue = normalizeText(route.action_value)
  switch (route.action) {
    case 'dns':
      return actionValue ? `DNS: ${actionValue}` : 'DNS 服务器未配置'
    case 'direct':
      return '直接连接'
    case 'proxy':
      return actionValue ? `转发: ${actionValue}` : '转发目标未配置'
    case 'block':
    default:
      return '阻止访问'
  }
}

/**
 * 获取动作值输入框标签。
 *
 * @param action 路由动作。
 * @returns 动作值输入标签。
 */
export function getNodeRouteActionValueLabel(action: AdminNodeRouteAction): string {
  return action === 'dns' ? 'DNS服务器' : '转发目标'
}

/**
 * 获取动作值输入框占位文案。
 *
 * @param action 路由动作。
 * @returns 输入占位文案。
 */
export function getNodeRouteActionValuePlaceholder(action: AdminNodeRouteAction): string {
  return action === 'dns'
    ? '例如 8.8.8.8 或 https://dns.google/dns-query'
    : '例如 auto、proxy 或 香港出口'
}

/**
 * 为所有路由生成节点引用摘要。
 *
 * @param nodes 当前节点列表。
 * @returns 以路由 ID 为 key 的引用摘要映射。
 */
export function buildNodeRouteReferenceMap(nodes: AdminNodeItem[]): Record<number, NodeRouteReferenceSummary> {
  const map: Record<number, NodeRouteReferenceSummary> = {}

  nodes.forEach((node) => {
    ;(node.route_ids ?? []).forEach((routeId) => {
      const normalizedId = Number(routeId)
      if (!Number.isFinite(normalizedId)) {
        return
      }

      if (!map[normalizedId]) {
        map[normalizedId] = {
          count: 0,
          names: [],
          preview: '未被节点引用',
        }
      }

      map[normalizedId].count += 1
      if (node.name && !map[normalizedId].names.includes(node.name)) {
        map[normalizedId].names.push(node.name)
      }
    })
  })

  Object.values(map).forEach((summary) => {
    if (summary.count === 0) {
      summary.preview = '未被节点引用'
      return
    }

    const previewNames = summary.names.slice(0, 2)
    const more = summary.count - previewNames.length
    summary.preview = more > 0
      ? `${previewNames.join('、')} +${more}`
      : previewNames.join('、')
  })

  return map
}

function buildRouteSearchText(
  route: AdminNodeRouteItem,
  reference?: NodeRouteReferenceSummary,
): string {
  return [
    route.id,
    route.remarks,
    route.match.join(' '),
    formatNodeRouteActionValue(route),
    getNodeRouteActionMeta(route.action).label,
    reference?.names.join(' '),
  ]
    .map((item) => String(item ?? '').trim())
    .filter(Boolean)
    .join(' ')
    .toLowerCase()
}

/**
 * 按关键字过滤路由列表。
 *
 * @param routes 当前路由列表。
 * @param keyword 搜索关键字。
 * @param references 节点引用摘要映射。
 * @returns 过滤后的路由列表。
 */
export function filterNodeRoutes(
  routes: AdminNodeRouteItem[],
  keyword: string,
  references: Record<number, NodeRouteReferenceSummary>,
): AdminNodeRouteItem[] {
  const normalizedKeyword = normalizeText(keyword).toLowerCase()
  if (!normalizedKeyword) {
    return routes
  }

  return routes.filter((route) => buildRouteSearchText(route, references[route.id]).includes(normalizedKeyword))
}

/**
 * 统计当前已被节点引用的路由数量。
 *
 * @param routes 路由列表。
 * @param references 节点引用摘要映射。
 * @returns 被引用路由数量。
 */
export function countReferencedNodeRoutes(
  routes: AdminNodeRouteItem[],
  references: Record<number, NodeRouteReferenceSummary>,
): number {
  return routes.filter((route) => (references[route.id]?.count ?? 0) > 0).length
}
