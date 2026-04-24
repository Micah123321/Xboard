# admin-frontend 节点管理真实工作台补全 — 需求

确认后冻结，执行阶段不可修改。如需变更必须回到设计阶段重新确认。

## 核心目标
- 在 `admin-frontend` 的 `#/nodes` 页面内，补齐“添加节点 / 编辑节点 / 编辑排序”三条真实链路，不再保留“下一阶段接入”占位提示。
- 参考用户提供的节点弹窗截图，交付居中大弹窗式节点编辑器，并让不同节点协议按各自配置方式动态切换字段。
- 保持 `apple/DESIGN.md` 与 `.helloagents/DESIGN.md` 定义的 Apple 化后台视觉语言，同时贴近截图中的高密度运营表单结构。

## 功能边界
- 必须实现 `#/nodes` 页面中的：
  - 添加节点
  - 编辑节点
  - 节点排序
- 必须支持以下协议的首版真实新增 / 编辑表单：
  - `Shadowsocks`
  - `VMess`
  - `Trojan`
  - `Hysteria`
  - `VLess`
  - `TUIC`
  - `SOCKS`
  - `Naive`
  - `HTTP`
  - `Mieru`
  - `AnyTLS`
- 表单必须覆盖通用字段：
  - 节点名称
  - 基础倍率
  - 动态倍率开关与时间段倍率规则
  - 自定义节点 ID
  - 节点标签
  - 权限组
  - 节点地址
  - 连接端口 / 服务端口
  - 父级节点
  - 路由组
  - 前台显示 / 节点启用状态
- 必须根据协议切换不同的配置块，至少覆盖当前后端 `ServerSave` 校验与 `Server::PROTOCOL_CONFIGURATIONS` 中已定义的关键字段：
  - 传输协议 / 传输层参数
  - TLS / Reality / ECH / uTLS
  - Hysteria 版本 / 带宽 / 混淆
  - TUIC 版本 / 拥塞控制 / ALPN / UDP relay
  - Shadowsocks cipher / obfs / plugin
  - VLess flow / encryption
  - Mieru transport / traffic pattern
  - AnyTLS padding scheme
- 必须接入现有 Laravel 管理接口：
  - `GET /server/manage/getNodes`
  - `POST /server/manage/save`
  - `POST /server/manage/sort`
  - `POST /server/manage/update`
  - `POST /server/manage/copy`
  - `POST /server/manage/drop`
  - `GET /server/group/fetch`
  - `GET /server/route/fetch`

## 非目标
- 本轮不改造 Laravel 节点后端逻辑、校验规则或数据库结构。
- 本轮不接入机器管理、批量删除、批量更新、批量重置流量等二级操作。
- 本轮不实现节点健康诊断、联机测试或复杂拓扑视图。

## 技术约束
- 技术栈固定为 `Vue 3 + TypeScript + Vite + Element Plus`。
- 后端真相源以仓库内 `App\Http\Controllers\V2\Admin\Server\ManageController`、`App\Http\Requests\Admin\ServerSave` 与 `App\Models\Server` 为准。
- 节点排序继续采用当前后台 `server/manage/sort` 的顺序保存模式。
- 构建验证使用 `admin-frontend/package.json` 中已有 `npm run build`。
- 构建产物继续输出到 `public/assets/admin` 子模块。

## 质量要求
- 弹窗结构需要贴近用户截图：顶部标题说明、右上角协议选择、白色表单面板、长内容滚动区、底部固定操作栏。
- 不同协议切换时，字段分组和默认值必须清晰，不能把所有字段堆成一张无差别长表单。
- 传输层 / TLS / Reality / 多路复用等设置需要按协议语义组织，而不是只暴露原始 JSON 文本。
- 排序流程需要提供可见的顺序编辑界面和保存反馈。
- 最终至少完成一次构建验证，并留下结构化视觉验收与交付证据。
