const TRAFFIC_PATTERN_SEED_TAG = 0x08
const MAX_TRAFFIC_PATTERN_SEED = 0x7fffffff

// Mieru traffic-pattern is a base64-encoded appctl TrafficPattern protobuf.
export function generateMieruTrafficPattern(): string {
  const randomValues = new Uint32Array(1)
  crypto.getRandomValues(randomValues)
  const seed = (randomValues[0] & MAX_TRAFFIC_PATTERN_SEED) || 1

  return encodeMieruTrafficPatternSeed(seed)
}

export function encodeMieruTrafficPatternSeed(seed: number): string {
  const integerSeed = Number.isFinite(seed) ? Math.trunc(seed) : 1
  const safeSeed = Math.max(1, Math.min(MAX_TRAFFIC_PATTERN_SEED, integerSeed))
  const bytes = [TRAFFIC_PATTERN_SEED_TAG, ...encodeVarint(safeSeed)]
  const binary = bytes.map((byte) => String.fromCharCode(byte)).join('')

  return btoa(binary)
}

function encodeVarint(value: number): number[] {
  const bytes: number[] = []
  let next = value >>> 0

  while (next > 0x7f) {
    bytes.push((next & 0x7f) | 0x80)
    next >>>= 7
  }
  bytes.push(next)

  return bytes
}
