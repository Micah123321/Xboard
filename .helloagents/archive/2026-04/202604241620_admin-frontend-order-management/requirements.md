# admin-frontend 订单管理首版交付 — 需求

确认后冻结，执行阶段不可修改。如需变更必须回到设计阶段重新确认。

## 核心目标
- 在 `admin-frontend` 中开放“订阅管理 / 订单管理”入口，不再保留禁用态。
- 参考用户提供的订单管理截图，交付真实订单列表页，覆盖搜索、筛选、排序、分页与详情查看。
- 保持 `apple/DESIGN.md` 与 `.helloagents/DESIGN.md` 定义的 Apple 化后台视觉语言，但优先贴近截图中的数据密集型运营视图。

## 功能边界
- 必须实现 `#/subscriptions/orders` 真实页面。
- 页面必须包含：
  - 添加订单入口
  - 订单号搜索
  - 类型 / 周期 / 订单状态 / 佣金状态筛选
  - 支持排序的订单表格
  - 订单详情抽屉
  - 分配订单抽屉
- 必须接入现有 Laravel 管理接口：
  - `GET /order/fetch`
  - `POST /order/detail`
  - `POST /order/assign`
  - `POST /order/paid`
  - `POST /order/cancel`
  - `POST /order/update`
- 详情抽屉至少支持：
  - 查看订单核心信息与金额拆解
  - 对待支付订单手动标记已支付
  - 对待支付订单取消
  - 对有佣金金额的订单更新佣金状态

## 非目标
- 本轮不实现礼品卡管理真实页面。
- 本轮不改造 Laravel 订单后端接口逻辑。
- 本轮不新增批量操作、多选导出或订单打印等扩展功能。

## 技术约束
- 技术栈固定为 `Vue 3 + TypeScript + Vite + Element Plus`。
- 后端真相源以仓库内 `App\Http\Controllers\V2\Admin\OrderController` 为准。
- 构建验证使用 `admin-frontend/package.json` 中已有 `npm run build`。

## 质量要求
- 订单页面需要对齐截图中的高密度表格工作流，同时保持 Apple 化后台的克制风格。
- 金额字段必须统一处理“后端以分存储、前端以元展示”的换算。
- 页面需覆盖加载、错误、空状态与成功反馈。
- 最终至少完成一次构建验证，并留下结构化视觉验收与交付证据。
