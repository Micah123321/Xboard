# 变更提案: fix-stash-anytls-compat-filter

## 元信息
```yaml
类型: 修复
方案类型: implementation
优先级: P1
状态: 已完成
创建: 2026-04-24
```

---

## 1. 需求

### 背景
用户反馈 `?flag=stash` 订阅地址导入到 Stash 时，客户端提示“**不支持 anytls 协议**”。现场抓取订阅真值可见，当前 `stash` 导出仍包含多个 `type: anytls` 节点，因此报错不是客户端假警告，而是后端兼容过滤缺失。

### 目标
- 修复 `flag=stash` 订阅导出逻辑，避免未知版本或低版本 Stash 收到 `AnyTLS` 节点。
- 保留高版本 Stash 的 `AnyTLS` 能力：仅当客户端版本 `>= 3.3.0` 时继续导出。
- 改动范围限制在 Stash 订阅导出与相关知识记录，不联动其他协议。

### 约束条件
```yaml
范围约束: 仅修复 Stash 的 AnyTLS 兼容过滤，不修改 Clash / ClashMeta / General / Shadowrocket
技术约束: 继续使用现有 Laravel + Protocol 导出链路，不新增依赖
验证约束: 当前工作区缺少 PHP 运行时与 vendor，无法执行 phpunit/php lint，只能做静态校验与真值复核
业务约束: 未知版本采用保守策略，宁可少导出 AnyTLS，也不继续让 Stash 导入报错
```

### 验收标准
- [ ] `app/Protocols/Stash.php` 在客户端版本未知或 `< 3.3.0` 时不再导出 `AnyTLS`
- [ ] 客户端版本 `>= 3.3.0` 时仍允许导出 `AnyTLS`
- [ ] 现场订阅问题的根因、修复范围与验证限制已记录到方案包和知识库

---

## 2. 方案

### 根因分析
`ClientController` 会把 `flag=stash` 解析为 `Stash` 协议，但当链接里只有 `flag=stash` 且没有明确版本时，`clientVersion` 为空。`Stash` 虽然声明了 `anytls.base_version = 3.3.0`，但当前兼容链路没有真正消费该门槛，导致 `AnyTLS` 节点仍被输出。

### 技术方案
1. 在 `Stash` 导出器中增加 `AnyTLS` 版本守卫：仅当 `clientVersion >= 3.3.0` 时写入 `AnyTLS` 节点。
2. 未知版本默认视为不支持 `AnyTLS`，与用户选择的“保守兼容”策略保持一致。
3. 补一条轻量回归测试，锁定该版本判断函数的边界行为，供具备 PHP 环境时执行。

### 影响范围
```yaml
涉及模块:
  - app/Protocols/Stash.php
  - tests/Unit/Protocols/StashAnyTlsCompatibilityTest.php
  - .helloagents 方案包与变更日志
预计变更文件: 6-8
```

### 风险评估
| 风险 | 等级 | 应对 |
|------|------|------|
| 未知版本 Stash 会少收到 AnyTLS 节点 | 低 | 这是有意的保守降级，优先保证“能导入” |
| 其他协议也存在类似 base_version 配置未生效 | 中 | 本次按用户范围只修 Stash AnyTLS，其他协议后续按需单独处理 |
| 当前环境无法执行 PHP 级验证 | 中 | 保留静态回归测试文件，并在交付中明确说明阻塞点 |

---

## 3. 技术决策

### fix-stash-anytls-compat-filter#D001: 未知版本按不支持 AnyTLS 处理
**日期**: 2026-04-24
**状态**: ✅采纳
**背景**: 用户明确选择“默认对 Stash 做保守兼容”，要求未知版本或 `< 3.3.0` 时自动过滤 `AnyTLS`。
**决策**: `flag=stash` 导出在未拿到客户端版本时，直接跳过 `AnyTLS` 节点。
**理由**: 现场问题正是因为未知版本仍收到 `AnyTLS`；优先恢复导入稳定性。

### fix-stash-anytls-compat-filter#D002: 仅在 Stash 导出器中做定点修复
**日期**: 2026-04-24
**状态**: ✅采纳
**背景**: `base_version` 兼容机制的通用实现尚未完善，但当前用户问题只指向 `flag=stash`。
**决策**: 先在 `Stash` 导出器内增加 `AnyTLS` 版本守卫，不扩散到其他协议类。
**理由**: 改动最小、风险可控，且能精准解决现场报错。
