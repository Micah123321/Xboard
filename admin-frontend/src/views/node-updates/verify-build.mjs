import { build } from 'vite'
import vue from '@vitejs/plugin-vue'
import AutoImport from 'unplugin-auto-import/vite'
import Components from 'unplugin-vue-components/vite'
import { ElementPlusResolver } from 'unplugin-vue-components/resolvers'
import { mkdtemp } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
const root = fileURLToPath(new URL('../../../', import.meta.url))
const outDir = await mkdtemp(join(tmpdir(), 'xboard-node-updates-'))
await build({
 root, configFile: false, envFile: false, envDir: false, base: '/assets/admin/',
 resolve: { alias: { '@': fileURLToPath(new URL('../../', import.meta.url)) } },
 plugins: [vue(), AutoImport({ resolvers: [ElementPlusResolver({ importStyle: 'sass' })], imports: ['vue', 'vue-router', 'pinia'], dts: false }), Components({ resolvers: [ElementPlusResolver({ importStyle: 'sass' })], dts: false })],
 build: { outDir, emptyOutDir: true },
})
console.log('VERIFIED_BUILD_DIRECTORY=' + outDir)
