# 任务清单: node-traffic-limit-enforcement

> **@status:** completed | 2026-04-29 00:21

> **LIVE_STATUS:** completed | completed=11 failed=0 pending=0 total=11 percent=100 current=验收完成，准备归档

```yaml
@feature: node-traffic-limit-enforcement
@created: 2026-04-28
@status: completed
@mode: R2
@package: 202604281921_node-traffic-limit-enforcement
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 11 | 0 | 0 | 11 |

---

## 任务列表

### 1. Xboard 数据与接口

- [√] 1.1 修改 `database/migrations/*_add_*_to_v2_server_table.php`、`app/Models/Server.php`
  - 预期变更: 新增节点限额启用、重置日、重置时间、时区、状态时间戳字段；补齐 casts 和属性语义。
  - 完成标准: `v2_server` 可保存限额配置和运行状态；未设置时默认不启用。
  - 验证方式: 代码审查 migration up/down 与 casts；PHP 可用时执行语法检查或迁移测试。
  - depends_on: []

- [√] 1.2 修改 `app/Http/Requests/Admin/ServerSave.php`、`app/Http/Controllers/V2/Admin/Server/ManageController.php`
  - 预期变更: 管理端保存和批量/手动重置入口支持限额字段，手动重置清理面板限额状态并通知节点。
  - 完成标准: 节点保存 payload 可包含限额字段；重置节点流量后 `u/d` 与限额状态一起清理。
  - 验证方式: 审查 validation、save/update/resetTraffic/batchResetTraffic 路径；PHP 可用时执行相关测试。
  - depends_on: [1.1]

- [√] 1.3 修改 `app/Services/ServerService.php`、`app/Observers/ServerObserver.php`、`app/Services/NodeSyncService.php`
  - 预期变更: `buildNodeConfig()` 下发 `traffic_limit`；限额字段变化触发 `sync.config`；metrics 缓存限额状态。
  - 完成标准: 配置接口返回完整 `traffic_limit` 结构；节点上报 metrics 后管理端能读取限额状态。
  - 验证方式: 静态审查 config 结构和 observer 触发字段；PHP 可用时补接口/单元测试。
  - depends_on: [1.1, 1.2]

- [√] 1.4 新增或扩展 Xboard 定时重置逻辑，作用范围 `app/Console`、`app/Services`
  - 预期变更: 到达节点重置时间时清理面板 `u/d` 与限额状态，并推送节点配置同步。
  - 完成标准: 自动重置不会影响未启用限额节点；短月重置日按当月最后一天处理。
  - 验证方式: 代码审查时间计算；PHP 可用时补服务测试。
  - depends_on: [1.1, 1.3]

### 2. Xboard 管理端

- [√] 2.1 修改 `admin-frontend/src/types/api.d.ts`、`admin-frontend/src/utils/nodeEditorOptions.ts`、`admin-frontend/src/utils/nodeEditorMapper.ts`
  - 预期变更: 前端类型、表单模型、节点保存映射支持月额度、限额开关、重置日、重置时间、时区。
  - 完成标准: 新建/编辑节点时限额字段能正确回填和提交；禁用限额时提交安全默认值。
  - 验证方式: `cd admin-frontend && npm run build`。
  - depends_on: [1.2]

- [√] 2.2 修改 `admin-frontend/src/views/nodes/NodeEditorDialog.vue`、`admin-frontend/src/views/nodes/NodesView.vue`、`admin-frontend/src/utils/nodes.ts`
  - 预期变更: 编辑弹窗新增流量限额配置区；节点列表/流量浮层展示额度、使用量、状态和下次重置。
  - 完成标准: UI 文案简洁；状态不只依赖颜色；移动宽度下不挤压原有控件。
  - 验证方式: `cd admin-frontend && npm run build`，必要时人工检查节点页。
  - depends_on: [2.1, 1.3]

### 3. mi-node 协议与执行

- [√] 3.1 修改 `internal/panel/types.go`、`internal/model/types.go`、`internal/model/panel.go`、`internal/controlplane/mailbox.go`
  - 预期变更: 新增 `TrafficLimit` 配置结构，并完成 REST/WS/machine mailbox 转换和 clone。
  - 完成标准: 新字段可从 Xboard 配置进入 `NodeSpec`，machine mode 不丢字段。
  - 验证方式: `go test ./internal/model ./internal/controlplane` 或 `go test ./...`。
  - depends_on: [1.3]

- [√] 3.2 新增 `internal/trafficlimit` 或同等职责包
  - 预期变更: 实现周期窗口、短月重置日、双向增量累加、suspended 判定、本地 JSON 持久化和 metrics snapshot。
  - 完成标准: 单元测试覆盖未启用、超额、重置、短月、重启恢复。
  - 验证方式: `go test ./internal/trafficlimit`。
  - depends_on: [3.1]

- [√] 3.3 修改 `internal/tracker/tracker.go`、`internal/service/service.go`
  - 预期变更: tracker 暴露本 tick 节点总增量；Service 在 track tick 后检查限额，超额 `kernel.Stop()`，重置后恢复 `startKernel()`，并阻止 suspended 状态下自动启动。
  - 完成标准: `ensureRunning()`、`applyChanges()`、用户更新路径不会绕过 suspended gate；metrics 包含 `traffic_limit`。
  - 验证方式: `go test ./internal/service ./internal/tracker` 或 `go test ./...`。
  - depends_on: [3.2]

### 4. 验证与知识库

- [√] 4.1 补充 Xboard 与 mi-node 相关测试
  - 预期变更: 为 Xboard 配置下发/重置服务、mi-node 限额状态机和 service gate 补核心测试。
  - 完成标准: 测试覆盖超额下线、到点恢复、手动重置通知、短月重置。
  - 验证方式: `go test ./...`；PHP 测试在本机运行时可用时执行。
  - depends_on: [1.4, 3.3]

- [√] 4.2 运行验收并同步知识库
  - 预期变更: 执行可用验证命令；更新 `.helloagents/context.md`、模块文档、`CHANGELOG.md`，并记录验证结果。
  - 完成标准: 验收报告列出通过项、受阻项和残余风险；方案包状态更新为 completed 或标明失败原因。
  - 验证方式: `go test ./...`、`cd admin-frontend && npm run build`、知识库 diff 审查。
  - depends_on: [4.1]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-04-28 19:21 | 方案包初始化 | completed | 用户选择方案 1：节点本地强执行 + 面板配置编排；子代理构思按主代理直接执行记录 |
| 2026-04-28 20:32 | Xboard 限额配置与管理端 | completed | 新增限额字段、配置下发、重置服务、节点编辑表单和流量浮层展示 |
| 2026-04-28 20:34 | mi-node 限额执行 | completed | 新增本地持久化限额管理器、tracker 双向增量、内核停启 gate 和 metrics |
| 2026-04-28 20:36 | 测试与验证 | completed | go test ./...、admin-frontend npm run build、PHP 语法检查通过；Laravel PHPUnit 因缺 vendor/autoload.php 未运行 |
| 2026-04-28 20:37 | 知识库同步 | completed | 更新 Xboard 与 mi-node 模块文档、上下文和 CHANGELOG |

---

## 执行备注

- 本轮不执行生产部署、不运行生产数据库迁移、不推送远端。
- 子代理调度因当前主代理工具约束降级为主代理直接执行，后续任务按同一职责清单串行落地。
- `transfer_enable` 复用为节点月流量额度；新增字段只负责启用、重置规则和限额运行状态。
- 已补充 Xboard `ServerTrafficLimitServiceTest` 与 mi-node `trafficlimit/service/tracker` 相关测试；本机缺少 `vendor/autoload.php`，Laravel PHPUnit 未执行，仅完成 PHP 语法检查。
- 可用验收结果：`go test ./...` 通过；`admin-frontend npm run build` 通过；本轮 PHP 文件 `php -l` 通过。
