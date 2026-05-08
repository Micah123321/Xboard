# 变更提案: code-review-temporary-traffic-reset-lock

## 元信息
```yaml
类型: 修复
方案类型: implementation
优先级: P1
状态: 已确认
创建: 2026-05-09
selected_plan: conservative
```

---

## 1. 需求

### 背景
本次代码审查发现 `TrafficResetService::performReset()` 在事务内直接使用调用方传入的 `User` 模型计算并更新流量。新增的 `UserService::assignTemporaryTraffic()` 已经使用事务和 `lockForUpdate()`，但重置路径没有重新锁定用户行。若管理员分配一次性临时流量与自动/手动重置并发发生，重置逻辑可能基于旧模型计算 `transfer_enable`，随后覆盖刚分配的 `transfer_enable` 与 `temporary_transfer_enable`。

### 目标
- 流量重置时必须在事务内重新锁定当前用户行。
- 临时流量扣除与清空必须基于锁定后的最新数据库状态。
- 保持现有 `performReset(User $user, string $triggerSource)` 调用签名不变。
- 增加覆盖锁定刷新行为的单元测试，避免后续回退到旧模型计算。

### 约束条件
```yaml
时间约束: 本次审查内完成保守修复
性能约束: 单用户重置增加一次行锁读取，保持现有批量重置分页策略
兼容性约束: 不改变重置接口签名、不重构重置日志表结构
业务约束: 只修复重置并发一致性，不扩大到完整流量来源账本
```

### 验收标准
- [ ] `TrafficResetService::performReset()` 在事务内使用 `User::lockForUpdate()` 重新读取目标用户。
- [ ] 扣除 `temporary_transfer_enable` 使用锁定后的最新用户值。
- [ ] 新测试能证明旧模型的临时额度过期时，重置以最新锁定用户为准。
- [ ] 定向 PHPUnit 与 PHP 语法检查通过。

---

## 2. 方案

### 审查结果摘要
| 严重度 | 文件 | 问题 |
|--------|------|------|
| Medium | `app/Services/TrafficResetService.php` | 重置事务未锁定并刷新用户，可能与临时流量分配并发覆盖 |

### 保守方案
在 `TrafficResetService::performReset()` 的事务闭包开头使用 `User::lockForUpdate()->findOrFail($user->id)` 重新获取 `$lockedUser`，后续旧用量、临时额度扣除、下次重置时间计算、日志记录、缓存清理和 hook 调用都改用 `$lockedUser`。保留方法签名和返回语义。

### 激进方案
建立统一的用户流量额度账本或用户流量变更服务，把管理员临时流量、订单开通、重置、礼品卡奖励全部纳入同一个来源流水和幂等事务模型。该方案能提升审计能力，但改动面覆盖订单、礼品卡、订阅导出和统计，不适合本次审查修复直接执行。

### 技术方案
- 调整 `TrafficResetService::performReset()`，事务内锁定最新用户模型。
- `resolveTransferEnableAfterReset()` 保持为纯计算辅助方法。
- 新增单元测试通过 partial mock 拦截 `findOrFail()`，验证传入旧模型时仍使用锁定模型计算。

### 影响范围
```yaml
涉及模块:
  - app/Services/TrafficResetService.php: 重置事务内锁定用户行并使用最新值
  - tests/Unit/TrafficResetTemporaryTrafficTest.php: 增加锁定模型优先的测试
预计变更文件: 2
```

### 风险评估
| 风险 | 等级 | 应对 |
|------|------|------|
| 批量重置增加一次锁定读取 | 低 | 仍是单用户事务内读取，符合数据一致性需求 |
| Hook 接收刷新后的模型可能与旧对象不同 | 低 | 这是期望行为，hook 应读取重置后的真实用户状态 |
| 测试 mock Eloquent 静态调用较脆弱 | 中 | 使用最小 mock，仅覆盖 `findOrFail`，不引入数据库依赖 |

### 方案取舍
```yaml
唯一方案理由: 重置并发一致性属于当前临时流量功能的直接风险，行锁刷新是最小且符合现有分配逻辑的修复。
放弃的替代路径:
  - 全量流量账本: 长期更完整，但超出本次审查修复范围。
  - 只补测试不改实现: 无法消除真实并发覆盖风险。
回滚边界: 可单独回退 TrafficResetService 的锁定读取和对应测试，不影响数据库结构。
```

---

## 3. 技术设计

### 架构设计
```mermaid
flowchart TD
    A[performReset 传入用户] --> B[事务开始]
    B --> C[User::lockForUpdate()->findOrFail(id)]
    C --> D[基于锁定用户扣除 temporary_transfer_enable]
    D --> E[清空 u/d 与 temporary_transfer_enable]
    E --> F[记录日志、清缓存、触发 hook]
```

### API设计
N/A，本次不改变外部 API。

### 数据模型
N/A，本次不新增字段。

---

## 4. 核心场景

### 场景: 管理员分配临时流量与流量重置并发
**模块**: user-temporary-traffic  
**条件**: 调用方持有旧 `User` 模型，数据库中同一用户刚被分配了新的临时流量  
**行为**: 执行 `TrafficResetService::performReset()`  
**结果**: 重置事务先锁定数据库最新用户，再扣除最新 `temporary_transfer_enable`，不会用旧模型覆盖刚分配的数据。

---

## 5. 技术决策

### code-review-temporary-traffic-reset-lock#D001: 重置事务内重新锁定用户行
**日期**: 2026-05-09
**状态**: ✅采纳
**背景**: 临时流量分配使用行锁，但重置路径仍基于传入模型，两个路径并发时可能产生 lost update。
**选项分析**:
| 选项 | 优点 | 缺点 |
|------|------|------|
| A: 在重置事务内重新锁定用户行 | 改动小，和分配路径一致，可消除旧模型覆盖风险 | 增加一次数据库读取 |
| B: 完整流量账本 | 审计能力强，长期可扩展 | 改动面大，不适合审查内修复 |
**决策**: 选择方案 A
**理由**: 直接修复本次新增功能的并发风险，并保持现有调用接口稳定。
**影响**: `TrafficResetService`、临时流量重置测试。

---

## 6. 验证策略

```yaml
verifyMode: review-first
reviewerFocus:
  - performReset 是否所有后续逻辑都使用锁定后的用户模型
  - 旧模型和锁定模型临时额度不一致时是否以锁定模型为准
testerFocus:
  - php -l app/Services/TrafficResetService.php
  - vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Unit/TrafficResetTemporaryTrafficTest.php tests/Unit/UserTemporaryTrafficServiceTest.php tests/Unit/Orders/OrderServiceTemporaryTrafficTest.php
uiValidation: none
riskBoundary:
  - 不执行生产数据库迁移
  - 不改动外部 API
```

---

## 7. 成果设计

N/A。非视觉修复。
