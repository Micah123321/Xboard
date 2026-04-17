# 变更提案: fix-clashmeta-flow-map-export

## 元信息
```yaml
类型: 修复
方案类型: implementation
优先级: P1
状态: 已确认
创建: 2026-04-18
```

---

## 1. 需求

### 背景
当前 Clash Meta 订阅模板本身是合法的 block style YAML，但服务端在导出订阅时会重新执行
`Yaml::dump(...)`。由于 `inline` 深度设置过低，深层节点对象会被压成单行 flow map，
例如 TUIC 节点被输出为 `{ name: ..., alpn: [h3, h2, http/1.1], ... }`。

在部分 Clash Meta 客户端中，这种超长单行 flow map 会触发：

`Flow map in block collection must be sufficiently indented and end with a }`

### 目标
- 修复 Clash Meta 订阅导出，避免代理节点被压成易出错的单行 flow map。
- 保持改动最小，仅影响 `ClashMeta` 导出链路。
- 不改动模板文件和其他协议导出器。

### 约束条件
```yaml
时间约束: 当前回合内完成修复与静态验证
性能约束: 不引入额外运行时依赖
兼容性约束: 不改动节点字段语义，仅调整 YAML 序列化风格
业务约束: 仅修复 Clash Meta；Clash / Stash 暂不联动修改
```

### 验收标准
- [ ] `app/Protocols/ClashMeta.php` 中 YAML 导出策略已调整，深层对象优先输出为 block style
- [ ] 不再依赖单行 flow map 来表示 `proxies` 中的 TUIC 等节点对象
- [ ] 改动范围限制在 `ClashMeta` 导出逻辑与方案包记录
- [ ] 完成最小静态验证并明确运行验证受限原因

---

## 2. 方案

### 技术方案
将 `ClashMeta` 中依赖 `Yaml::dump(...)` 的默认风格选择，替换为显式的 block style YAML 渲染，
避免 `proxies`、`proxy-groups` 等深层结构被压缩成单行 flow map。

本次仅修改：

1. `app/Protocols/ClashMeta.php` 的 YAML 渲染路径；
2. 在代码旁增加一行说明性注释，明确修复目的；
3. 不改模板、不改节点字段构造、不改 Clash / Stash。

### 影响范围
```yaml
涉及模块:
  - ClashMeta 订阅导出: YAML 序列化风格调整
  - .helloagents 方案包: 记录本次修复过程
预计变更文件: 2-4
```

### 风险评估
| 风险 | 等级 | 应对 |
|------|------|------|
| 导出 YAML 变得更“展开”，文件体积略增 | 低 | 只调整序列化风格，不改字段内容 |
| 同类问题在 `Clash` / `Stash` 中也存在，但本次未修 | 中 | 范围明确限制为 Clash Meta，后续可按需补修 |
| 本机无 PHP / vendor，无法直接做运行时生成验证 | 中 | 通过代码路径与参数语义做静态验证，并在结论中说明限制 |

---

## 3. 技术设计（可选）

> 本次为序列化参数修复，不涉及架构/API/数据模型设计。

### 架构设计
N/A

### API设计
N/A

### 数据模型
N/A

---

## 4. 核心场景

### 场景: 导出含长 TUIC 节点的 Clash Meta 订阅
**模块**: Clash Meta 订阅导出
**条件**: 用户订阅中包含较长节点名、`alpn` 数组、`udp-relay-mode` 等字段
**行为**: 服务端生成 YAML 订阅文本
**结果**: 节点对象以 block style 输出，避免客户端在解析长单行 flow map 时失败

---

## 5. 技术决策

### fix-clashmeta-flow-map-export#D001: 显式渲染 block style YAML，不再依赖 dumper 的默认风格选择
**日期**: 2026-04-18
**状态**: ✅采纳
**背景**: 问题的根源在序列化风格，而不是 TUIC 节点字段本身的构造。仅修改 `Yaml::dump` 参数后，服务器真值仍然返回单行 flow map。
**选项分析**:
| 选项 | 优点 | 缺点 |
|------|------|------|
| A: 继续微调 `Yaml::dump` 参数 | 改动小 | 已被服务器真值否定，不能保证摆脱 flow map |
| B: 显式输出 block style YAML | 可完全控制 `proxies` / `proxy-groups` / `rules` 的格式 | 代码量更大，需要自定义渲染 |
**决策**: 选择方案 B
**理由**: 服务器真值已经证明仅调参数不够，必须显式控制 YAML 输出风格，才能彻底避免客户端继续收到 `- { ... }`。
**影响**: 仅影响 Clash Meta 订阅的最终文本格式，不影响节点字段语义

---

## 6. 成果设计

### 设计方向
- N/A

### 视觉要素
- N/A

### 技术约束
- **可访问性**: N/A
- **响应式**: N/A
