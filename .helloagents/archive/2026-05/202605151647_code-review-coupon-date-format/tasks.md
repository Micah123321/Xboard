# 任务清单: 优惠券日期格式审查修复

> **@status:** completed | 2026-05-15 16:52

```yaml
@feature: code-review-coupon-date-format
@created: 2026-05-15
@status: completed
@mode: review-fix
```

## 进度概览

| 完成 | 失败 | 跳过 | 总数 |
|------|------|------|------|
| 3 | 0 | 0 | 3 |

## 任务列表

- [√] 1. 修正优惠券日期选择器的 `value-format` 与表单时间戳单位
- [√] 2. 更新优惠券工具层的日期归一化与保存转换逻辑
- [√] 3. 运行构建和差异检查，确认修复未破坏现有产物

---

## 执行日志

| 时间 | 任务 | 状态 | 备注 |
|------|------|------|------|
| 2026-05-15 16:47 | 审查发现 | in_progress | `value-format="X"` 与 Element Plus 时间选择器解析不兼容 |
| 2026-05-15 16:50 | 修复实施 | completed | 日期控件改为 `value-format="x"`，表单内部使用毫秒数字，提交前归一为秒 |
| 2026-05-15 16:51 | 构建验证 | completed | `admin-frontend` 执行 `npm run build` 通过，`git diff --check` 无空白错误 |

---

## 执行备注

- 本轮只修复审查发现的优惠券日期漂移问题，不扩大到其他订阅管理功能。
