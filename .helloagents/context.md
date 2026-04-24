# 项目上下文

## 基本信息

- 项目: Xboard-new
- 当前工作目录: `E:\code\php\Xboard-new`
- 主要栈: Laravel(PHP) + Vue3/TypeScript/Vite/Element Plus (`admin-frontend`)

## 技术上下文

- 管理端前端位于 `admin-frontend/`
- 管理端 API 通过 `window.settings.secure_path` 或 `VITE_ADMIN_PATH` 解析 `/api/v2/{secure_path}` 前缀
- 登录接口复用 `/api/v2/passport/auth/login`
- 管理端仪表盘现已接入:
  - `stat/getStats`
  - `stat/getOrder`
  - `stat/getTrafficRank`
  - `system/getSystemStatus`
  - `system/getQueueStats`
- 管理端用户管理现已接入:
  - `user/fetch`
  - `user/generate`
  - `user/update`
  - `user/resetSecret`
  - `user/destroy`
  - `plan/fetch`
- 管理端节点管理现已接入:
  - `server/manage/getNodes`
  - `server/manage/save`
  - `server/manage/sort`
  - `server/group/fetch`
  - `server/group/save`
  - `server/group/drop`
  - `server/route/fetch`
  - `server/route/save`
  - `server/route/drop`
  - `server/manage/update`
  - `server/manage/copy`
  - `server/manage/drop`
- 管理端套餐管理现已接入:
  - `plan/fetch`
  - `plan/save`
  - `plan/update`
  - `plan/drop`
  - `plan/sort`
  - `server/group/fetch`
- 管理端订单管理现已接入:
  - `order/fetch`
  - `order/detail`
  - `order/assign`
  - `order/paid`
  - `order/cancel`
  - `order/update`
- 管理端礼品卡管理现已接入:
  - `gift-card/templates`
  - `gift-card/create-template`
  - `gift-card/update-template`
  - `gift-card/delete-template`
  - `gift-card/generate-codes`
  - `gift-card/codes`
  - `gift-card/toggle-code`
  - `gift-card/export-codes`
  - `gift-card/update-code`
  - `gift-card/delete-code`
  - `gift-card/usages`
  - `gift-card/statistics`
  - `gift-card/types`
- 管理端公告管理现已接入:
  - `notice/fetch`
  - `notice/save`
  - `notice/show`
  - `notice/drop`
  - `notice/sort`
- 管理端支付配置现已接入:
  - `payment/fetch`
  - `payment/getPaymentMethods`
  - `payment/getPaymentForm`
  - `payment/save`
  - `payment/show`
  - `payment/drop`
  - `payment/sort`
- 客户端订阅导出入口位于 `app/Http/Controllers/V1/Client/ClientController.php`，会根据 `flag` / `User-Agent` 匹配 `app/Protocols/*` 导出器
- `Stash` 订阅导出位于 `app/Protocols/Stash.php`，当前对 `AnyTLS` 采用保守兼容：仅客户端版本 `>= 3.3.0` 时导出

## 项目概述

- 主仓仍以 Laravel 为后端真相源
- `admin-frontend` 负责独立管理后台 UI 与交互逻辑
- 订阅协议导出由 Laravel 主仓内的 `app/Protocols/*` 提供，客户端兼容问题需以对应导出器实现为准
- `public/assets/admin` 为构建产物输出位置

## 开发约定

- 管理端路由使用 Hash 模式
- 管理端当前业务路由包含 `/dashboard`、`/users`、`/tickets`、`/nodes`、`/node-groups`、`/node-routes`、`/subscriptions/plans`、`/subscriptions/orders`、`/subscriptions/coupons`、`/subscriptions/gift-cards`、`/system/config`、`/system/notices`、`/system/payments`、`/system/plugins`、`/system/themes` 与 `/system/knowledge`
- `#/nodes` 当前已升级为真实节点工作台：支持搜索、显隐切换、复制、删除，以及 11 种协议的新增 / 编辑弹窗和排序对话框
- Bearer Token 存储于 `sessionStorage/localStorage`
- `admin-frontend` 的视觉方向当前以 Apple 风格为基线，优先纯色分区、系统字体栈和低装饰成本

## 当前约束

- 本地静态 preview 环境默认缺少 Laravel 注入的 `window.settings` 与真实管理 API，受保护页面只能验证结构与跳转，不能替代完整联调
- 后端接口契约以仓库内 Controller/Route 为准，不在前端推断字段
