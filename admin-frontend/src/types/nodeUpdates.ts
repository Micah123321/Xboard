export type Scope = { kind: 'machine'; machine_id: number } | { kind: 'legacy'; node_ids: number[] }
export interface Page<T> { items: T[]; page: number; page_size: number; total: number }
export interface Settings { enabled: boolean; max_concurrency: number; revision: number }
export interface Policy { enabled: boolean; target_release_id: string | null; revision: number }
export interface Artifact { arch: 'amd64' | 'arm64'; component: 'mi-node' | 'xbctl'; https_url: string; sha256: string; size_bytes: number }
export interface ReleaseInput { version: string; os: 'linux'; min_agent_protocol: number; artifacts: Artifact[] }
export interface Release extends ReleaseInput { id: string; published_at: string; revoked_at: string | null }
export interface Installation {
 id: string; scope: Scope; versions: { mi_node: string | null; xbctl: string | null };
 installed_sha256: { mi_node: string | null; xbctl: string | null };
 runtime: { os: string | null; arch: string | null; init: string | null; container: boolean | null };
 capability: { status: 'supported' | 'unsupported'; reason: string | null };
 last_seen_at: string | null; online: boolean; policy: Policy; active_task_id: string | null; revoked_at: string | null
}
export type TaskState = 'queued' | 'claimed' | 'downloading' | 'verifying_artifacts' | 'installing' | 'verifying' | 'succeeded' | 'failed' | 'canceled' | 'skipped' | 'rolling_back' | 'rolled_back' | 'rollback_failed' | 'uncertain'
export type BatchState = 'running' | 'paused' | 'canceling' | 'completed' | 'canceled'
export interface Batch { id: string; release_id: string; state: BatchState; max_concurrency: number; fail_pause_after: number; counts: Record<TaskState, number>; created_at: string; finished_at: string | null }
export interface Task { id: string; batch_id: string; installation_id: string; state: TaskState; last_phase: TaskState | null; code: string | null; message: string | null; attempt_id: string | null; lease_expires_at: string | null; created_at: string; finished_at: string | null }
export interface UpdateEvent { id: string; task_id: string; attempt_id: string; seq: number; source: string; payload: unknown; received_at: string }
export interface Preview { eligible_ids: string[]; excluded: { installation_id: string; code: string }[]; affected_installation_count: number }
export interface BatchInput { release_id: string; installation_ids: string[]; max_concurrency: number; fail_pause_after: number }
export interface Enrollment { enrollment_id: string; enrollment_secret?: string; expires_at: string }
export interface Coverage { status: 'registered' | 'bootstrap_required'; installation_ids: string[]; reason: string | null }
