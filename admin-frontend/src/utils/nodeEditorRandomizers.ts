export const ANYTLS_RANDOM_SNI_DOMAINS = [
  'xqqqx.com',
  '023467.xyz',
  '015679.xyz',
  '488448.xyz',
  'qqqz.de',
  '003939.xyz',
  '515666.xyz',
  '535888.xyz',
] as const

// ha-min: keep the template fixed so the parser stays predictable; widen it only if AnyTLS gains an official generator API.
export function generateRandomAnytlsPaddingScheme(): string {
  return [
    'stop=8',
    '0=' + buildRange(28, 64, 56, 120),
    '1=' + buildRange(96, 240, 240, 420),
    '2=' + [
      buildRange(320, 520, 420, 700),
      'c',
      buildRange(520, 760, 680, 1000),
      'c',
      buildRange(260, 460, 420, 760),
      'c',
      buildRange(640, 980, 840, 1200),
      'c',
      buildRange(180, 360, 320, 640),
    ].join(','),
    '3=' + buildRange(8, 24, 24, 56) + ',' + buildRange(420, 700, 700, 1100),
    '4=' + buildRange(240, 560, 520, 920),
    '5=' + buildRange(160, 420, 400, 820),
    '6=' + buildRange(420, 760, 760, 1200),
    '7=' + buildRange(300, 640, 640, 1180),
  ].join('\n')
}

export function generateRandomAnytlsSni(): string {
  return pickRandomItem(ANYTLS_RANDOM_SNI_DOMAINS)
}

function buildRange(startMin: number, startMax: number, endMin: number, endMax: number): string {
  const start = randomInt(startMin, startMax)
  const lower = Math.max(start + 1, endMin)
  const upper = Math.max(lower, endMax)
  const end = randomInt(lower, upper)
  return start + '-' + end
}

function pickRandomItem<T>(items: readonly T[]): T {
  if (items.length === 0) {
    throw new Error('Cannot pick from an empty list')
  }
  const randomValues = new Uint32Array(1)
  crypto.getRandomValues(randomValues)
  return items[randomValues[0] % items.length] ?? items[0]!
}

function randomInt(min: number, max: number): number {
  const lower = Math.min(min, max)
  const upper = Math.max(min, max)
  const randomValues = new Uint32Array(1)
  crypto.getRandomValues(randomValues)
  return lower + (randomValues[0] % (upper - lower + 1))
}
