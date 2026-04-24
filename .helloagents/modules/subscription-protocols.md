# subscription-protocols

## 职责

- 负责 `/sub...` 订阅入口的客户端识别、协议匹配与最终导出文本生成
- 维护 `app/Protocols/*` 对 Clash / ClashMeta / Stash / General / SingBox 等客户端格式的适配
- 根据 `flag`、`User-Agent` 与客户端版本信息，对不兼容协议做过滤或降级

## 行为规范

- 订阅入口以 `app/Http/Controllers/V1/Client/ClientController.php` 为真相源，`flag` / `User-Agent` 解析结果会决定导出器类
- `flag=stash` 走 `app/Protocols/Stash.php`
- Stash 的 `AnyTLS` 目前采用保守兼容策略：只有明确拿到客户端版本且版本 `>= 3.3.0` 时才导出
- 未知版本优先保证“可导入”，因此会过滤 `AnyTLS`，避免客户端直接报“不支持 anytls 协议”
- 这类兼容修复默认只改目标导出器，不顺带联动其他协议类

## 依赖关系

- 依赖 `ClientController` 解析 `flag`、`User-Agent`、`types` 与 `filter`
- 依赖 `App\Support\AbstractProtocol` 提供公共过滤与协议抽象
- 依赖 `App\Models\Server` 与 `App\Services\ServerService` 提供节点数据

## 已知限制

- 当前工作区缺少 PHP 运行时与 `vendor`，本地只能做静态校验，无法直接执行协议导出单元测试
- 其他协议类中的 `base_version` 兼容声明暂未统一梳理；当前仅对用户命中的 Stash AnyTLS 做定点修复
