# admin-frontend

## 职责

- 提供 Vue3 管理端登录页、认证状态、路由守卫和主布局
- 封装管理端统计/系统状态接口
- 渲染后台仪表盘、排行、队列和系统运行状态

## 行为规范

- 登录成功后优先跳转 `redirect` 指定路由，否则回到 `/dashboard`
- 受保护路由在未登录时会自动附加 `redirect` 查询参数
- API 基础路径使用 `/api/v2/{secure_path}`，其中 `secure_path` 来自运行时配置
- 仪表盘以真实后端接口返回值为准，不在前端伪造业务统计
- 当前首页视觉基线为 Apple 风格：纯色分区、系统字体栈、单一蓝色强调和轻量层次
- 性能优化优先级高于装饰性表达，避免远程字体、全局模糊背景和固定特效层

## 依赖关系

- 依赖 `src/api/client.ts` 处理 axios 与认证头
- 依赖 Laravel 注入的 `window.settings`
- 构建输出到 `public/assets/admin`
