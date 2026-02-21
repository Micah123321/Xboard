# Xboard 仓库 Agent 协作规范

本文给自动化代理与协作者使用，目标是提升并行效率，减少回归风险。

## 1. 输出与沟通

- 默认使用简体中文。
- 先给结论，再给关键证据与改动点。
- 复杂任务必须先给执行计划，再落地改动。

## 2. 执行原则

1. 质量优先：不为速度牺牲正确性与可维护性。
2. 并行优先：能并行的信息收集与文件操作尽量并行。
3. 最小改动：仅修改与任务直接相关的文件。
4. 可追溯：说明改了什么、为什么改、如何验证。

## 3. 并行拆分规则

1. 先画依赖图，区分可并行和必须串行任务。
2. 不允许并行写同一文件或同一区域。
3. 收集阶段可并行，分析和冲突解决阶段串行。
4. 每轮并行结束后先汇总再进入下一轮。

## 4. 仓库事实（执行前必须知道）

- 技术栈：Laravel 12 + Octane + Horizon + Redis。
- 安装命令：`php artisan xboard:install`（交互式，强依赖 Redis 可连通）。
- 前端资产：仓库内为已编译静态资源，不是完整前端源码仓。
- 任务调度：依赖 `schedule:work` 或 `cron + schedule:run`。
- 当前仓库默认无 `tests/` 与 `phpunit.xml`，测试基建可能缺失。

## 5. 推荐命令基线

信息收集：

```bash
rg --files
rg -n "<pattern>" app config routes docs
```

本地运行（三进程）：

```bash
php artisan octane:start --host=0.0.0.0 --port=7001 --watch
php artisan horizon
php artisan schedule:work
```

质量检查：

```bash
php artisan about
php artisan migrate:status
vendor/bin/phpstan analyse --memory-limit=1G
```

测试（若已补齐测试）：

```bash
timeout 60s php artisan test
```

## 6. 高风险操作确认

以下操作必须先得到明确确认：

- 删除/批量改写文件
- `git reset --hard`、`git checkout --`、强制回滚
- 数据库清库、结构变更、批量数据更新
- 调用生产环境 API 或发送敏感数据
- 全局安装/卸载依赖

## 7. 仓库特殊风险提醒

- `update.sh` 含 `git reset --hard origin/master`，默认禁止直接执行。
- `xboard:update` / `xboard:install` 可能恢复 `plugins/` 下被跟踪文件，执行前需确认是否会覆盖开发中的插件改动。
- `compose.sample.yaml` 使用 `network_mode: host`，跨平台可用性有限，必要时改端口映射。

## 8. 提交前自检清单

1. 变更范围最小且与需求一致。
2. 关键路径已验证（安装/运行/队列/调度至少一项）。
3. 质量检查命令执行结果已记录。
4. 风险与未覆盖项已明确说明。

