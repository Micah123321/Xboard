import type {
  AdminSubscribePolicyConfig,
  AdminSubscribePolicyRule,
  AdminSubscribePolicySavePayload,
} from '@/types/api'

const PROTOCOLS = [
  'shadowsocks', 'vmess', 'trojan', 'hysteria', 'vless', 'tuic',
  'socks', 'naive', 'http', 'mieru', 'anytls', 'snell',
]

export function isValidPolicyParameter(parameter: string): boolean {
  return parameter.length >= 1 && parameter.length <= 32
    && /^[a-z]/.test(parameter) && !/[^a-z0-9_]/.test(parameter)
}

export function validateSubscribePolicy(
  rules: AdminSubscribePolicyRule[],
  reservedParameters: string[],
): Record<string, string> {
  const errors: Record<string, string> = Object.create(null)
  const reserved = new Set(reservedParameters.map((parameter) => parameter.toLowerCase()))
  const counts = new Map<string, number>()
  for (const rule of rules) counts.set(rule.parameter, (counts.get(rule.parameter) ?? 0) + 1)
  for (const rule of rules) {
    if (!isValidPolicyParameter(rule.parameter)) {
      errors[rule.protocol] = '使用小写英文字母开头，仅含小写字母、数字、下划线，长度 1–32 字符'
    } else if (reserved.has(rule.parameter)) {
      errors[rule.protocol] = '该参数名为系统保留名，请更换'
    } else if ((counts.get(rule.parameter) ?? 0) > 1) {
      errors[rule.protocol] = '参数名与其他协议重复，请更换'
    }
  }
  return errors
}

export function serializeSubscribePolicy(rules: AdminSubscribePolicyRule[]): AdminSubscribePolicySavePayload {
  return {
    rules: rules.map(({ protocol, default_enabled, allow_override, parameter }) => ({
      protocol, default_enabled, allow_override, parameter,
    })),
  }
}

export function parseSubscribePolicy(value: unknown): AdminSubscribePolicyConfig {
  const config = value as AdminSubscribePolicyConfig | null
  if (!config || !Array.isArray(config.rules) || config.rules.length !== PROTOCOLS.length
    || !Array.isArray(config.reserved_parameters)
    || !config.reserved_parameters.every((parameter) => typeof parameter === 'string')
    || !config.rules.every((rule) => rule && PROTOCOLS.includes(rule.protocol)
      && typeof rule.label === 'string' && typeof rule.requirement === 'string'
      && typeof rule.default_enabled === 'boolean' && typeof rule.allow_override === 'boolean'
      && typeof rule.parameter === 'string')
    || new Set(config.rules.map((rule) => rule.protocol)).size !== PROTOCOLS.length) {
    throw new Error('配置数据不完整，需要包含全部 12 种协议和保留参数名，请刷新重试')
  }
  return {
    rules: config.rules.map((rule) => ({ ...rule })),
    reserved_parameters: [...config.reserved_parameters],
  }
}

export function buildSubscribePolicyExamples(
  rules: AdminSubscribePolicyRule[],
  reservedParameters: string[],
  baseUrl: string,
) {
  const errors = validateSubscribePolicy(rules, reservedParameters)
  // Omit invalid or disabled overrides so the preview never advertises an ineffective parameter.
  const overrides = rules.filter((rule) => rule.allow_override && !errors[rule.protocol])
  const makeUrl = (value: (rule: AdminSubscribePolicyRule) => boolean): string => {
    const url = new URL(baseUrl)
    url.search = ''
    url.hash = ''
    url.searchParams.set('token', 'TOKEN')
    for (const rule of overrides) url.searchParams.set(rule.parameter, value(rule) ? '1' : '0')
    return url.toString()
  }
  const plainUrl = new URL(baseUrl)
  plainUrl.search = ''
  plainUrl.hash = ''
  plainUrl.searchParams.set('token', 'TOKEN')
  const snellRule = overrides.find((rule) => rule.protocol === 'snell')
  const snellUrl = new URL(plainUrl)
  if (snellRule) snellUrl.searchParams.set(snellRule.parameter, '1')
  const fragments = Object.fromEntries(overrides.map((rule) => [rule.protocol, {
    include: rule.parameter + '=1', exclude: rule.parameter + '=0',
  }]))
  return {
    plainUrl: plainUrl.toString(),
    snellUrl: snellRule ? snellUrl.toString() : null,
    defaultUrl: makeUrl((rule) => rule.default_enabled),
    includeUrl: makeUrl(() => true),
    excludeUrl: makeUrl(() => false),
    fragments,
  }
}
