import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import AutoImport from 'unplugin-auto-import/vite'
import Components from 'unplugin-vue-components/vite'
import { ElementPlusResolver } from 'unplugin-vue-components/resolvers'
import { fileURLToPath, URL } from 'node:url'

const backendTarget = 'https://jc-kzmb.ikuncdn.com'
const uploadTarget = 'https://pic.535888.xyz'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const uploadAuthToken = env.DEV_UPLOAD_AUTH_TOKEN || ''
  const buildOutDir = process.env.ADMIN_BUILD_OUT_DIR || env.ADMIN_BUILD_OUT_DIR || '../public/assets/admin'

  return {
  base: '/assets/admin/',
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  plugins: [
    vue(),
    AutoImport({
      resolvers: [ElementPlusResolver({ importStyle: 'sass' })],
      imports: ['vue', 'vue-router', 'pinia'],
      dts: 'src/types/auto-imports.d.ts',
    }),
    Components({
      resolvers: [ElementPlusResolver({ importStyle: 'sass' })],
      dts: 'src/types/components.d.ts',
    }),
  ],
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: backendTarget,
        changeOrigin: true,
      },
      '/upload': {
        target: uploadTarget,
        changeOrigin: true,
        rewrite: (path) => path.replace(/^\/upload/, ''),
        headers: uploadAuthToken
          ? {
              Authorization: uploadAuthToken,
            }
          : undefined,
      },
    },
  },
  build: {
    outDir: buildOutDir,
    emptyOutDir: true,
  },
  }
})
