# subscription-protocols

## 职责

- 负责 `/sub...` 订阅入口的客户端识别、协议匹配与最终导出文本生成
- 维护 `app/Protocols/*` 对 Clash / ClashMeta / Stash / General / SingBox 等客户端格式的适配
- 根据 `flag`、`User-Agent` 与客户端版本信息，对不兼容协议做过滤或降级
- 统一约束用户节点 API、订阅导出和 App 自动选择候选的运行态准入

## 行为规范

- 订阅入口以 `app/Http/Controllers/V1/Client/ClientController.php` 为真相源，`flag` / `User-Agent` 解析结果会决定导出器类
- `flag=stash` 走 `app/Protocols/Stash.php`
- Stash 的 `AnyTLS` 目前采用保守兼容策略：只有明确拿到客户端版本且版本 `>= 3.3.0` 时才导出
- 未知版本优先保证“可导入”，因此会过滤 `AnyTLS`，避免客户端直接报“不支持 anytls 协议”
- 这类兼容修复默认只改目标导出器，不顺带联动其他协议类
- 标准分发入口以 `ServerService::getAvailableServers()` 为节点准入真相源：`available_status=STATUS_OFFLINE` 的节点不进入分发结果，`STATUS_ONLINE_NO_PUSH` 与 `STATUS_ONLINE` 节点保留；节点恢复有效心跳后会在下一次请求中重新加入。
- 分发准入复用 `Server::available_status` 的父节点运行缓存回退，因此只由父入口上报的转发子节点仍按有效运行态参与分发，不改用仅自身心跳的状态判断。
- 用户节点 API 的 ETag 基于过滤后的节点 `cache_key` 列表生成；节点离线或恢复导致成员集合变化时返回新内容，相同可见节点集合可以复用原 ETag。
- `client.subscribe.servers` 和显式传入 `ClientController::doSubscribe()` 的节点集合属于受信扩展边界，可有意覆盖标准准入结果；核心服务不在插件钩子后重复过滤自定义节点。

## 依赖关系

- 依赖 `ClientController` 解析 `flag`、`User-Agent`、`types` 与 `filter`
- 依赖 `App\Support\AbstractProtocol` 提供公共过滤与协议抽象
- 依赖 `App\Models\Server` 与 `App\Services\ServerService` 提供节点数据
- 依赖节点运行缓存提供 `last_check_at` / `last_push_at`，由 `Server::available_status` 统一解析有效运行态

## 已知限制

- 其他协议类中的 `base_version` 兼容声明暂未统一梳理；当前仅对用户命中的 Stash AnyTLS 做定点修复
- 外部订阅客户端只会在下一次主动刷新时移除或恢复节点，服务端不会主动改写客户端已保存的旧配置
