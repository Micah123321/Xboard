import type { AdminServerGroupItem } from '@/types/api'

function normalizeText(value: unknown): string {
  return String(value ?? '').trim().toLowerCase()
}

function toSafeCount(value: unknown): number {
  const count = Number(value)
  return Number.isFinite(count) ? count : 0
}

export function normalizeNodeGroup(group: AdminServerGroupItem): AdminServerGroupItem {
  return {
    ...group,
    users_count: toSafeCount(group.users_count),
    server_count: toSafeCount(group.server_count),
  }
}

export function filterNodeGroups(groups: AdminServerGroupItem[], keyword: string): AdminServerGroupItem[] {
  const normalizedKeyword = normalizeText(keyword)
  if (!normalizedKeyword) {
    return groups
  }

  return groups.filter((group) => {
    const searchText = [group.id, group.name, group.users_count, group.server_count]
      .map((item) => String(item ?? '').trim().toLowerCase())
      .filter(Boolean)
      .join(' ')

    return searchText.includes(normalizedKeyword)
  })
}

export function summarizeNodeGroups(groups: AdminServerGroupItem[]): {
  totalUsers: number
  totalServers: number
} {
  return groups.reduce(
    (summary, group) => ({
      totalUsers: summary.totalUsers + toSafeCount(group.users_count),
      totalServers: summary.totalServers + toSafeCount(group.server_count),
    }),
    {
      totalUsers: 0,
      totalServers: 0,
    },
  )
}
