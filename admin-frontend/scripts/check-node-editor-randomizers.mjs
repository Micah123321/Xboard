import assert from 'node:assert/strict'
import {
  ANYTLS_RANDOM_SNI_DOMAINS,
  generateRandomAnytlsPaddingScheme,
  generateRandomAnytlsSni,
} from '../src/utils/nodeEditorRandomizers.ts'

const scheme = generateRandomAnytlsPaddingScheme()
const lines = scheme.split(/\r?\n/)

assert.equal(lines.length, 9)
assert.equal(lines[0], 'stop=8')

lines.slice(1).forEach((line, index) => {
  assert.match(line, new RegExp('^' + index + '=.+$'))
})

assert.ok(lines[3]?.includes(',c,'))
assert.ok(ANYTLS_RANDOM_SNI_DOMAINS.includes(generateRandomAnytlsSni()))

console.log('nodeEditorRandomizers self-check passed')
