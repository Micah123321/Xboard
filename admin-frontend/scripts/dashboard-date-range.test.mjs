import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import ts from 'typescript'

const source = await readFile(new URL('../src/utils/dashboard.ts', import.meta.url), 'utf8')
const { outputText } = ts.transpileModule(source, {
  compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 },
})
const { getDateRangeFromPreset } = await import('data:text/javascript;base64,' + Buffer.from(outputText).toString('base64'))

for (const browserTimezone of ['America/Los_Angeles', 'Asia/Tokyo', 'UTC']) {
  test('calendar dates use backend timezone from browser ' + browserTimezone, () => {
    const previousTimezone = process.env.TZ
    process.env.TZ = browserTimezone
    try {
      const now = new Date('2026-04-30T17:30:00Z')
      for (const [preset, startDate] of [
        ['1d', '2026-05-01'], ['7d', '2026-04-25'],
        ['30d', '2026-04-02'], ['90d', '2026-02-01'],
      ]) {
        assert.deepEqual(getDateRangeFromPreset(preset, 'Asia/Shanghai', now), {
          startDate, endDate: '2026-05-01',
        })
      }
      assert.deepEqual(getDateRangeFromPreset('1d', 'America/Los_Angeles', now), {
        startDate: '2026-04-30', endDate: '2026-04-30',
      })
    } finally {
      if (previousTimezone === undefined) delete process.env.TZ
      else process.env.TZ = previousTimezone
    }
  })
}

test('calendar arithmetic crosses DST and year boundaries without dropping days', () => {
  assert.deepEqual(getDateRangeFromPreset('7d', 'America/New_York', new Date('2026-03-10T12:00:00Z')), {
    startDate: '2026-03-04', endDate: '2026-03-10',
  })
  assert.deepEqual(getDateRangeFromPreset('90d', 'Asia/Shanghai', new Date('2026-01-01T00:00:00Z')), {
    startDate: '2025-10-04', endDate: '2026-01-01',
  })
})
