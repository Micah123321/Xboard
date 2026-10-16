import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import ts from 'typescript'

const source = await readFile(new URL('../src/utils/subscribePolicy.ts', import.meta.url), 'utf8')
const { outputText } = ts.transpileModule(source, {
  compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 },
})
const {
  isValidPolicyParameter, validateSubscribePolicy, serializeSubscribePolicy,
  parseSubscribePolicy, buildSubscribePolicyExamples,
} = await import('data:text/javascript;base64,' + Buffer.from(outputText).toString('base64'))

const protocols = ['shadowsocks', 'vmess', 'trojan', 'hysteria', 'vless', 'tuic', 'socks', 'naive', 'http', 'mieru', 'anytls', 'snell']
function config() {
  return {
    rules: protocols.map(protocol => ({
      protocol, label: protocol, default_enabled: protocol !== 'snell', allow_override: true,
      parameter: protocol, requirement: protocol === 'snell' ? 'Mihomo 1.19.26+' : '',
    })),
    reserved_parameters: ['token', 'flag', 'client'],
  }
}
const baseUrl = 'https://example.test/api/v1/client/subscribe'

test('parameter syntax accepts lowercase boundaries and rejects whitespace, unicode and punctuation', () => {
  for (const parameter of ['a', 'a0_', 'a'.repeat(32)]) assert.equal(isValidPolicyParameter(parameter), true, parameter)
  for (const parameter of ['', 'a'.repeat(33), 'A', '0abc', '_abc', 'a-b', 'a.b', 'a b', ' a', 'a ', 'a&token', 'a=1', '订阅', 'a\n', 'a\r\n', 'a\u2028']) {
    assert.equal(isValidPolicyParameter(parameter), false, JSON.stringify(parameter))
  }
})

test('all rules require unique non-reserved names even when overrides are disabled', () => {
  const { rules, reserved_parameters } = config()
  assert.deepEqual(Object.keys(validateSubscribePolicy(rules, reserved_parameters)), [])
  rules[0].parameter = 'same'
  rules[1].parameter = 'same'
  rules[1].allow_override = false
  let errors = validateSubscribePolicy(rules, reserved_parameters)
  assert.deepEqual(Object.keys(errors), ['shadowsocks', 'vmess'])
  rules[1].parameter = 'token'
  errors = validateSubscribePolicy(rules, ['TOKEN'])
  assert.deepEqual(Object.keys(errors), ['vmess'])
  rules[1].parameter = 'new_name'
  assert.deepEqual(Object.keys(validateSubscribePolicy(rules, reserved_parameters)), [])
  assert.equal(validateSubscribePolicy(rules, ['same']).shadowsocks.length > 0, true)
})

test('serialization preserves all 12 rules, order and booleans, dropping display fields without mutating input', () => {
  const { rules } = config()
  rules.reverse()
  rules[0].allow_override = false
  const original = structuredClone(rules)
  const payload = serializeSubscribePolicy(rules)
  assert.equal(payload.rules.length, 12)
  assert.deepEqual(payload.rules.map(rule => rule.protocol), rules.map(rule => rule.protocol))
  assert.deepEqual(payload.rules[0], { protocol: 'snell', default_enabled: false, allow_override: false, parameter: 'snell' })
  for (const rule of payload.rules) assert.deepEqual(Object.keys(rule), ['protocol', 'default_enabled', 'allow_override', 'parameter'])
  payload.rules[0].parameter = 'edited'
  assert.deepEqual(rules, original)
})

test('complete server configurations preserve order, values and changed reserved names on save echo', () => {
  const data = config()
  data.rules.reverse()
  data.rules[0].default_enabled = true
  data.rules[0].parameter = 'custom_snell'
  data.reserved_parameters.push('new_reserved')
  const parsed = parseSubscribePolicy(data)
  assert.deepEqual(parsed, data)
  assert.notEqual(parsed.rules, data.rules)
  assert.notEqual(parsed.rules[0], data.rules[0])
  assert.notEqual(parsed.reserved_parameters, data.reserved_parameters)
})

test('incomplete or malformed responses are rejected instead of enabling a partial save', () => {
  for (const data of [null, {}, { rules: [] }, { ...config(), reserved_parameters: null }]) assert.throws(() => parseSubscribePolicy(data))
  const missing = config()
  missing.rules.pop()
  assert.throws(() => parseSubscribePolicy(missing))
  const duplicate = config()
  duplicate.rules[1] = duplicate.rules[0]
  assert.throws(() => parseSubscribePolicy(duplicate))
  for (const patch of [{ default_enabled: 1 }, { allow_override: null }, { requirement: undefined }, { protocol: 'unknown' }]) {
    const data = config()
    Object.assign(data.rules[0], patch)
    assert.throws(() => parseSubscribePolicy(data))
  }
})

test('examples use TOKEN and explicit 1/0 semantics while tracking current defaults and renamed parameters', () => {
  const { rules, reserved_parameters } = config()
  let examples = buildSubscribePolicyExamples(rules, reserved_parameters, baseUrl)
  assert.equal(examples.plainUrl, baseUrl + '?token=TOKEN')
  assert.equal(examples.snellUrl, baseUrl + '?token=TOKEN&snell=1')
  const defaults = new URL(examples.defaultUrl)
  assert.equal(defaults.searchParams.get('token'), 'TOKEN')
  assert.equal(defaults.searchParams.get('snell'), '0')
  for (const rule of rules.filter(rule => rule.protocol !== 'snell')) assert.equal(defaults.searchParams.get(rule.parameter), '1')
  for (const rule of rules) {
    assert.equal(new URL(examples.includeUrl).searchParams.get(rule.parameter), '1')
    assert.equal(new URL(examples.excludeUrl).searchParams.get(rule.parameter), '0')
  }
  const snell = rules.find(rule => rule.protocol === 'snell')
  snell.parameter = 'custom_snell'
  snell.default_enabled = true
  examples = buildSubscribePolicyExamples(rules, reserved_parameters, baseUrl)
  assert.equal(new URL(examples.defaultUrl).searchParams.get('custom_snell'), '1')
  assert.equal(new URL(examples.defaultUrl).searchParams.has('snell'), false)
  assert.deepEqual(examples.fragments.snell, { include: 'custom_snell=1', exclude: 'custom_snell=0' })
  assert.equal(examples.snellUrl, baseUrl + '?token=TOKEN&custom_snell=1')
})

test('disabled, invalid, duplicate and reserved overrides never appear in URLs or fragments', () => {
  const { rules, reserved_parameters } = config()
  rules[0].allow_override = false
  rules[1].parameter = 'token'
  rules[2].parameter = 'not valid'
  rules[3].parameter = 'duplicate'
  rules[4].parameter = 'duplicate'
  const examples = buildSubscribePolicyExamples(rules, reserved_parameters, baseUrl + '?token=OLD&stale=1#old')
  const url = new URL(examples.defaultUrl)
  assert.equal(url.searchParams.get('token'), 'TOKEN')
  for (const name of ['shadowsocks', 'not valid', 'duplicate', 'stale']) assert.equal(url.searchParams.has(name), false)
  for (const rule of rules.slice(0, 5)) assert.equal(examples.fragments[rule.protocol], undefined)
  assert.equal(url.hash, '')
})

test('all overrides disabled leaves only token regardless of default selections', () => {
  const { rules, reserved_parameters } = config()
  for (const rule of rules) rule.allow_override = false
  const examples = buildSubscribePolicyExamples(rules, reserved_parameters, baseUrl)
  assert.equal(examples.defaultUrl, baseUrl + '?token=TOKEN')
  assert.equal(examples.includeUrl, examples.defaultUrl)
  assert.equal(examples.excludeUrl, examples.defaultUrl)
  assert.deepEqual(examples.fragments, {})
  assert.equal(examples.snellUrl, null)
})
