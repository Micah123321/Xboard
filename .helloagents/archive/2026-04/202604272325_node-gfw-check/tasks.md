# 任务清单: node-gfw-check

> **@status:** completed | 2026-04-27 23:40

```yaml
@feature: node-gfw-check
@created: 2026-04-27
@status: completed
@mode: R2
```

## LIVE_STATUS

```json
{"status":"completed","completed":15,"failed":0,"pending":0,"total":15,"percent":100,"current":"实现与验证完成，准备归档方案包","updated_at":"2026-04-27 23:58:00"}
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 15 | 0 | 0 | 15 |

---

## 任务列表

### 1. Xboard 后端数据与服务

- [√] 1.1 新增 `database/migrations/*_create_server_gfw_checks_table.php`
  - 预期变更: 创建 `server_gfw_checks` 表，保存父节点检测任务、状态、摘要、原始结果、错误和完成时间。
  - 完成标准: migration 结构完整，down 可删除表，索引覆盖 `server_id/status/created_at` 查询。
  - 验证方式: `php -l database/migrations/*_create_server_gfw_checks_table.php`（当前环境无 PHP CLI，已做代码审查）
  - depends_on: []
- [√] 1.2 新增 `app/Models/ServerGfwCheck.php` 并扩展 `app/Models/Server.php`
  - 预期变更: 增加检测记录模型、JSON casts、状态常量、`Server::gfwChecks()` 关系。
  - 完成标准: 模型可被服务层创建、查询、更新，PHP 语法通过。
  - 验证方式: `php -l app/Models/ServerGfwCheck.php app/Models/Server.php`（当前环境无 PHP CLI，已做代码审查）
  - depends_on: [1.1]
- [√] 1.3 新增 `app/Services/ServerGfwCheckService.php`
  - 预期变更: 实现 `startChecks`、`getPendingTaskForNode`、`reportResult`、`decorateServers`、状态判定与子节点继承。
  - 完成标准: 父节点下发任务；子节点返回 skipped/inherited；报告 check_id 必须归属当前节点。
  - 验证方式: `php -l app/Services/ServerGfwCheckService.php`（当前环境无 PHP CLI，已做代码审查）
  - depends_on: [1.2]

### 2. Xboard 后端接口与 WS

- [√] 2.1 修改 `app/Http/Controllers/V2/Admin/Server/ManageController.php` 与 `app/Http/Routes/V2/AdminRoute.php`
  - 预期变更: `getNodes` 附加 `gfw_check`；新增 `checkGfw` 管理接口。
  - 完成标准: 管理端可以按 ids 发起检测，响应包含 started/skipped/total。
  - 验证方式: `php -l app/Http/Controllers/V2/Admin/Server/ManageController.php app/Http/Routes/V2/AdminRoute.php`（当前环境无 PHP CLI，已做代码审查）
  - depends_on: [1.3]
- [√] 2.2 修改 `app/Http/Controllers/V2/Server/ServerController.php` 与 `app/Http/Routes/V2/ServerRoute.php`
  - 预期变更: 新增 `gfwTask` 和 `gfwReport` 节点端接口。
  - 完成标准: 通过 `server.v2` 鉴权读取节点；节点只能领取/上报自己的父节点检测任务。
  - 验证方式: `php -l app/Http/Controllers/V2/Server/ServerController.php app/Http/Routes/V2/ServerRoute.php`（当前环境无 PHP CLI，已做代码审查）
  - depends_on: [1.3]
- [√] 2.3 修改 `app/WebSocket/NodeWorker.php` 或相关事件处理
  - 预期变更: 确保 `gfw.check` 作为 panel->node 推送事件能经现有 Redis/NodeRegistry 发送到节点端。
  - 完成标准: 不影响现有 `sync.*` 和 `report.devices` 事件。
  - 验证方式: 代码审查确认现有 Redis/NodeRegistry 已支持任意 panel->node event，无需修改 `NodeWorker.php`
  - depends_on: [2.1]

### 3. admin-frontend 节点管理 UI

- [√] 3.1 修改 `admin-frontend/src/types/api.d.ts` 与 `admin-frontend/src/api/admin.ts`
  - 预期变更: 增加 `AdminNodeGfwCheck`、`AdminNodeGfwStatus`、`checkNodeGfw` API。
  - 完成标准: TypeScript 类型覆盖节点列表字段和检测接口响应。
  - 验证方式: `cd admin-frontend && npm run build`
  - depends_on: [2.1]
- [√] 3.2 修改 `admin-frontend/src/utils/nodes.ts`
  - 预期变更: 增加墙状态 meta、tooltip、状态筛选类型，搜索文本包含被墙/正常/异常/未检测/随父节点。
  - 完成标准: `filterNodes` 可按墙状态过滤，关键词可命中墙状态中文词。
  - 验证方式: `cd admin-frontend && npm run build`
  - depends_on: [3.1]
- [√] 3.3 修改 `admin-frontend/src/views/nodes/NodesView.vue`
  - 预期变更: 增加墙状态筛选、节点旁状态标签、单行检测、批量检测与 loading 状态。
  - 完成标准: 父节点可检测；子节点操作提示随父节点；UI 保持现有节点页风格。
  - 验证方式: `cd admin-frontend && npm run build`
  - depends_on: [3.2]

### 4. mi-node 检测执行与上报

- [√] 4.1 新增 `E:/code/go/mi-node/internal/gfwcheck`
  - 预期变更: 实现三网目标定义、并发 ping runner、结果汇总与状态判定。
  - 完成标准: 不依赖 shell 脚本，不包含 Telegram/自动安装逻辑；超时和并发可控。
  - 验证方式: `cd E:/code/go/mi-node && go test ./internal/gfwcheck`
  - depends_on: []
- [√] 4.2 修改 `E:/code/go/mi-node/internal/panel` 与 `E:/code/go/mi-node/internal/controlplane`
  - 预期变更: 支持 `gfw.check` WS 事件、REST 获取任务、上报检测结果。
  - 完成标准: 事件可转为 service 层事件；REST payload 自动携带 token/node_id。
  - 验证方式: `cd E:/code/go/mi-node && go test ./internal/panel ./internal/controlplane`
  - depends_on: [4.1]
- [√] 4.3 修改 `E:/code/go/mi-node/internal/service/service.go` 与 `E:/code/go/mi-node/internal/config/config.go`
  - 预期变更: 服务层处理 WS 检测事件，增加低频 REST 兜底轮询，避免重复执行同一 check。
  - 完成标准: 检测在后台执行，不阻塞主 select 循环；支持配置默认轮询间隔。
  - 验证方式: `cd E:/code/go/mi-node && go test ./internal/service ./internal/config`
  - depends_on: [4.2]
- [√] 4.4 修改 `E:/code/go/mi-node/Dockerfile`
  - 预期变更: runtime 镜像安装 `iputils` 以提供 `ping`。
  - 完成标准: 不新增无关依赖，不改变入口。
  - 验证方式: 文件审查
  - depends_on: [4.1]

### 5. 验证与知识库同步

- [√] 5.1 执行端到端验证命令
  - 预期变更: 运行 PHP 语法检查、前端 build、mi-node Go 测试，记录结果。
  - 完成标准: 可执行验证通过；不可执行项记录原因与残余风险。
  - 验证方式: `php -l ...`、`cd admin-frontend && npm run build`、`cd E:/code/go/mi-node && go test ./...`
  - depends_on: [1.1,1.2,1.3,2.1,2.2,2.3,3.1,3.2,3.3,4.1,4.2,4.3,4.4]
- [√] 5.2 更新 `.helloagents/CHANGELOG.md` 与方案包状态
  - 预期变更: 记录本次新增墙状态检测闭环，更新任务状态、LIVE_STATUS 和执行日志。
  - 完成标准: CHANGELOG 与 tasks.md 反映实际完成内容和验证结果。
  - 验证方式: 文件审查
  - depends_on: [5.1]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-04-27 23:25 | DESIGN | in_progress | 创建方案包并固化方案 A |
| 2026-04-27 23:42 | backend/frontend/mi-node | completed | 完成后端接口、前端节点页、mi-node 检测与上报链路 |
| 2026-04-27 23:50 | verification | completed | `npm run build` 通过，`go test ./...` 通过；PHP CLI 不在 PATH |
| 2026-04-27 23:55 | knowledge | completed | 更新 CHANGELOG 与模块知识库 |

---

## 执行备注

- 当前没有墙内检测 IP，本轮只做节点主动 ping 国内三网目标。
- 子节点默认不独立检测，显示和筛选继承父节点墙状态。
- 参考脚本中的 Telegram 通知和自动安装依赖不进入项目实现。
