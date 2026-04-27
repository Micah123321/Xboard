# HelloAGENTS 工作流踩坑复盘

## 适用范围

本文记录本轮在 `E:\code\php\Xboard-new` 中调用 HelloAGENTS Standby 工作流时踩到的坑，适用于后续在 Windows + PowerShell + 已激活 `.helloagents/` 项目中执行编码、验证、文档沉淀和收尾任务。

## 总原则

1. 当前用户消息优先于旧状态文件。
2. 代码和真实命令输出优先于记忆、文档和推断。
3. 工具失败必须记录为验证结论或阻断项，不能静默跳过。
4. `output_format=true` 时，只在最后一条收尾消息使用 HelloAGENTS 输出格式。
5. 涉及 `public/assets/admin` 时，必须同时看根仓和子模块状态。

## 坑点清单

### 1. `scripts/turn-state.mjs` 被协议要求，但项目中不存在

**表现**

- 收尾协议要求先执行 `scripts/turn-state.mjs write`。
- 实际执行 `node "scripts\turn-state.mjs" write kind=complete role=main` 时失败：
  - `Cannot find module 'E:\code\php\Xboard-new\scripts\turn-state.mjs'`
- 仓库根目录也没有 `scripts/` 目录。

**根因**

HelloAGENTS 协议假设存在统一的 turn-state 脚本，但当前项目和全局运行目录没有提供该脚本。

**正确处理**

- 收尾前按协议尝试一次。
- 若脚本不存在，明确记录失败原因。
- 不要反复重试同一个不存在的路径。
- 不要因为脚本失败而伪装成 turn-state 已写入。
- 用项目状态文件 `.helloagents/sessions/master/default/STATE.md` 补充记录当前任务状态。

**下次规则**

如果仓库仍没有 `scripts/turn-state.mjs`：

```powershell
node "scripts\turn-state.mjs" write kind=complete role=main
```

失败后只记录一次：

> turn-state 脚本缺失，无法写入运行态信号；已更新 `.helloagents/.../STATE.md` 作为恢复状态。

### 2. 旧 `STATE.md` 容易把新任务带偏

**表现**

进入本轮时，状态文件仍记录上一个“用户管理高级筛选活跃状态”任务，而用户实际要求是修复节点 TUIC 和权限组问题。

**根因**

`.helloagents/sessions/master/default/STATE.md` 是恢复参考，不是当前任务真相源。若直接按旧状态续作，会进入错误业务线。

**正确处理**

- 先读当前用户消息。
- 再看活跃方案包、代码、验证证据。
- 最后才用 `STATE.md` 补上下文。
- 确认是新任务后，立即重写 `STATE.md`，不要等到收尾。

**下次规则**

判断主线优先级固定为：

1. 当前用户最新消息。
2. 本轮确认的范围。
3. 当前代码与验证证据。
4. 活跃方案包。
5. `STATE.md`。

### 3. 输出格式只能用于最后一条消息

**表现**

配置中 `output_format=true`，但工具执行前、执行中、阻塞说明都不能使用 HelloAGENTS 外层格式。

**根因**

输出格式只允许用于本轮最终收尾消息。中间消息如果套格式，会让运行时误判任务完成或等待输入。

**正确处理**

- 中间状态用自然语言或工具计划，不使用 `{图标}【HelloAGENTS】`。
- 确认不再调用工具后，再使用最终格式。
- 如果使用了记忆引用，记忆引用块必须放在最终回复最末尾。

**下次规则**

只在满足以下条件时使用 HelloAGENTS 输出格式：

- 不再调用工具。
- 不再继续执行。
- 已完成或已明确阻塞。
- 已尝试写 turn-state，或明确记录脚本缺失。

### 4. `.helloagents/` 不是随便放文件的目录

**表现**

用户要求“提炼成一个 md 文档”，直觉上可以放到 `.helloagents/`，但协议要求 `.helloagents/` 内文件创建和更新必须遵循模板。

**根因**

`.helloagents/` 是运行态和知识库目录，部分文件有固定模板和生命周期；随意新增文档可能破坏流程约定。

**正确处理**

- 临时复盘、交付文档优先放到 `docs/`。
- 只有状态、方案、知识库、归档等 HelloAGENTS 定义文件才放入 `.helloagents/`。
- 更新 `.helloagents/CHANGELOG.md`、`modules/*.md` 时保持既有格式。

**下次规则**

用户要普通 Markdown 成品时，默认使用：

```text
docs/<topic>.md
```

除非用户明确要求放进 `.helloagents/`。

### 5. 容易把快速修复膨胀成完整方案包

**表现**

节点权限组和 TUIC 模板是明确小范围修复，容易因为项目已激活而误判为必须创建完整 `plans/{feature}/`。

**根因**

HelloAGENTS 有 T0-T3 交付分层，但不是所有激活项目任务都必须走完整方案包。明确、低风险、小范围、可验证的变更可按 T1 快速执行。

**正确处理**

- 先判断任务复杂度。
- 明确 bugfix 可以直接修，不用额外确认。
- 无独立方案包时，在 `CHANGELOG.md` 标记“快速修改（无方案包）”。

**下次规则**

符合以下条件时走快速修改：

- 用户描述了具体故障或具体页面。
- 改动范围可限定在少量文件。
- 不涉及生产高风险操作。
- 可用本地构建或静态检查验证。

### 6. 记忆可辅助定位，但不能替代当前证据

**表现**

记忆中已有 Xboard-new、`admin-frontend`、`public/assets/admin` 子模块等经验，能快速提醒验证路径和历史坑。

**风险**

记忆可能过期。直接把记忆当作当前事实，会漏掉分支、远端、目录结构变化。

**正确处理**

- 先用记忆找关键词和历史坑。
- 再用当前命令验证：
  - `git status`
  - `npm run build`
  - 子模块状态
  - 目标文件实际内容
- 最终报告区分“已验证”和“记忆提示”。

**下次规则**

记忆只能回答“应该重点看哪里”，不能回答“当前一定是什么状态”。

### 7. `public/assets/admin` 是子模块，根仓状态不等于真实前端产物状态

**表现**

执行 `npm run build` 后，根仓只显示：

```text
m public/assets/admin
```

这不是“没改到”，而是子模块内部产物发生变化。

**额外坑**

进入子模块执行 `git status` 时出现：

```text
fatal: detected dubious ownership in repository at 'E:/code/php/Xboard-new/public/assets/admin'
```

**正确处理**

使用单次 scoped safe directory，不要默认改全局配置：

```powershell
git -c safe.directory="E:/code/php/Xboard-new/public/assets/admin" -C "E:\code\php\Xboard-new\public\assets\admin" status --short --branch
```

**下次规则**

涉及管理端构建产物时必须给两层证据：

1. 根仓：源码文件变更 + `m public/assets/admin`。
2. 子模块：产物文件状态或关键词检索结果。

### 8. Windows + PowerShell 下不能偷懒写命令

**表现**

当前环境是 PowerShell，路径包含盘符和反斜杠。命令如果不加引号，容易因为空格、中文或特殊字符导致路径错误。

**正确处理**

- 所有路径用双引号。
- 不用 `cmd /c`。
- 多路径操作拆成多条命令。
- 文件修改优先用 `apply_patch`，避免 shell 重定向和编码问题。

**下次规则**

推荐命令形态：

```powershell
git -C "E:\code\php\Xboard-new" status --short
npm run build
```

不推荐：

```powershell
cmd /c ...
```

### 9. 验证阻断要写清楚，不能把未跑的检查说成通过

**表现**

前端可执行：

```powershell
npm run build
```

并已通过。

但 PHP 环境不可用：

```powershell
php -v
```

返回 `php` 命令不存在。

**正确处理**

- 前端构建通过就只声明前端构建通过。
- PHP 语法检查 / PHPUnit 未执行，原因写成阻断。
- 不用“应该没问题”代替验证结果。

**下次规则**

验证结果分三类写：

- 通过：命令执行成功。
- 失败：命令执行但返回错误。
- 未执行：缺少运行时、凭据、服务或外部依赖。

### 10. 工具输出需要审查，不要被外部内容带节奏

**表现**

搜索、构建、Git 输出中会混入大量无关内容，例如 `node_modules`、编译产物、历史 bundle。

**风险**

直接根据长输出行动，容易误改生成文件或误判业务源码。

**正确处理**

- 优先限定路径：
  - `admin-frontend/src/...`
  - `app/...`
  - `.helloagents/...`
- 避免全仓递归搜索进入 `node_modules` 或大型 bundle。
- 对长输出只提取任务相关结论。

**下次规则**

检索优先使用：

```powershell
rg -n --glob "*.php" --glob "!vendor/**" "keyword" "E:\code\php\Xboard-new\app"
```

而不是不加过滤地全仓搜索。

## 标准执行清单

### 开始任务

- [ ] 判断用户最新消息是否是新任务。
- [ ] 若项目已激活，读取当前 `STATE.md`，但不让旧状态覆盖新需求。
- [ ] 判断交付层级：快速修复、完整方案、只读分析或高风险操作。
- [ ] 若需要记忆，先快速检索，再用当前文件验证。

### 执行中

- [ ] 路径全部加双引号。
- [ ] 修改源码优先用 `apply_patch`。
- [ ] 不把普通文档随意放入 `.helloagents/`。
- [ ] 涉及 UI 时遵守当前 Apple 风格设计契约。
- [ ] 涉及 `public/assets/admin` 时记住它是子模块。

### 验证

- [ ] 能跑的命令必须跑。
- [ ] 不能跑的命令写明原因。
- [ ] `npm run build` 只证明前端构建通过。
- [ ] PHP 缺失时不能声称后端 PHPUnit 或语法检查通过。
- [ ] `git diff --check` 可作为基础格式检查。

### 收尾

- [ ] 更新 `STATE.md` 到下一轮可恢复状态。
- [ ] 有必要时更新 `CHANGELOG.md` 和模块知识。
- [ ] 尝试执行 `scripts/turn-state.mjs write`。
- [ ] 若 turn-state 脚本缺失，明确说明。
- [ ] 最后一条回复才使用 HelloAGENTS 输出格式。

## 本轮改进建议

1. 项目初始化时补齐 `scripts/turn-state.mjs`，或在协议中定义缺失时的标准 fallback。
2. 为 `public/assets/admin` 子模块提供固定的本地 safe.directory 说明，减少每轮踩同一个 Git 安全检查。
3. 在 `.helloagents/verify.yaml` 中区分前端、后端、子模块验证命令，避免只剩单一 `npm run build`。
4. 给“快速修改（无方案包）”建立轻量记录模板，避免每次临时判断写法不一致。
5. 将 Windows PowerShell 安全命令模板沉淀为项目级约定，减少路径和编码风险。
