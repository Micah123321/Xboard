# mi-node 自动更新统一实现契约 v1

状态：Go / PHP / Vue 已按本文实现并完成本地隔离回归，尚未部署；真实生产 systemd 整机更新与 MySQL/PostgreSQL 并发尚待灰度验收。

## 1. 已核实基础

Go cmd/xbctl/main.go:398–531 已实现双二进制下载、备份、替换、重启及失败回滚，尚无 SHA256，且重启成功即删除备份。main.go:25–34 使用固定安装目录及 mi-node.service；service_control.go 支持 systemd/OpenRC。PHP ServerV2.php:28–95 的 legacy token 是面板共享 server_token，machine 模式验证机器 token 和节点归属。MachineController.php:120–137 提供独立机器认证。后台 AdminRoute.php:29–31 使用动态 secure_path 和 admin/log 中间件。

保留 xbctl upgrade [--version VERSION]、既有流量修复及部署准备。新协议不改流量 report。首版自动升级只支持 Linux amd64/arm64 + systemd 宿主；OpenRC/其他 init 保留手工路径；Docker 明确 unsupported，不替换镜像内二进制。本次只写本文。

## 自动发现（独立于托管权限）

新版 mi-node 启动后，通过既有 `/api/v2/server/report` 认证通道附带 `update_inventory={installation_id,version,os,arch}`，兼容原 HTTP/HTTPS 面板绑定。版本允许构建提交 SHA。服务端只依据认证所得 node_info 关联节点，机器归属来自现有节点记录；元数据不得扩大权限。独立发现表按 node_id 更新最后上报信息，管理接口 `/server/update/discoveries` 分页返回。它不创建托管安装、不签发凭据、不启用策略、不下发二进制操作。元数据无效或尚未迁移时，忽略发现信息，保持原流量ACK。

发现 installation_id 在本机单独持久化，只用于识别同一安装的多个逻辑节点；不与托管凭据身份自动合并。

## 2. 安装身份与鉴权

installation_id 是本机初始化生成并持久化的 UUIDv4，代表共享二进制、配置及服务的一套实际安装，不是逻辑 instance/node/machine ID。同进程多节点、多面板只设一个 agent。当前固定安装布局只支持一个升级单元；多个服务共享 binary realpath 时标记 shared_binary_layout，首版不自动升级。克隆机器重新生成 ID 和凭据。

本地 root-only 配置指定唯一 authority_panel_url、authority_scope、固定安装路径和服务名。只从 authority 领取任务，其他面板继续业务；服务端不得下发路径、命令、脚本或变更 authority。authority 转移需本机停 timer、处理在途事务、撤销旧登记再登记。文件锁以 canonical binary path 为键，manual upgrade 和 agent 共用，防止伪造多个 ID 并行修改同一文件。

管理员生成登记票据，服务端固定 scope 为 {kind:machine,machine_id:12} 或 {kind:legacy,node_ids:[31,32]}（V2 全局节点 ID）。票据随机32字节 base64url，有效900秒，库只存 SHA256。两种模式均需要票据；legacy 共享 token 本身不证明节点安装归属。

S = /api/v2/server/update。新建独立 update-agent 鉴权中间件，不直接套用要求 node_id 的 server.v2。所有节点请求 HTTPS + JSON，凭据不放 query。

POST S/enroll：
~~~json
{"protocol_version":1,"enrollment_id":"uuid","enrollment_secret":"opaque","installation_id":"uuid","agent_token":"client-generated-32-byte-base64url","auth":{"kind":"machine","machine_id":12,"token":"existing-machine-token"}}
~~~
legacy auth 恰为 {kind:legacy,token:existing-server-token}。scope 仅来自票据；machine ID 必须匹配票据并通过已有机器鉴权。agent_token 在首请求前写入0600文件；服务端只保存散列。响应 data={installation_id,scope,policy_revision:1}。事务内消费票据并绑定 ID/token/scope；完全相同重试返回原成功（已成功后票据过期也可重放），异参数409；已有安装不可覆盖。

后续 Authorization: Bearer agent_token，token 推导安装/scope；body installation_id 不匹配403，非本安装 task 返回404。每次检查机器/节点仍有效且启用。机器 token 重置/机器停用/安装 revoke 撤销对应凭据；共享 server_token 重置撤销 legacy agent 凭据。agent 不可扩张 scope，新增 scope 需重新登记。租约失效不影响独立 agent 凭据的补报权限。所有日志屏蔽凭据。禁止远程任意 shell 接口。

## 3. 基本类型、发布与策略

UTF-8 JSON、snake_case。除标注 nullable/可选外字段必填。新 ID 为 UUID 字符串，现有 node/machine ID 为正整数；时间 UTC RFC3339秒，SHA256 为64位小写hex。未知请求字段422，客户端忽略新增响应字段。成功 HTTP200 + {data:...}；错误 {error:{code,message,retryable}}。401无效凭据、403范围/策略、404资源不可见、409冲突、422格式、429限流附 Retry-After、503暂时故障。

Release：
~~~json
{"id":"uuid","version":"v1.2.3","os":"linux","min_agent_protocol":1,"artifacts":[{"arch":"amd64","component":"mi-node","https_url":"https://downloads.example.test/v1.2.3/mi-node-linux-amd64","sha256":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","size_bytes":123456},{"arch":"amd64","component":"xbctl","https_url":"https://downloads.example.test/v1.2.3/xbctl-linux-amd64","sha256":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","size_bytes":234567}],"published_at":"2026-08-28T12:00:00Z","revoked_at":null}
~~~
version 只接受 vMAJOR.MINOR.PATCH，不接受 latest/预发布。每个支持架构恰有 mi-node/xbctl 两项，架构仅 amd64/arm64；若同时支持则四项。文件为纯二进制，size_bytes正数且≤512MiB。发布后不可变，仅撤销；目标架构缺制品不能排队。HTTPS URL 无 userinfo/fragment，最多3次HTTPS重定向，跨域不传面板凭据，TLS验证常开。两文件尺寸和SHA全部验证后才执行版本探测；新发行双方须提供稳定机器可读版本输出（精确version），兼容旧 -v/version。散列由可信发布构建生成，不能用下载所得散列冒充发布校验。

Policy={enabled:false,target_release_id:null,revision:1}。全局默认关闭、各安装默认关闭；两者启用且目标明确才允许创建/领取任务。不跟随latest。批次release必须等于安装target。相同版本且两散列一致→skipped/already_current；相同版本异散列→version_conflict；目标低于当前→downgrade_forbidden。版本未知时不自动升级。手工upgrade不依赖面板开关。

## 4. Agent 接口

### POST S/poll
~~~json
{"protocol_version":1,"installation_id":"uuid","versions":{"mi_node":"v1.2.2","xbctl":"v1.2.2"},"installed_sha256":{"mi_node":"64hex","xbctl":"64hex"},"runtime":{"os":"linux","arch":"amd64","init":"systemd","container":false},"capability":{"status":"supported","reason":null},"active_attempt":null}
~~~
versions/installed_sha256 各成员可null，null时不自动升级。init=systemd/openrc/other；capability.status=supported/unsupported，reason=null/docker/openrc/unsupported_os/unsupported_arch/shared_binary_layout/local_disabled。active_attempt非空为{task_id,attempt_id,last_seq,phase}，仅恢复提示，不直接改变任务状态。
返回 data={server_time,poll_after_seconds:60,global_enabled,policy,candidate:null}。candidate非空为{task_id,release_id,version,policy_revision}；无任务/关闭/暂停返回null。poll不授予执行权。HTTP超时10秒；失败退避60/120/240/480/900秒加0–15秒抖动。

### POST S/tasks/{task_id}/claim
请求 {installation_id,claim_id,policy_revision}；claim_id UUID先落盘，重试复用。
返回 data={task_id,attempt_id,lease_token,lease_expires_at,heartbeat_interval_seconds:30,lease_seconds:120,release:Release,state:claimed}。
固定事务锁顺序：全局设置→批次→安装→任务。复核策略revision/开关/架构/版本/发布撤销/安装独占/容量。同claim_id同参数返回原attempt和租约，不重复扣容量；异参数409 idempotency_conflict；其他claim409 task_claimed。容量满409 capacity_exhausted，等待下次poll；策略变化409 policy_changed。每task首版只有一个attempt，不自动重领过期任务。

### POST S/tasks/{task_id}/heartbeat
请求 {installation_id,attempt_id,lease_token}；响应 data={lease_expires_at,allow_start}。每30秒续期120秒，下载也续期；过期409 lease_expired。allow_start受策略/批次/撤销控制，取消或暂停后false。已经installing的本地事务必须继续验证或恢复，不远程强杀。

### POST S/tasks/{task_id}/events
~~~json
{"installation_id":"uuid","attempt_id":"uuid","lease_token":"opaque","seq":1,"state":"downloading","occurred_at":"2026-08-28T12:00:00Z","code":null,"message":null,"observed_versions":{"mi_node":"v1.2.2","xbctl":"v1.2.2"}}
~~~
响应 data={accepted_seq,state,lease_expires_at}；终态租约时间null。seq从1递增；同attempt/seq同payload返回原响应，异payload409；跳号409 expected_seq，error附expected_seq整数。message可null、最多1024字符、脱敏；非成功终态code必填，observed_versions同poll。occurred_at只展示，服务端时间决定时效。事件不隐式续租。

installing事件是执行闸门：事务内复核租约、开关、批次、发布、policy_revision并落库；agent收到成功ACK才替换。ACK丢失使用相同seq重试，禁止先执行。禁止/过期则保持旧文件，以canceled/policy_disabled或failed/lease_expired结束。ACK后断网不影响本地完成验证/回滚。

uncertain后仍允许同安装同attempt/token按seq补报本地持久化事件与结果，但不重新授予installing。只有数据库曾ACK installing才接受其verifying/rolling_back/succeeded/rolled_back/rollback_failed补报；未进入installing只允许安全阶段补报后failed/canceled。终态不可改写，仅原事件幂等重放。

## 5. 状态机与批次

Task正常链：queued→claimed→downloading→verifying_artifacts→installing→verifying→succeeded。claimed/downloading/verifying_artifacts可结束为failed/canceled/skipped；skipped仅already_current。installing/verifying失败必须rolling_back→rolled_back或rollback_failed。queued可canceled/skipped。领取后任意非终态租约过期→uncertain，保存last_phase。

容量占用：claimed/downloading/verifying_artifacts/installing/verifying/rolling_back/uncertain。uncertain持续占位，禁止超时即释放或重发。succeeded/failed/rolled_back/rollback_failed/canceled/skipped为终态。uncertain只能由同attempt补报或管理员实地确认agent已停且本地事务解决后resolve；人工操作必须记录依据，不能仅凭超时。

Batch=running/paused/canceling/completed/canceled。创建running；failure_count计failed/rolled_back/rollback_failed，达到fail_pause_after同事务暂停；uncertain独立计数并立即暂停。resume写failure_baseline，新增失败重新累计；有uncertain不许resume。cancel立即取消queued，对尚未installing任务设置cancel_requested并关闭安装闸门，已installing自行完成；全部终态后canceled。pause保留queued，已领取但未安装者以canceled/batch_paused终止，后续需新批次重试；其他任务全部终态→completed（可含失败，不表示全部成功）。

同时仅一个active batch（含paused/canceling）。全局max_concurrency默认5、范围1–100；批次默认5、范围1–100，实际取两者最小；缩容不终止在途。全局/安装关闭、发布撤销与取消均阻止未开始安装，不中断已installing。失败不自动重试；管理员创建新批次。每30秒维护租约及批次聚合；claim同步惰性核对超时，不依赖维护任务及时运行保证容量。

## 6. 外置 systemd agent 与持久化恢复

新增CLI：xbctl update-agent install --authority-panel-url URL --enrollment-file PATH；xbctl update-agent run；xbctl update-agent status；xbctl update-agent disable。票据文件0600，含 enrollment_id、enrollment_secret、expires_at 及 instance_id；instance_id 是 xbctl list 展示的本机既有绑定实例 ID，仅用于选择既有认证配置，不是后台 installation_id 或权限范围。秘密不放命令行。

install是本机明确操作。独立mi-node-update.service：Type=oneshot、User=root、固定ExecStart为xbctl update-agent run、TimeoutStartSec=0；timer：OnBootSec=60s、OnUnitInactiveSec=60s、RandomizedDelaySec=15s。不得PartOf/BindsTo主服务；禁止在mi-node子进程或其cgroup执行升级。timer保持报告，面板两个开关默认关闭。disable先检查在途事务，禁止杀掉恢复过程。

事务：共同flock→恢复journal/补报→poll/claim→两文件在目标同盘暂存→流式大小/SHA/ELF架构/版本校验→备份两原件→journal+fsync→installing ACK→顺序rename及目录fsync→固定systemctl restart→健康确认→写元数据→补报。每下载10分钟上限、最多两次尝试，systemctl每次180秒上限（覆盖既有120秒节点优雅退出预算）。两个rename不是联合原子，journal逐步记录，第二个失败恢复两件。至少保留最近成功事务备份至下次安全备份完成，失败备份不删。

/etc/mi-node/update-agent/transaction.json目录0700文件0600，记录task/attempt/claim_id、凭据引用、release快照、旧新散列、备份路径、阶段、last_seq、待发事件及ACK。原子临时写+fsync+rename+目录fsync。断电/agent异常后下次timer先恢复，不领取新任务；以实际散列+journal判断替换进度，混合状态恢复两原件。替换运行中的xbctl文件不终止旧进程，旧进程保有恢复逻辑。

健康：90秒内每5秒检查systemd active、MainPID为新启动进程、版本匹配。mi-node原子写本地0600 readiness文件，字段{pid,boot_id,version,ready,bindings_ready}，bindings_ready覆盖升级前所有启用绑定的控制循环/核心初始化；面板临时不可达不作为唯一失败条件。连续30秒PID稳定且readiness匹配才succeeded。回滚还原两件、重启并验证旧版稳定；旧版无readiness仅回滚允许PID/服务稳定30秒降级判断，code=rollback_legacy_health。失败为rollback_failed并保留现场。

手工upgrade保留旧参数/latest入口和OpenRC控制，与agent共用锁及可恢复引擎；latest仅手工先解析固定版本。旧发行无清单可保留原手工兼容路径并明示校验能力，不纳入自动任务。Docker通过原镜像部署流程升级。自动更新不得要求提前改写用户业务配置。

## 7. 新数据库表

统一前缀v2_node_update_；新UUID PK char(36)，时间UTC datetime，JSON依现有数据库支持，外键删除限制；各表timestamps。除nullable外必填；revision默认1，bool默认false，计数默认0。

| 表 | 必需字段与约束 |
|---|---|
| settings | id=1 PK, enabled, max_concurrency=5, revision |
| releases | id, version varchar(64) UNIQUE, os, min_agent_protocol, artifacts JSON, published_at, revoked_at nullable, created_by |
| enrollments | id, secret_hash char(64) UNIQUE, scope JSON, expires_at, consumed_at nullable, installation_id nullable, token_hash nullable, created_by |
| installations | id, scope JSON immutable, token_hash UNIQUE, revoked_at nullable, policy_enabled, target_release_id nullable FK, policy_revision, capability/runtime/versions/installed_sha256 JSON, last_seen_at nullable, active_task_id nullable UNIQUE, reported_active_attempt nullable JSON |
| batches | id, release_id FK, state, max_concurrency, fail_pause_after, failure_baseline, cancel_requested, created_by, finished_at nullable |
| tasks | id, batch_id FK, installation_id FK, release_snapshot JSON, policy_revision, state, last_phase nullable, cancel_requested, code/message nullable, finished_at nullable；UNIQUE(batch_id,installation_id) |
| attempts | id, task_id UNIQUE FK, installation_id FK, claim_id, claim_request_hash, lease_token_hash, lease_token_ciphertext TEXT, lease_expires_at nullable, last_seq, installing_acked_at nullable, started_at, finished_at nullable；UNIQUE(installation_id,claim_id) |
| events | id bigint PK, attempt_id nullable FK, task_id FK, seq nullable, payload_hash, payload JSON, source=agent/server/admin, received_at；UNIQUE(attempt_id,seq)，server/admin seq=null |
| requests | id bigint PK, admin_id, key UUID, route, request_hash, response JSON；UNIQUE(admin_id,key)，保留至少30天 |

租约token随机32字节，hash验证，应用密钥加密副本用于claim幂等重放。安装active_task_id在claim至终态事务内维护；单active batch由settings行锁保证。状态、事件、失败暂停、释放容量同事务，不用Redis临时锁替代数据库一致性。

## 8. 后台接口与 Vue

A=/api/v2/{实际secure_path}/server/update，沿用admin/log。写操作需Idempotency-Key UUID，同key同请求返回原结果，异请求409。PATCH携带revision，过期409 revision_conflict。列表page默认1/page_size默认20最大100，响应data={items,page,page_size,total}。凭据仅登记票据首次返回，其余DTO不含任何token。登记请求幂等重放只返回票据ID与到期时间；若首次响应丢失，页面提示重新生成新票据（旧票据自行到期），不会下载缺少秘密的文件。

| 方法 / A后缀 | 请求 | data |
|---|---|---|
| GET /settings | 无 | {enabled,max_concurrency,revision} |
| PATCH /settings | {enabled,max_concurrency,revision} | 同上 |
| POST /releases | {version,os,min_agent_protocol,artifacts} | Release |
| GET /releases | 分页 | Release列表 |
| POST /releases/{id}/revoke | {} | Release |
| POST /enrollments | {scope} | {enrollment_id,enrollment_secret,expires_at} |
| POST /installations/{id}/revoke | {} | {id,revoked_at} |
| GET /installations | 分页，machine_id?/node_id?/capability_status? | Installation列表 |
| PATCH /installations/{id}/policy | {enabled,target_release_id,revision} | Policy，enabled=true时target必填 |
| POST /batches/preview | {release_id,installation_ids} | {eligible_ids,excluded:[{installation_id,code}],affected_installation_count}，只读不需幂等key |
| POST /batches | {release_id,installation_ids,max_concurrency,fail_pause_after} | Batch；最多1000安装，去重冻结；任何不合格422并附excluded，不静默忽略 |
| GET /batches | 分页 | Batch列表 |
| GET /batches/{id} | 无 | Batch |
| POST /batches/{id}/pause | {} | Batch |
| POST /batches/{id}/resume | {} | Batch |
| POST /batches/{id}/cancel | {} | Batch |
| GET /batches/{id}/tasks | 分页，state? | Task列表 |
| GET /tasks/{id}/events | 分页 | 按received_at,id升序事件列表 |
| POST /tasks/{id}/resolve | {state,reason,agent_stopped:true} | Task，仅uncertain，state限failed/rolled_back/rollback_failed，reason 1–1024字符，审计人工核验声明 |
| GET /coverage | machine_id或node_id恰选一 | {status,installation_ids,reason} |

Installation={id,scope,versions,installed_sha256,runtime,capability,last_seen_at,online,policy,active_task_id,revoked_at}；online为180秒内poll，非业务在线。
Batch={id,release_id,state,max_concurrency,fail_pause_after,counts,created_at,finished_at}，counts包括全部Task状态的整数，零也返回。
Task={id,batch_id,installation_id,state,last_phase,code,message,attempt_id,lease_expires_at,created_at,finished_at}，attempt_id/lease_expires_at可null。
Event={id,task_id,attempt_id,seq,source,payload,received_at}，payload脱敏。
coverage从登记scope映射：有登记status=registered；无登记bootstrap_required（不能据此推断真实版本），reason=not_enrolled；有登记reason=null，具体unsupported看Installation。

Vue按installation_id去重，不按node/service发任务。确认页明确同进程全部绑定（含其他面板）会重启。旧节点显示“需手动升级一次并启用更新agent”，不显示虚假远程一键引导。展示版本/架构/SHA、全局与安装策略、批次失败/失联、事件和暂停/恢复/取消；区分rolled_back、rollback_failed、uncertain、Docker不支持、OpenRC手工。列表每5秒刷新，页面隐藏暂停。预览后创建再次服务端校验，不开放shell输入和在途强制取消。

## 9. 精确实现补充

- fail_pause_after 默认1，范围1–1000。计数/seq/revision为unsigned bigint；外部JSON须小于2^53。state/code/runtime字符串varchar(64)，URL varchar(2048)，message/reason varchar(1024)，散列char(64)，created_by/admin_id采用现有管理员主键类型。scope为空或legacy node_ids为空/重复、跨machine归属均422；登记scope含已属于其他有效安装的节点时409 scope_conflict（允许一安装多个逻辑节点，禁止节点重复登记）。
- 登记后、首次poll前，runtime={os:null,arch:null,init:null,container:null}，versions/installed_sha256成员null，capability={status:unsupported,reason:not_reported}。reason枚举增加not_reported；已登记但未报告不进入候选。现有版本双方不一致返回binary_version_mismatch，先手工修复；SemVer按整数三元组比较，禁止字符串字典序比较。
- 机器可读版本命令定为 mi-node version --json 与 xbctl version --json，输出恰为{version:"v1.2.3",os:"linux",arch:"amd64"}；不使用不稳定展示文本作为自动协议。readiness固定 /run/mi-node/readiness.json；升级前删除旧文件，boot_id须区别于升级前值，读取后核对MainPID和进程启动时间，防止PID复用。新版本ready须等全部绑定初始化，初始化失败不得写true。
- installing ACK必须在本地journal标记为已确认并fsync后替换。重放ACK时若lease_expires_at已过且尚无本地已执行阶段，则不开始新替换，报告failed/lease_expired；已确认 installing 的事务即使尚未替换，也统一走 rolling_back → rolled_back/rollback_failed，保留一致的服务端状态迁移。已执行阶段遇失联只恢复，不再等待面板。人工resolve后旧attempt的后续新事件409 task_terminal；只允许已存事件重放。
- poll版本变化及能力unsupported不会直接终结在途事务；事件为事实来源。全局关闭/安装关闭/发布撤销时queued以canceled结束，已领取未安装者经heartbeat/installing闸门退出；暂停只保留queued。installing及以后不检查策略开关来阻断补报，但仍检查agent身份。凭据被撤销导致补报失败时本地继续恢复并保留journal，面板uncertain待人工核对。
- 无在途本地安装事务但有未补报终态时，先补报再领取；面板离线不阻断本机手工升级，手工升级须确认journal没有未解决替换并保存既有结果待补报。禁止借手工upgrade覆盖未完成自动事务。
- terminal code固定：already_current、download_failed、checksum_mismatch、size_mismatch、binary_version_mismatch、arch_mismatch、backup_failed、lease_expired、policy_disabled、batch_paused、batch_canceled、release_revoked、restart_failed、health_failed、rollback_legacy_health、rollback_failed、manual_resolution。成功code=null；rolled_back以原始失败code表示原因，旧版健康降级例外使用rollback_legacy_health并在message保留原原因。HTTP额外code：invalid_credentials、scope_mismatch、not_found、validation_failed、idempotency_conflict、task_claimed、capacity_exhausted、policy_changed、expected_seq、revision_conflict、task_terminal、scope_conflict、version_conflict、downgrade_forbidden。
- 正常事件状态迁移验证以last_phase为基础；uncertain期间可顺序补齐安全阶段，但保持对外uncertain和容量占位，直到终态补报。禁止以补报downloading恢复租约。failure_count从任务终态统计，每任务只计一次。

## 10. 分工与验收

Go负责外置单agent、安装锁/事务/journal、协议及补报、校验/readiness/回滚、手工兼容；PHP负责表/DTO/范围鉴权、租约幂等/闸门/批次/审计；Vue负责类型和后台页面，禁止自行新增状态。

联合必须验证：amd64/arm64双产物；SHA错误零替换；第二rename失败双恢复；restart成功但不健康回滚；进程/机器重启恢复；xbctl自替换不丢回滚；丢响应幂等；100并发claim不超容量；暂停取消与installing竞争；租约失联保留占位；跨machine/installation/task冒领403/404；legacy无票据不可登记；多面板只有authority；Docker/OpenRC准确；旧节点bootstrap_required；手动/自动互斥；现有流量与部署改动保留。

本地验收：后端更新与流量联合 47 测试/295 断言；管理页面 7 项逻辑测试及两端类型检查/构建；Edge 隔离模拟接口渲染与设置提交；Go 故障注入、Linux WSL 文件锁及崩溃恢复。真实服务升级灰度、生产数据库并发与外部发行发布尚未执行。
