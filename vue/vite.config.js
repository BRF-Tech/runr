import {defineConfig} from 'vite'
import vue from '@vitejs/plugin-vue'
import {fileURLToPath, URL} from 'node:url'

export default defineConfig({
    plugins: [vue()],
    build: {
        target: 'esnext',
    },
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./src', import.meta.url)),
        },
    },
    server: {
        allowedHosts: ['runr.test','.runr.test'],
        port: 5173,
        proxy: {
            '/api': {
                target: process.env.VITE_API_PROXY_TARGET || 'https://runr.test',
                changeOrigin: true,
            },
            '/storage': {
                target: process.env.VITE_API_PROXY_TARGET || 'https://runr.test',
                changeOrigin: true,
            },
            // AI agents (docs/mcp.md): MCP server + hosted servers, OAuth endpoints and metadata.
            // Regex keys: the SPA's own routes (/oauth-consent) stay with Vite.
            '^/mcp(/|$)': {
                target: process.env.VITE_API_PROXY_TARGET || 'https://runr.test',
                changeOrigin: true,
            },
            '^/oauth2/': {
                target: process.env.VITE_API_PROXY_TARGET || 'https://runr.test',
                changeOrigin: true,
            },
            '^/\\.well-known/oauth-': {
                target: process.env.VITE_API_PROXY_TARGET || 'https://runr.test',
                changeOrigin: true,
            },
            // Project git remotes (smart HTTP): git push http://localhost:8061/git/<org>/<project>.git
            '/git/': {
                target: process.env.VITE_API_PROXY_TARGET || 'https://runr.test',
                changeOrigin: true,
            },
            '/ws': {
                target: process.env.VITE_REVERB_TARGET || 'http://reverb:8080',
                changeOrigin: true,
                ws: true,
                rewrite: (path) => path.replace(/^\/ws/, ''),
            },
            '/sentry-tunnel': {
                target: process.env.VITE_SENTRY_TUNNEL_TARGET || 'http://localhost:8067',
                changeOrigin: true,
                rewrite: (path) => path.replace(/^\/sentry-tunnel/, ''),
            },
        },
    },
})
