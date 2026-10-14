import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { webcrypto } from 'node:crypto'
import test from 'node:test'
import ts from 'typescript'
const source=await readFile(new URL('../../api/nodeUpdates.ts',import.meta.url),'utf8')
const helpers=source.slice(source.indexOf('function createRequestId')).replace('export function','function')
const compiled=ts.transpileModule(helpers,{compilerOptions:{target:ts.ScriptTarget.ES2022,module:ts.ModuleKind.None}}).outputText.replace('export function','function')
const factory=crypto=>new Function('crypto',compiled+';return createIdempotencyKeys()')(crypto)
test('HTTP fallback produces valid unique UUIDs and preserves retry keys',()=>{
 const keys=factory({getRandomValues:bytes=>webcrypto.getRandomValues(bytes)})
 const payload={enabled:true,installations:[{id:'fixture',revision:1}]}
 const id=keys.get('bulkPolicy',payload)
 assert.match(id,/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
 assert.equal(keys.get('bulkPolicy',payload),id)
 keys.clear('bulkPolicy',payload)
 assert.notEqual(keys.get('bulkPolicy',payload),id)
 assert.equal(new Set(Array.from({length:32},(_,i)=>keys.get('bulkPolicy',{i}))).size,32)
})
test('HTTPS uses native randomUUID once for the same pending operation',()=>{
 let calls=0
 const keys=factory({randomUUID:()=>{calls++;return '11111111-1111-4111-8111-111111111111'},getRandomValues:()=>{throw Error('Fallback should not run')}})
 assert.equal(keys.get('operation',{}),'11111111-1111-4111-8111-111111111111')
 assert.equal(keys.get('operation',{}),'11111111-1111-4111-8111-111111111111')
 assert.equal(calls,1)
})
