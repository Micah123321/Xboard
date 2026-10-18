# AI 服务识别与未知流量推测

本轮为本地实现与隔离验收，未发布线上。无新增数据库迁移或节点程序改动。

## 截图样本

| 访问目标 | 服务识别 | 可信度 | 依据 |
| --- | --- | --- | --- |
| chatgpt.com | ChatGPT / OpenAI，AI 服务 | 高 | 独立域名规则；GeoSite OpenAI 清单 |
| dl-c-zb-u.drive.quark.cn | 夸克网盘文件传输，云存储 | 高 | drive.quark.cn 域名标签边界 |
| 91.108.56.137 | Telegram，即时通讯 | 中 | Telegram 官方公布的 91.108.56.0/22 专用网段 |
| ojfn.hzrmzz.com | 归属未知；可显示传输方向线索 | 服务未知 / 行为低 | 未找到可靠的服务归属证据，不以备案、共享 IP 或流量大小认定视频 |
| 无域名、无有效 IP | 服务归属未知；可显示传输方向线索 | 服务未知 / 行为低 | 真实访问目标没有采集 |

AI 分类 ai 覆盖 OpenAI、Claude、Gemini、DeepSeek、Perplexity、Grok、豆包、Copilot 与其他社区规则中的 AI 服务。即时通讯分类为 messaging。内置具体服务规则优先，域名完整/后缀匹配遵守标签边界，不因出现 ai 字样或 .ai 顶级域名就认定 AI。

## 数据来源

- GeoSite 源数据：https://github.com/v2fly/domain-list-community/blob/master/data/openai
- 本轮仍使用已保存且校验过的上游源码包，源 SHA256 33af033711bb4270ac890ad971098d537a25032bbe5f3fa0437defbe6ecbcc7c。随代码数据库扩充至 37 个服务组、5786 条有效规则，MIT 许可随数据库保留。
- 共享跟踪、广告、遥测域名和宽泛 LiveKit 转发端点不作为 OpenAI 归属证据；不支持的匹配或带属性 include 也会跳过。当前记录 359 次跳过。
- Telegram IP 快照来自 https://core.telegram.org/resources/cidr.txt，包含官方 14 个 IPv4/IPv6 网段，保存在 resources/data/access-traffic/telegram-cidr.txt。仅在上报来源为 IP、缺少域名且 IP 有效时使用。支持 IPv4-mapped IPv6；未指定地址不会命中。共享 CDN、云主机、运营商或城市范围不会反推出具体应用。
- 夸克官方说明：https://www.quark.cn/documents/help/quark-drive 。网盘同时支持存储、下载和播放，识别网盘归属不等同于识别具体操作。

规则读取、分类和页面查询不联网。数据文件选择内置快照与定时更新中更新时间较新的有效版本，旧的 24 组运行时缓存不会遮蔽新快照；原子更新、失败回退和30秒缓存继续适用。

## 流量形态线索

AccessTrafficActivityInference 仅使用原始上下行字节，计费倍率不影响线索。合计达到1 MiB时：

- 上行占比至少90%：疑似上传 / 同步，可信度低；可能包括文件、音频、视频发送。
- 下行占比至少90%：疑似下载 / 内容接收，可信度低；文件下载、网页资源和流媒体接收均可能产生该形态。
- 其余：双向数据传输，可信度低；具体同步、聊天、语音等仍未确定。

API 行增加 activity_hint、activity_confidence、activity_reason。页面独立显示橙色“流量形态（低可信度）”，CSV与客户HTML报告也保留线索及低可信度说明。原category仍由服务证据决定，流量形态不会把未知量计入视频、AI或网盘占比。未采集目标的行明确保留该缺口。

## 发布与历史更新

部署后端和 admin-frontend/dist-access-ai-inference 管理员资源，按项目流程重载配置、应用与队列。随代码的新快照可直接使用；本轮无需新增迁移或更新节点。

旧报告的分类保存在明细表，需要执行既有命令重新分类：

~~~bash
php artisan access-traffic:reclassify
~~~

该命令仅更新服务、分类、可信度与依据，不改原始字节、计费字节、倍率或账户扣费。新报告自动按新规则分类；流量形态在查询/导出时生成，不需要写回。手动同步上游数据使用 php artisan access-traffic:sync-domains，查看活跃库使用 --status。

## 验证

PHP84项测试、540断言通过：AI与网盘样本、域名伪后缀、官方IPv4/IPv6与边界、旧共享缓存优先级、广告/遥测排除、历史重分类及计费一致性、低可信度线索、整数大流量、分页及子节点计费。

管理端类型检查与独立生产构建通过；Chrome15项隔离API交互通过，零页面异常和零未处理API，涵盖新增AI/通讯分类与低可信度线索、原有邮箱/筛选/分页/导出及手机布局。证据位于 admin-frontend/dist-access-ai-inference/browser-evidence。线上仍需发布后核验，当前截图来自用户提供的生产页面，本轮浏览器验收使用本地构建和隔离API。
