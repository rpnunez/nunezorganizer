import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig, lazyPlugins } from 'vite-plus';

// Inside the Docker `node` service (VITE_DOCKER=1): listen on all interfaces and tell
// the browser (and public/hot) to use localhost:<VITE_PORT>. No effect elsewhere.
const vitePort = Number(process.env.VITE_PORT || 5173);
const dockerServer = process.env.VITE_DOCKER
    ? {
          host: '0.0.0.0',
          port: vitePort,
          strictPort: true,
          origin: `http://localhost:${vitePort}`,
          hmr: { host: 'localhost', clientPort: vitePort },
      }
    : {};

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                /* @chisel-passkeys */
                'resources/js/passkeys.js',
                /* @end-chisel-passkeys */
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ]),
    server: {
        cors: true,
        ...dockerServer,
        watch: {
            // Bind mounts from Windows/macOS hosts don't deliver file events into containers,
            // so poll there; the ignore list keeps polling to the directories assets come from.
            usePolling: Boolean(process.env.VITE_DOCKER),
            interval: 300,
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/storage/**',
                '**/bootstrap/cache/**',
                '**/vendor/**',
                '**/tests/**',
                '**/database/**',
                '**/deploy/**',
                '**/docs/**',
                '**/docker/**',
            ],
        },
    },
});
