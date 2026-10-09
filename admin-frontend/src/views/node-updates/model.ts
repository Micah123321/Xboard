import type { Artifact, TaskState, BatchState } from '@/types/nodeUpdates'
export const taskLabels: Record<TaskState, string> = { queued: '排队中', claimed: '已领取', downloading: '下载中', verifying_artifacts: '校验制品中', installing: '安装中', verifying: '健康验证中', succeeded: '更新成功', failed: '失败', canceled: '已取消', skipped: '已跳过（已是目标版本）', rolling_back: '回滚中', rolled_back: '已回滚', rollback_failed: '回滚失败', uncertain: '结果不确定（持续占用容量，需核验）' }
export const batchLabels: Record<BatchState, string> = { running: '进行中', paused: '已暂停', canceling: '取消中（等待在途事务结束）', completed: '已结束（不代表全部成功）', canceled: '已取消' }
const reasons: Record<string, string> = { supported: '支持自动更新', unsupported: '不支持自动更新', docker: 'Docker 不支持，请按镜像部署流程更新', openrc: 'OpenRC 仅支持手工更新', unsupported_os: '操作系统不支持', unsupported_arch: '架构不支持', shared_binary_layout: '共享二进制布局不支持', local_disabled: '本地已禁用', not_reported: '尚未报告能力', not_enrolled: '需手动升级一次并启用更新agent', already_current: '已是目标版本且散列一致', version_conflict: '同版本散列冲突', downgrade_forbidden: '禁止降级', binary_version_mismatch: '双二进制版本不一致', download_failed: '下载失败', checksum_mismatch: 'SHA256 不匹配', size_mismatch: '文件大小不匹配', arch_mismatch: '架构不匹配', backup_failed: '备份失败', lease_expired: '租约过期', policy_disabled: '策略已关闭', batch_paused: '批次已暂停', batch_canceled: '批次已取消', release_revoked: '发行已撤销', restart_failed: '重启失败', health_failed: '健康检查失败', rollback_legacy_health: '已回滚，旧版本采用降级健康检查', rollback_failed: '回滚失败', manual_resolution: '已人工核验处理', revision_conflict: '配置已被修改，已刷新，请重新核对后保存', policy_changed: '安装策略已变化', capacity_exhausted: '并发容量已满', scope_conflict: '绑定范围与现有安装冲突', task_terminal: '任务已结束', invalid_credentials: '登录凭据失效', scope_mismatch: '范围不匹配', not_found: '资源不存在', validation_failed: '参数校验失败', idempotency_conflict: '幂等请求冲突', task_claimed: '任务已领取', expected_seq: '事件序号不连续' }
export function reasonLabel(code: string | null) { return code ? (reasons[code] || '服务端原因：' + code) : '—' }
export function positiveIds(value: string): number[] {
 const parts = value.trim().split(/[\s,，]+/)
 if (parts.some(v => !/^[1-9]\d*$/.test(v) || !Number.isSafeInteger(Number(v)))) throw new Error('请输入正整数 ID，以逗号或空格分隔')
 const ids = parts.map(Number)
 if (new Set(ids).size !== ids.length) throw new Error('节点 ID 不得重复')
 return ids
}
export function validateRelease(version: string, artifacts: Artifact[]) {
 if (!/^v(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/.test(version)) throw new Error('版本必须为固定 vMAJOR.MINOR.PATCH，不接受 latest 或预发布')
 if (!artifacts.length) throw new Error('至少选择一个架构')
 for (const artifact of artifacts) {
  const url = new URL(artifact.https_url)
  if (url.protocol !== 'https:' || url.username || url.password || url.hash || artifact.https_url.length > 2048) throw new Error('制品 URL 必须为 HTTPS，且不含用户信息或片段')
  if (!/^[a-f0-9]{64}$/.test(artifact.sha256)) throw new Error('SHA256 必须为可信构建提供的 64 位小写十六进制')
  if (!Number.isSafeInteger(artifact.size_bytes) || artifact.size_bytes < 1 || artifact.size_bytes > 512 * 1024 * 1024) throw new Error('文件大小必须为 1–512MiB 的整数字节数')
 }
 for (const arch of new Set(artifacts.map(a => a.arch))) {
  const pair = artifacts.filter(a => a.arch === arch)
  if (pair.length !== 2 || new Set(pair.map(a => a.component)).size !== 2) throw new Error('每个架构必须恰好包含 mi-node 和 xbctl')
 }
}
