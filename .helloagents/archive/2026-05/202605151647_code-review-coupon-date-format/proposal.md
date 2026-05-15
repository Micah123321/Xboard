# 审查修复方案: 优惠券日期格式

> **@status:** completed | 2026-05-15 16:52

```yaml
@feature: code-review-coupon-date-format
@created: 2026-05-15
@status: completed
@mode: review-fix
```

## 审查范围

- `admin-frontend/src/utils/coupons.ts`
- `admin-frontend/src/views/subscriptions/CouponEditorDialog.vue`
- 相关前端构建产物与 CI workflow 变更

## 发现的问题

### High: 日期选择器仍使用不兼容的 `value-format="X"`

Element Plus 当前 `time-picker` 工具只对 `value-format="x"` 做 Unix 毫秒时间戳特殊处理，`"X"` 会走普通 `dayjs(...).format()` / `dayjs(..., format)` 分支。当前表单模型传入秒级字符串后，打开新增或编辑弹窗仍可能被解析成异常年份，和用户反馈的 1700/1200 年漂移一致。

## 保守修复方案

- 将优惠券日期选择器改为 `value-format="x"`。
- 表单内部日期范围统一保存毫秒级时间戳数字。
- 保存前统一归一化为后端需要的秒级 Unix 时间戳。
- 保留兼容逻辑：如果已有调用传入秒级值，归一化函数仍能识别并提交正确秒值。

## 验收标准

- 新增优惠券默认日期显示为当前时间到 30 天后，不再漂移到异常年份。
- 编辑优惠券时，后端秒级 `started_at` / `ended_at` 能正确回显。
- 保存请求继续提交秒级 Unix 时间戳。
- `admin-frontend` 构建通过。

## 验收结果

- 已确认 Element Plus `time-picker` 工具只对小写 `x` 做时间戳特殊处理。
- 已将优惠券日期控件改为 `value-format="x"`。
- 已将表单日期范围统一为毫秒级数字，并保留秒级输入兼容归一化。
- `npm run build` 已通过。
