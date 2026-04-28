# 任务清单: admin-frontend-node-traffic-hover

> **@status:** completed | 2026-04-28 16:48

```yaml
@feature: admin-frontend-node-traffic-hover
@created: 2026-04-28
@status: in_progress
@mode: R2
```

## LIVE_STATUS

```json
{"status":"in_progress","completed":5,"failed":0,"pending":0,"total":5,"percent":100,"current":"代码实现、知识库同步与前端构建验证已完成；PHP CLI 不可用，后端语法检查未执行","updated_at":"2026-04-28 16:34:10"}
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 5 | 0 | 0 | 5 |

---

## 任务列表

### 1. 后端接口

- [√] 1.1 在 `app/Http/Controllers/V2/Admin/Server/ManageController.php` 中为 `getNodes` 批量挂载节点流量统计
  - 预期变更: 基于当前节点 ID 集合批量聚合 `StatServer` 的今日、本月、总计上行/下行/合计，并写入 `traffic_stats`
  - 完成标准: 每个节点响应都包含 `traffic_stats.today/month/total.upload/download/total`
  - 验证方式: `php -l app/Http/Controllers/V2/Admin/Server/ManageController.php`
  - depends_on: []

### 2. 前端数据模型与工具

- [√] 2.1 在 `admin-frontend/src/types/api.d.ts` 中扩展节点流量统计类型
  - 预期变更: 新增节点流量统计接口，并将其挂到 `AdminNodeItem`
  - 完成标准: TypeScript 能识别 `row.traffic_stats.today.upload` 等字段
  - 验证方式: `npm run build`
  - depends_on: [1.1]

- [√] 2.2 在 `admin-frontend/src/utils/nodes.ts` 中新增节点流量格式化与 hover 详情数据构造
  - 预期变更: 提供字节自适应格式化和 `today/month/total` 三组详情行
  - 完成标准: null、undefined、非数字输入不会输出 `NaN`
  - 验证方式: `npm run build`
  - depends_on: [2.1]

### 3. 节点页展示

- [√] 3.1 在 `admin-frontend/src/views/nodes/NodesView.vue` 中把节点名称区域改为 hover 流量详情卡
  - 预期变更: 节点名称 hover 时显示今日、本月、累计的上行、下行和合计，样式遵循 `apple/DESIGN.md`
  - 完成标准: 不影响节点状态标签、墙状态标签、类型展示和行级操作
  - 验证方式: `npm run build`，必要时本地预览人工核对
  - depends_on: [2.2]

### 4. 文档与验收

- [√] 4.1 同步知识库并执行构建/语法验证
  - 预期变更: 更新 `.helloagents/modules/admin-frontend.md` 与 `CHANGELOG.md`，记录本次节点 hover 流量详情能力
  - 完成标准: 知识库与代码事实一致；验证命令完成并记录结果
  - 验证方式: `npm run build`、`php -l app/Http/Controllers/V2/Admin/Server/ManageController.php`
  - depends_on: [3.1]

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-04-28 16:25 | DESIGN | completed | 已完成上下文收集、唯一方案设计与任务拆分 |
| 2026-04-28 16:30 | 1.1 | completed | getNodes 已挂载 traffic_stats；累计流量使用 v2_server.u/d |
| 2026-04-28 16:31 | 2.1/2.2 | completed | 已扩展类型与节点流量格式化工具 |
| 2026-04-28 16:32 | 3.1 | completed | 节点名称 hover 详情卡已接入 |
| 2026-04-28 16:34 | 4.1 | completed | npm run build 通过；当前环境缺少 php/composer，PHP 语法检查未执行 |

---

## 执行备注

- 现有 `server/manage/getNodes` 已返回节点运行态、墙检测和权限组信息，本次只新增只读统计字段，不改变节点管理写操作。
- 当前工作树存在与本次无关的 `.github/workflows/admin-frontend-docker-publish.yml`、`.helloagents/CHANGELOG.md`、`.helloagents/modules/ci-workflows.md` 和 `public/assets/admin` 改动，执行时必须保留这些用户已有变更。
- `npm run build` 会刷新 `public/assets/admin` 构建产物，并更新 `admin-frontend/src/types/components.d.ts` 中的 `ElPopover` 自动组件声明。
- 当前执行环境没有 `php` 和 `composer` 命令，后端 PHP 语法检查无法在本机执行。
