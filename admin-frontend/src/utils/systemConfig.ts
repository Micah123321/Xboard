import type { AdminConfigMappings, AdminPlanListItem } from '@/types/api'

export type SystemConfigSectionKey =
  | 'site'
  | 'safe'
  | 'subscribe'
  | 'invite'
  | 'server'
  | 'email'
  | 'telegram'
  | 'app'
  | 'subscribe_template'

export type SystemConfigFieldType =
  | 'text'
  | 'url'
  | 'textarea'
  | 'switch'
  | 'number'
  | 'select'
  | 'password'

export type SystemConfigFieldValue = string | number | boolean | string[] | null
export type SystemConfigFormState = Record<string, SystemConfigFieldValue>

interface SystemConfigOption {
  label: string
  value: string | number
}

export interface SystemConfigFieldSchema {
  key: string
  label: string
  type: SystemConfigFieldType
  valueType?: 'string' | 'number'
  placeholder?: string
  helper?: string
  fullWidth?: boolean
  rows?: number
  min?: number
  max?: number
  step?: number
  defaultValue?: SystemConfigFieldValue
  nullable?: boolean
  multiple?: boolean
  allowCreate?: boolean
  preserveWhitespace?: boolean
  options?: SystemConfigOption[]
  optionSource?: 'plans'
}

export interface SystemConfigSectionSchema {
  key: SystemConfigSectionKey
  navLabel: string
  title: string
  description: string
  fields: SystemConfigFieldSchema[]
}

const emailWhitelistOptions: SystemConfigOption[] = [
  'gmail.com',
  'qq.com',
  '163.com',
  'yahoo.com',
  'sina.com',
  '126.com',
  'outlook.com',
  'yeah.net',
  'foxmail.com',
].map((value) => ({ label: value, value }))

const withdrawMethodOptions: SystemConfigOption[] = [
  '支付宝',
  'USDT',
  'Paypal',
].map((value) => ({ label: value, value }))

const resetTrafficOptions: SystemConfigOption[] = [
  { label: '每月 1 号重置', value: 0 },
  { label: '按月重置', value: 1 },
  { label: '不重置', value: 2 },
  { label: '每年 1 月 1 日重置', value: 3 },
  { label: '按年重置', value: 4 },
]

const orderEventOptions: SystemConfigOption[] = [
  { label: '不执行额外事件', value: 0 },
  { label: '执行事件 1', value: 1 },
]

const deviceLimitOptions: SystemConfigOption[] = [
  { label: '按在线设备数统计', value: 0 },
  { label: '按去重 IP 统计', value: 1 },
]

const captchaOptions: SystemConfigOption[] = [
  { label: 'reCAPTCHA', value: 'recaptcha' },
  { label: 'Turnstile', value: 'turnstile' },
  { label: 'reCAPTCHA v3', value: 'recaptcha-v3' },
]

const emailEncryptionOptions: SystemConfigOption[] = [
  { label: '无加密', value: '' },
  { label: 'SSL', value: 'ssl' },
  { label: 'TLS', value: 'tls' },
]

export const systemConfigSections: SystemConfigSectionSchema[] = [
  {
    key: 'site',
    navLabel: '站点设置',
    title: '站点设置',
    description: '配置站点基础信息，包括站点名称、地址、试用策略与货币展示。',
    fields: [
      { key: 'app_name', label: '站点名称', type: 'text', placeholder: '请输入站点名称' },
      { key: 'app_description', label: '站点描述', type: 'textarea', fullWidth: true, rows: 3, placeholder: '用于首页与后台展示的站点描述' },
      { key: 'app_url', label: '站点网址', type: 'url', fullWidth: true, nullable: true, placeholder: 'https://example.com' },
      { key: 'force_https', label: '强制 HTTPS', type: 'switch', helper: '当站点已启用 HTTPS 或 CDN 回源使用 HTTPS 时建议开启。' },
      { key: 'logo', label: 'LOGO', type: 'url', fullWidth: true, nullable: true, placeholder: 'https://cdn.example.com/logo.png' },
      { key: 'subscribe_url', label: '订阅 URL', type: 'textarea', fullWidth: true, rows: 3, nullable: true, placeholder: '可填写一个或多个订阅入口地址' },
      { key: 'tos_url', label: '用户条款 (TOS) URL', type: 'url', fullWidth: true, nullable: true, placeholder: 'https://example.com/tos' },
      { key: 'frontend_enable', label: '开放用户前端', type: 'switch', defaultValue: true, helper: '关闭后首页返回空 404，API 不受影响。' },
      { key: 'stop_register', label: '停止新用户注册', type: 'switch' },
      { key: 'ticket_must_wait_reply', label: '工单等待回复限制', type: 'switch' },
      { key: 'try_out_plan_id', label: '注册试用套餐', type: 'select', optionSource: 'plans', valueType: 'number', defaultValue: 0, helper: '选择 0 表示关闭试用。' },
      { key: 'try_out_hour', label: '试用时长（小时）', type: 'number', min: 0, step: 1, valueType: 'number', defaultValue: 1 },
      { key: 'currency', label: '货币单位', type: 'text', placeholder: 'CNY' },
      { key: 'currency_symbol', label: '货币符号', type: 'text', placeholder: '¥' },
    ],
  },
  {
    key: 'safe',
    navLabel: '安全设置',
    title: '安全设置',
    description: '控制注册、验证码、后台路径与限流等安全相关策略。',
    fields: [
      { key: 'email_verify', label: '开启邮箱验证', type: 'switch' },
      { key: 'safe_mode_enable', label: '开启安全模式', type: 'switch' },
      { key: 'secure_path', label: '后台安全路径', type: 'text', placeholder: '至少 8 位，仅字母数字和中划线' },
      { key: 'email_whitelist_enable', label: '启用邮箱白名单', type: 'switch' },
      { key: 'email_whitelist_suffix', label: '邮箱白名单后缀', type: 'select', multiple: true, allowCreate: true, fullWidth: true, options: emailWhitelistOptions, helper: '支持自定义后缀，回车即可添加。' },
      { key: 'email_gmail_limit_enable', label: '限制 Gmail 别名注册', type: 'switch' },
      { key: 'captcha_enable', label: '开启人机验证', type: 'switch' },
      { key: 'captcha_type', label: '人机验证类型', type: 'select', valueType: 'string', options: captchaOptions, defaultValue: 'recaptcha' },
      { key: 'recaptcha_key', label: 'reCAPTCHA Secret Key', type: 'text', nullable: true, fullWidth: true },
      { key: 'recaptcha_site_key', label: 'reCAPTCHA Site Key', type: 'text', nullable: true, fullWidth: true },
      { key: 'recaptcha_v3_secret_key', label: 'reCAPTCHA v3 Secret Key', type: 'text', nullable: true, fullWidth: true },
      { key: 'recaptcha_v3_site_key', label: 'reCAPTCHA v3 Site Key', type: 'text', nullable: true, fullWidth: true },
      { key: 'recaptcha_v3_score_threshold', label: 'reCAPTCHA v3 分数阈值', type: 'number', min: 0, max: 1, step: 0.1, valueType: 'number', defaultValue: 0.5 },
      { key: 'turnstile_secret_key', label: 'Turnstile Secret Key', type: 'text', nullable: true, fullWidth: true },
      { key: 'turnstile_site_key', label: 'Turnstile Site Key', type: 'text', nullable: true, fullWidth: true },
      { key: 'register_limit_by_ip_enable', label: '开启按 IP 限制注册', type: 'switch' },
      { key: 'register_limit_count', label: 'IP 注册次数限制', type: 'number', min: 0, step: 1, valueType: 'number', defaultValue: 3 },
      { key: 'register_limit_expire', label: 'IP 限制周期（分钟）', type: 'number', min: 0, step: 1, valueType: 'number', defaultValue: 60 },
      { key: 'password_limit_enable', label: '开启密码尝试限制', type: 'switch' },
      { key: 'password_limit_count', label: '密码尝试次数', type: 'number', min: 0, step: 1, valueType: 'number', defaultValue: 5 },
      { key: 'password_limit_expire', label: '密码限制周期（分钟）', type: 'number', min: 0, step: 1, valueType: 'number', defaultValue: 60 },
    ],
  },
  {
    key: 'subscribe',
    navLabel: '订阅设置',
    title: '订阅设置',
    description: '管理续费、流量重置、订单事件与订阅路径等全局订阅行为。',
    fields: [
      { key: 'plan_change_enable', label: '允许变更订阅', type: 'switch' },
      { key: 'reset_traffic_method', label: '系统流量重置方式', type: 'select', valueType: 'number', options: resetTrafficOptions, defaultValue: 0 },
      { key: 'surplus_enable', label: '启用旧套餐折抵', type: 'switch' },
      { key: 'new_order_event_id', label: '新购订单事件', type: 'select', valueType: 'number', options: orderEventOptions, defaultValue: 0 },
      { key: 'renew_order_event_id', label: '续费订单事件', type: 'select', valueType: 'number', options: orderEventOptions, defaultValue: 0 },
      { key: 'change_order_event_id', label: '升级订单事件', type: 'select', valueType: 'number', options: orderEventOptions, defaultValue: 0 },
      { key: 'show_info_to_server_enable', label: '向节点展示用户信息', type: 'switch' },
      { key: 'show_protocol_to_server_enable', label: '向节点展示协议信息', type: 'switch' },
      { key: 'default_remind_expire', label: '默认开启到期提醒', type: 'switch', defaultValue: true },
      { key: 'default_remind_traffic', label: '默认开启流量提醒', type: 'switch', defaultValue: true },
      { key: 'subscribe_path', label: '订阅路径', type: 'text', placeholder: '例如 s' },
    ],
  },
  {
    key: 'invite',
    navLabel: '邀请&佣金设置',
    title: '邀请 & 佣金设置',
    description: '控制邀请注册、佣金比例与提现方式等分销策略。',
    fields: [
      { key: 'invite_force', label: '强制填写邀请码', type: 'switch' },
      { key: 'invite_commission', label: '默认邀请佣金比例 (%)', type: 'number', min: 0, step: 1, valueType: 'number', defaultValue: 10 },
      { key: 'invite_gen_limit', label: '邀请码生成上限', type: 'number', min: 0, step: 1, valueType: 'number', defaultValue: 5 },
      { key: 'invite_never_expire', label: '邀请码永不过期', type: 'switch' },
      { key: 'commission_first_time_enable', label: '仅首单发放佣金', type: 'switch', defaultValue: true },
      { key: 'commission_auto_check_enable', label: '自动确认佣金', type: 'switch', defaultValue: true },
      { key: 'commission_withdraw_limit', label: '佣金提现门槛', type: 'number', min: 0, step: 1, valueType: 'number', nullable: true },
      { key: 'commission_withdraw_method', label: '佣金提现方式', type: 'select', multiple: true, allowCreate: true, fullWidth: true, options: withdrawMethodOptions },
      { key: 'withdraw_close_enable', label: '关闭佣金提现', type: 'switch' },
      { key: 'commission_distribution_enable', label: '开启三级分销', type: 'switch' },
      { key: 'commission_distribution_l1', label: '一级分销比例 (%)', type: 'number', min: 0, step: 1, valueType: 'number', nullable: true },
      { key: 'commission_distribution_l2', label: '二级分销比例 (%)', type: 'number', min: 0, step: 1, valueType: 'number', nullable: true },
      { key: 'commission_distribution_l3', label: '三级分销比例 (%)', type: 'number', min: 0, step: 1, valueType: 'number', nullable: true },
    ],
  },
  {
    key: 'server',
    navLabel: '节点配置',
    title: '节点配置',
    description: '管理面板与节点的通讯令牌、推拉频率与在线设备统计方式。',
    fields: [
      { key: 'server_token', label: '通讯密钥', type: 'password', nullable: true, helper: '长度至少 16 位。' },
      { key: 'server_pull_interval', label: '拉取间隔（秒）', type: 'number', min: 1, step: 1, valueType: 'number', defaultValue: 60 },
      { key: 'server_push_interval', label: '推送间隔（秒）', type: 'number', min: 1, step: 1, valueType: 'number', defaultValue: 60 },
      { key: 'device_limit_mode', label: '设备数统计模式', type: 'select', valueType: 'number', options: deviceLimitOptions, defaultValue: 0 },
      { key: 'server_ws_enable', label: '启用节点 WebSocket', type: 'switch', defaultValue: true },
      { key: 'server_ws_url', label: '节点 WebSocket URL', type: 'url', fullWidth: true, nullable: true, placeholder: 'wss://example.com/ws' },
    ],
  },
  {
    key: 'email',
    navLabel: '邮件设置',
    title: '邮件设置',
    description: '配置 SMTP、发信地址与提醒邮件开关，支持保存后发送测试邮件。',
    fields: [
      { key: 'email_host', label: 'SMTP Host', type: 'text', nullable: true, placeholder: 'smtp.example.com' },
      { key: 'email_port', label: 'SMTP Port', type: 'text', nullable: true, placeholder: '465' },
      { key: 'email_username', label: 'SMTP 用户名', type: 'text', nullable: true },
      { key: 'email_password', label: 'SMTP 密码', type: 'password', nullable: true },
      { key: 'email_encryption', label: '加密方式', type: 'select', valueType: 'string', options: emailEncryptionOptions, defaultValue: '' },
      { key: 'email_from_address', label: '发件人地址', type: 'text', nullable: true, placeholder: 'noreply@example.com' },
      { key: 'remind_mail_enable', label: '开启提醒邮件', type: 'switch' },
    ],
  },
  {
    key: 'telegram',
    navLabel: 'Telegram设置',
    title: 'Telegram 设置',
    description: '配置 Bot Token、Webhook 地址与讨论组链接，保存后可手动设置 Webhook。',
    fields: [
      { key: 'telegram_bot_enable', label: '启用 Telegram Bot', type: 'switch' },
      { key: 'telegram_bot_token', label: 'Bot Token', type: 'password', nullable: true, fullWidth: true },
      { key: 'telegram_webhook_url', label: 'Webhook 基础地址', type: 'url', nullable: true, fullWidth: true, placeholder: 'https://example.com' },
      { key: 'telegram_discuss_link', label: '讨论组链接', type: 'url', nullable: true, fullWidth: true, placeholder: 'https://t.me/your-group' },
    ],
  },
  {
    key: 'app',
    navLabel: 'APP设置',
    title: 'APP 设置',
    description: '维护桌面端与移动端安装包版本、下载地址与更新展示信息。',
    fields: [
      { key: 'windows_version', label: 'Windows 版本号', type: 'text', nullable: true },
      { key: 'windows_download_url', label: 'Windows 下载地址', type: 'url', nullable: true, fullWidth: true },
      { key: 'macos_version', label: 'macOS 版本号', type: 'text', nullable: true },
      { key: 'macos_download_url', label: 'macOS 下载地址', type: 'url', nullable: true, fullWidth: true },
      { key: 'android_version', label: 'Android 版本号', type: 'text', nullable: true },
      { key: 'android_download_url', label: 'Android 下载地址', type: 'url', nullable: true, fullWidth: true },
    ],
  },
  {
    key: 'subscribe_template',
    navLabel: '订阅模板',
    title: '订阅模板',
    description: '集中维护各客户端的订阅模板文本，保存后由后端按类型分发。',
    fields: [
      { key: 'subscribe_template_singbox', label: 'Sing-box 模板', type: 'textarea', fullWidth: true, rows: 8, nullable: true, preserveWhitespace: true },
      { key: 'subscribe_template_clash', label: 'Clash 模板', type: 'textarea', fullWidth: true, rows: 8, nullable: true, preserveWhitespace: true },
      { key: 'subscribe_template_clashmeta', label: 'Clash Meta 模板', type: 'textarea', fullWidth: true, rows: 8, nullable: true, preserveWhitespace: true },
      { key: 'subscribe_template_stash', label: 'Stash 模板', type: 'textarea', fullWidth: true, rows: 8, nullable: true, preserveWhitespace: true },
      { key: 'subscribe_template_surge', label: 'Surge 模板', type: 'textarea', fullWidth: true, rows: 8, nullable: true, preserveWhitespace: true },
      { key: 'subscribe_template_surfboard', label: 'Surfboard 模板', type: 'textarea', fullWidth: true, rows: 8, nullable: true, preserveWhitespace: true },
    ],
  },
]

function getDefaultValue(field: SystemConfigFieldSchema): SystemConfigFieldValue {
  if (field.multiple) return []
  if (field.type === 'switch') return Boolean(field.defaultValue ?? false)
  if (field.type === 'number' || field.valueType === 'number') {
    return field.defaultValue ?? (field.nullable ? null : 0)
  }
  return field.defaultValue ?? ''
}

function normalizeNumberValue(
  value: unknown,
  field: SystemConfigFieldSchema,
): number | null {
  if (value === null || value === undefined || value === '') {
    if (field.defaultValue !== undefined) {
      return Number(field.defaultValue)
    }
    return field.nullable ? null : 0
  }

  const parsed = Number(value)
  if (Number.isFinite(parsed)) return parsed
  if (field.defaultValue !== undefined) return Number(field.defaultValue)
  return field.nullable ? null : 0
}

function normalizeTextValue(value: unknown, field: SystemConfigFieldSchema): string | null {
  if (value === null || value === undefined) {
    return field.nullable ? null : String(field.defaultValue ?? '')
  }

  const normalized = String(value)
  if (!normalized && field.nullable) return null
  return normalized
}

export function createSystemConfigFormState(): SystemConfigFormState {
  return systemConfigSections.reduce<SystemConfigFormState>((state, section) => {
    section.fields.forEach((field) => {
      state[field.key] = getDefaultValue(field)
    })
    return state
  }, {})
}

export function normalizeSystemConfigMappings(config: AdminConfigMappings | null | undefined): SystemConfigFormState {
  const state = createSystemConfigFormState()

  systemConfigSections.forEach((section) => {
    const group = config?.[section.key] ?? {}
    section.fields.forEach((field) => {
      const rawValue = group[field.key]
      if (field.multiple) {
        state[field.key] = Array.isArray(rawValue)
          ? rawValue.map((item) => String(item).trim()).filter(Boolean)
          : []
        return
      }

      if (field.type === 'switch') {
        state[field.key] = Boolean(rawValue)
        return
      }

      if (field.type === 'number' || field.valueType === 'number') {
        state[field.key] = normalizeNumberValue(rawValue, field)
        return
      }

      state[field.key] = normalizeTextValue(rawValue, field)
    })
  })

  return state
}

function serializeTextValue(value: SystemConfigFieldValue, field: SystemConfigFieldSchema): string | null {
  if (value === null || value === undefined) {
    return field.nullable ? null : ''
  }

  const stringValue = typeof value === 'string' ? value : String(value)
  const normalized = field.preserveWhitespace ? stringValue : stringValue.trim()
  if (!normalized && field.nullable) return null
  return normalized
}

export function serializeSystemConfigForm(form: SystemConfigFormState): Record<string, unknown> {
  return systemConfigSections.reduce<Record<string, unknown>>((payload, section) => {
    section.fields.forEach((field) => {
      const value = form[field.key]

      if (field.multiple) {
        payload[field.key] = Array.isArray(value)
          ? value.map((item) => String(item).trim()).filter(Boolean)
          : []
        return
      }

      if (field.type === 'switch') {
        payload[field.key] = Boolean(value)
        return
      }

      if (field.type === 'number' || field.valueType === 'number') {
        payload[field.key] = normalizeNumberValue(value, field)
        return
      }

      payload[field.key] = serializeTextValue(value, field)
    })
    return payload
  }, {})
}

export function getSystemConfigFieldOptions(
  field: SystemConfigFieldSchema,
  plans: AdminPlanListItem[],
): SystemConfigOption[] {
  if (field.optionSource === 'plans') {
    return [
      { label: '关闭试用', value: 0 },
      ...plans.map((plan) => ({
        label: plan.name,
        value: plan.id,
      })),
    ]
  }

  return field.options ?? []
}
