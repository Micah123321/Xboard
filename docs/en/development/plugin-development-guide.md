# XBoard 插件开发指南

## 插件结构

每个插件都是一个独立目录，推荐结构如下：

```
plugins/
└── YourPlugin/               # 插件目录（PascalCase 命名）
    ├── Plugin.php            # 插件主类（必需）
    ├── config.json           # 插件配置（必需）
    ├── routes/
    │   └── api.php           # API 路由
    ├── Controllers/          # 控制器目录
    │   └── YourController.php
    ├── Commands/             # Artisan 命令目录
    │   └── YourCommand.php
    └── README.md             # 文档说明
```

## 快速开始

### 1. 创建配置文件 `config.json`

```json
{
    "name": "My Plugin",
    "code": "my_plugin", // Corresponds to plugin directory (lowercase + underscore)
    "version": "1.0.0",
    "description": "Plugin functionality description",
    "author": "Author Name",
    "require": {
        "xboard": ">=1.0.0" // Version not fully implemented yet
    },
    "config": {
        "api_key": {
            "type": "string",
            "default": "",
            "label": "API Key",
            "description": "API Key"
        },
        "timeout": {
            "type": "number",
            "default": 300,
            "label": "Timeout (seconds)",
            "description": "Timeout in seconds"
        }
    }
}
```

### 2. 创建插件主类 `Plugin.php`

```php
<?php

namespace Plugin\YourPlugin;

use App\Services\Plugin\AbstractPlugin;

class Plugin extends AbstractPlugin
{
    /**
     * Called when plugin starts
     */
    public function boot(): void
    {
        // Register frontend configuration hook
        $this->filter('guest_comm_config', function ($config) {
            $config['my_plugin_enable'] = true;
            $config['my_plugin_setting'] = $this->getConfig('api_key', '');
            return $config;
        });
    }
}
```

### 3. 创建控制器

推荐做法：继承 `PluginController`。

```php
<?php

namespace Plugin\YourPlugin\Controllers;

use App\Http\Controllers\PluginController;
use Illuminate\Http\Request;

class YourController extends PluginController
{
    public function handle(Request $request)
    {
        // Get plugin configuration
        $apiKey = $this->getConfig('api_key');
        $timeout = $this->getConfig('timeout', 300);

        // Your business logic...

        return $this->success(['message' => 'Success']);
    }
}
```

### 4. 创建路由 `routes/api.php`

```php
<?php

use Illuminate\Support\Facades\Route;
use Plugin\YourPlugin\Controllers\YourController;

Route::group([
    'prefix' => 'api/v1/your-plugin'
], function () {
    Route::post('/handle', [YourController::class, 'handle']);
});
```

## 配置访问

在控制器中可直接访问插件配置：

```php
// Get single configuration
$value = $this->getConfig('key', 'default_value');

// Get all configurations
$allConfig = $this->getConfig();

// Check if plugin is enabled
$enabled = $this->isPluginEnabled();
```

## Hook 系统

### 常用 Hook（建议优先关注）

XBoard 在多个关键业务节点提供了内置 hook。插件可通过 `filter` 或 `listen` 扩展行为。

| Hook 名称                 | 类型   | 常见参数                 | 说明 |
| ------------------------- | ------ | ----------------------- | ---- |
| user.register.before      | action | Request                 | 用户注册前 |
| user.register.after       | action | User                    | 用户注册后 |
| user.login.after          | action | User                    | 用户登录后 |
| user.password.reset.after | action | User                    | 密码重置后 |
| order.cancel.before       | action | Order                   | 订单取消前 |
| order.cancel.after        | action | Order                   | 订单取消后 |
| payment.notify.before     | action | method, uuid, request   | 支付回调校验前 |
| payment.notify.verified   | action | array                   | 支付回调校验成功 |
| payment.notify.failed     | action | method, uuid, request   | 支付回调校验失败 |
| traffic.reset.after       | action | User                    | 流量重置后 |
| ticket.create.after       | action | Ticket                  | 工单创建后 |
| ticket.reply.user.after   | action | Ticket                  | 用户回复工单后 |
| ticket.close.after        | action | Ticket                  | 工单关闭后 |

> hook 能力会持续扩展。可结合本文与 `php artisan hook:list` 获取最新支持列表。

### Filter Hook

用于修改数据：

```php
// In Plugin.php boot() method
$this->filter('guest_comm_config', function ($config) {
    // Add configuration for frontend
    $config['my_setting'] = $this->getConfig('setting');
    return $config;
});
```

### Action Hook

用于执行动作：

```php
$this->listen('user.created', function ($user) {
    // Operations after user creation
    $this->doSomething($user);
});
```

## 实战示例：Telegram 登录插件

下面使用 TelegramLogin 插件展示完整实现思路。

### 插件主类

```php
<?php

namespace Plugin\TelegramLogin;

use App\Services\Plugin\AbstractPlugin;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->filter('guest_comm_config', function ($config) {
            $config['telegram_login_enable'] = true;
            $config['telegram_login_domain'] = $this->getConfig('domain', '');
            $config['telegram_bot_username'] = $this->getConfig('bot_username', '');
            return $config;
        });
    }
}
```

### 控制器（继承 `PluginController`）

```php
class TelegramLoginController extends PluginController
{
    public function telegramLogin(Request $request)
    {
        // Check plugin status
        if ($error = $this->beforePluginAction()) {
            return $error[1];
        }

        // Get configuration
        $botToken = $this->getConfig('bot_token');
        $timeout = $this->getConfig('auth_timeout', 300);

        // Business logic...

        return $this->success($result);
    }
}
```

## 插件定时任务（Scheduler）

插件可在主类中实现 `schedule(Schedule $schedule)` 方法来注册定时任务。

```php
use Illuminate\Console\Scheduling\Schedule;

class Plugin extends AbstractPlugin
{
    public function schedule(Schedule $schedule): void
    {
        // Execute every hour
        $schedule->call(function () {
            // Your scheduled task logic
            \Log::info('Plugin scheduled task executed');
        })->hourly();
    }
}
```

- 只需在 `Plugin.php` 中实现 `schedule()`。
- 主程序会自动调度所有插件任务。
- 支持 Laravel Scheduler 的全部能力。

## 插件 Artisan 命令

插件启用后，会自动加载 `Commands/` 目录中的命令类。

### 命令目录结构

```
plugins/YourPlugin/
├── Commands/
│   ├── TestCommand.php      # 测试命令
│   ├── BackupCommand.php    # 备份命令
│   └── CleanupCommand.php   # 清理命令
```

### 创建命令类

示例：`TestCommand.php`

```php
<?php

namespace Plugin\YourPlugin\Commands;

use Illuminate\Console\Command;

class TestCommand extends Command
{
    protected $signature = 'your-plugin:test {action=ping} {--message=Hello}';
    protected $description = 'Test plugin functionality';

    public function handle(): int
    {
        $action = $this->argument('action');
        $message = $this->option('message');

        try {
            return match ($action) {
                'ping' => $this->ping($message),
                'info' => $this->showInfo(),
                default => $this->showHelp()
            };
        } catch (\Exception $e) {
            $this->error('Operation failed: ' . $e->getMessage());
            return 1;
        }
    }

    protected function ping(string $message): int
    {
        $this->info("✅ {$message}");
        return 0;
    }

    protected function showInfo(): int
    {
        $this->info('Plugin Information:');
        $this->table(
            ['Property', 'Value'],
            [
                ['Plugin Name', 'YourPlugin'],
                ['Version', '1.0.0'],
                ['Status', 'Enabled'],
            ]
        );
        return 0;
    }

    protected function showHelp(): int
    {
        $this->info('Usage:');
        $this->line('  php artisan your-plugin:test ping --message="Hello"  # Test');
        $this->line('  php artisan your-plugin:test info                    # Show info');
        return 0;
    }
}
```

### 自动注册规则

- 插件启用时，自动注册 `Commands/` 目录下全部命令类。
- 命令命名空间自动按 `Plugin\YourPlugin\Commands` 解析。
- 支持 Laravel 命令全部能力（参数、选项、交互等）。

### 使用示例

```bash
# Test command
php artisan your-plugin:test ping --message="Hello World"

# Show information
php artisan your-plugin:test info

# View help
php artisan your-plugin:test --help
```

### 命令开发建议

1. **命令命名**：使用 `plugin-name:action`，例如 `telegram:test`。
2. **异常处理**：主流程建议统一 `try-catch`。
3. **返回码规范**：成功返回 `0`，失败返回 `1`。
4. **可用性**：提供清晰的帮助信息和错误提示。
5. **类型声明**：建议使用 PHP 8.2 类型声明。

## 开发工具与基础能力

### 控制器基类选择

#### 方案一：继承 `PluginController`（推荐）

- 自动配置读取：`$this->getConfig()`
- 自动状态检查：`$this->beforePluginAction()`
- 统一返回与错误处理

#### 方案二：使用 `HasPluginConfig` Trait

```php
use App\Http\Controllers\Controller;
use App\Traits\HasPluginConfig;

class YourController extends Controller
{
    use HasPluginConfig;

    public function handle()
    {
        $config = $this->getConfig('key');
        // ...
    }
}
```

### 配置类型

支持以下配置类型：

- `string`：字符串
- `number`：数字
- `boolean`：布尔值
- `json`：数组
- `yaml`

## 通用最佳实践

### 1. 保持主类简洁

- `Plugin.php` 只保留启动相关逻辑。
- 主要负责注册 hooks、路由、调度等入口。
- 复杂业务放到 Controller 或 Service。

### 2. 统一配置管理

- 所有配置都在 `config.json` 明确定义。
- 通过 `$this->getConfig()` 读取。
- 所有项建议提供默认值。

### 3. 路由设计清晰

- 使用语义化前缀。
- API 路由放在 `routes/api.php`。
- Web 路由放在 `routes/web.php`。

### 4. 完善错误处理

```php
public function handle(Request $request)
{
    // Check plugin status
    if ($error = $this->beforePluginAction()) {
        return $error[1];
    }

    try {
        // Business logic
        return $this->success($result);
    } catch (\Exception $e) {
        return $this->fail([500, $e->getMessage()]);
    }
}
```

## 调试技巧

### 1. 日志记录

```php
\Log::info('Plugin operation', ['data' => $data]);
\Log::error('Plugin error', ['error' => $e->getMessage()]);
```

### 2. 配置校验

```php
// Check required configuration
if (!$this->getConfig('required_key')) {
    return $this->fail([400, 'Missing configuration']);
}
```

### 3. 开发模式

```php
if (config('app.debug')) {
    // Detailed debug information for development environment
}
```

## 插件生命周期

1. **安装**：校验配置并注册到数据库。
2. **启用**：加载插件并注册 hooks、路由、命令、调度。
3. **运行**：处理请求并执行业务逻辑。

## 阶段总结

结合 TelegramLogin 插件的实践经验：

- **简洁**：主类短小、职责单一。
- **实用**：基于 `PluginController`，开发效率高。
- **可维护**：目录结构清晰，模式统一。
- **可扩展**：通过 hooks 可快速扩展业务。

## 插件 Artisan 命令完整指南

### 功能亮点

- **自动注册**：插件启用时自动注册 `Commands/` 下命令。
- **命名空间隔离**：各插件命令互不冲突。
- **类型安全**：支持 PHP 8.2 类型声明。
- **异常处理**：可统一错误输出与退出码。
- **配置集成**：命令中可读取插件配置。
- **交互支持**：支持输入、确认、选择等交互流程。

### 真实案例

#### 1. Telegram 插件命令

```bash
# Test Bot connection
php artisan telegram:test ping

# Send message
php artisan telegram:test send --message="Hello World"

# Get Bot information
php artisan telegram:test info
```

#### 2. TelegramExtra 插件命令

```bash
# Show all statistics
php artisan telegram-extra:stats all

# User statistics
php artisan telegram-extra:stats users

# JSON format output
php artisan telegram-extra:stats users --format=json
```

#### 3. Example 插件命令

```bash
# Basic usage
php artisan example:hello

# With arguments and options
php artisan example:hello Bear --message="Welcome!"
```

### 命令开发规范

#### 1. 命名约定

```php
// ✅ Recommended: Use plugin name as prefix
protected $signature = 'telegram:test {action}';
protected $signature = 'telegram-extra:stats {type}';
protected $signature = 'example:hello {name}';

// ❌ Avoid: Use generic names
protected $signature = 'test {action}';
protected $signature = 'stats {type}';
```

#### 2. 异常处理模式

```php
public function handle(): int
{
    try {
        // Main logic
        return $this->executeAction();
    } catch (\Exception $e) {
        $this->error('Operation failed: ' . $e->getMessage());
        return 1;
    }
}
```

#### 3. 交互能力

```php
// Get user input
$chatId = $this->ask('Please enter chat ID');

// Confirm operation
if (!$this->confirm('Are you sure you want to execute this operation?')) {
    $this->info('Operation cancelled');
    return 0;
}

// Choose operation
$action = $this->choice('Choose operation', ['ping', 'send', 'info']);
```

#### 4. 配置访问

```php
// Access plugin configuration in commands
protected function getConfig(string $key, $default = null): mixed
{
    // Get plugin instance through PluginManager
    $plugin = app(\App\Services\Plugin\PluginManager::class)
        ->getEnabledPlugins()['example_plugin'] ?? null;

    return $plugin ? $plugin->getConfig($key, $default) : $default;
}
```

### 进阶用法

#### 1. 多命令插件

```php
// One plugin can have multiple commands
plugins/YourPlugin/Commands/
├── BackupCommand.php      # Backup command
├── CleanupCommand.php     # Cleanup command
├── StatsCommand.php       # Statistics command
└── TestCommand.php        # Test command
```

#### 2. 命令间通信

```php
// Share data between commands through cache or database
Cache::put('plugin:backup:progress', $progress, 3600);
$progress = Cache::get('plugin:backup:progress');
```

#### 3. 与定时任务联动

```php
// Call commands in plugin's schedule method
public function schedule(Schedule $schedule): void
{
    $schedule->command('your-plugin:backup')->daily();
    $schedule->command('your-plugin:cleanup')->weekly();
}
```

### 命令调试建议

#### 1. 命令测试

```bash
# View command help
php artisan your-plugin:command --help

# Verbose output
php artisan your-plugin:command --verbose

# Debug mode
php artisan your-plugin:command --debug
```

#### 2. 命令日志

```php
// Log in commands
Log::info('Plugin command executed', [
    'command' => $this->signature,
    'arguments' => $this->arguments(),
    'options' => $this->options()
]);
```

#### 3. 性能监控

```php
// Record command execution time
$startTime = microtime(true);
// ... execution logic
$endTime = microtime(true);
$this->info("Execution time: " . round(($endTime - $startTime) * 1000, 2) . "ms");
```

### 常见问题

#### Q: 命令没有出现在列表里？

A: 检查插件是否启用，并确认 `Commands/` 目录存在且命令类有效。

#### Q: 命令执行失败？

A: 检查命名空间是否正确，且类继承了 `Illuminate\Console\Command`。

#### Q: 如何在命令中读取插件配置？

A: 通过 `PluginManager` 获取插件实例，再调用 `getConfig()`。

#### Q: 命令能否调用其他命令？

A: 可以，使用 `Artisan::call()`。

```php
Artisan::call('other-plugin:command', ['arg' => 'value']);
```

### 总结

插件命令系统为 XBoard 提供了强扩展能力：

- **开发效率**：快速构建运维与管理命令
- **运维便利**：支持自动化日常操作
- **监控能力**：便于查看系统运行状态
- **调试支持**：可快速定位与排查问题

合理使用插件命令，可以显著提升系统可维护性和使用体验。
