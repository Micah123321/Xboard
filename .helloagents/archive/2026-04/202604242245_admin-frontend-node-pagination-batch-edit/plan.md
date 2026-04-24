# admin-frontend 节点管理分页、置顶与批量修改 — 实施规划

## 目标与范围
- 将现有节点管理页从“单屏全量表格”升级为“可分页浏览、可稳定勾选、可批量维护”的真实运营工作台。
- 在不破坏既有 Apple 化后台节奏的前提下，补齐列表密度管理与批量维护动作。

## 架构与实现策略
- 继续保留 `NodesView.vue` 作为节点页装配入口，但补齐以下四类状态：
  - 前端分页状态（页码、每页条数）
  - 父/子节点筛选状态
  - 跨分页勾选状态
  - 批量修改弹窗状态
- 新增 `NodeBatchEditDialog.vue`：
  - 负责批量修改节点地址（host）、权限组、倍率
  - 采用“字段开关 + 输入控件”的结构，避免未启用字段被误提交
  - 明确提示仅对已勾选节点生效
- `src/utils/nodes.ts` 负责收敛节点列表的本地过滤逻辑：
  - 关键字搜索
  - 类型筛选
  - 权限组筛选
  - 父/子节点筛选
- `src/api/admin.ts` 与 `src/types/api.d.ts` 补齐节点批量修改的类型与接口封装。
- Laravel `ManageController::batchUpdate` 做最小扩展，仅补齐 `host / rate / group_ids` 三个字段的批量更新支持。
- “置顶节点”不新开接口，直接基于当前排序结果生成新的 `{ id, order }[]` 并提交到 `server/manage/sort`。

## 完成定义
- 节点列表底部出现可用的分页控件，并能按当前筛选结果切页。
- 节点列表支持勾选多个节点，切页后勾选状态仍能稳定恢复。
- 工具条出现“批量修改”入口，且只有已勾选节点时可用。
- 批量修改弹窗支持按需修改 `host / group_ids / rate`，并真实写回后台。
- 节点行菜单新增“置顶节点”，执行后该节点会排到列表最前。
- 搜索工具条新增“全部节点 / 父节点 / 子节点”筛选选项。

## 文件结构
- `.helloagents/plans/202604242245_admin-frontend-node-pagination-batch-edit/*`
- `admin-frontend/src/api/admin.ts`
- `admin-frontend/src/types/api.d.ts`
- `admin-frontend/src/utils/nodes.ts`
- `admin-frontend/src/views/nodes/NodesView.vue`
- `admin-frontend/src/views/nodes/NodeBatchEditDialog.vue`
- `admin-frontend/src/views/nodes/NodeBatchEditDialog.scss`
- `app/Http/Controllers/V2/Admin/Server/ManageController.php`

## UI / 设计约束
- 节点页首屏继续保持“黑色 Hero + 白色工作台”结构，不另起新皮肤。
- 父/子节点筛选应与搜索、类型、权限组并列出现在工具条，维持高密度但不拥挤的运营节奏。
- 批量修改弹窗保持轻薄白色面板、分组式表单和固定底栏，避免做成厚重后台配置页。
- “置顶节点”属于高频轻操作，应放在行级菜单中而不是二级排序弹窗里。

## 风险与验证
- 风险 1：分页后勾选容易丢失，因此需要在前端维护独立的勾选 ID 集合并在切页后回填。
- 风险 2：批量修改只改部分字段，若直接提交完整节点模型容易覆盖协议配置，因此必须使用专门的批量 payload。
- 风险 3：`batchUpdate` 原本不支持 `host / rate / group_ids`，前端先实现但后端不补齐会导致伪完成，因此必须同步扩展管理端接口。
- 验证方式：
  - `npm run build`
  - 代码级视觉自检：节点列表默认态、已勾选批量修改态、批量修改弹窗态
  - 代码检查：置顶排序 payload 与批量修改 payload

## 决策记录
- [2026-04-24] 节点分页采用前端本地分页，不为本轮新增后端分页接口。
- [2026-04-24] 批量修改范围按用户确认固定为“仅已勾选节点”，不扩展到筛选结果。
- [2026-04-24] “置顶节点”直接复用 `server/manage/sort`，避免新开单独排序接口。
