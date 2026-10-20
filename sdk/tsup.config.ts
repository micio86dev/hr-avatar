import { defineConfig } from 'tsup'
import { peerDependencies } from './package.json'

// The optional provider libraries are the package's `peerDependencies`. tsup leaves them external
// in ESM and CJS but skips that for an IIFE (a script has no resolver) and inlines them, so the IIFE
// hands esbuild the list itself: they stay lazy `import()` calls in every format. A page that uses
// the IIFE passes `loaders` (for example a CDN import) when it needs a provider.
//
// `dist` is cleaned by the `build` script, not by `clean`: the two configs build in parallel and
// each would delete what the other wrote.
export default defineConfig([
  {
    entry: ['src/index.ts', 'src/mock.ts'],
    format: ['esm', 'cjs'],
    dts: true,
    minify: true,
    // One self-contained file per entry, so the size gate measures what an app actually loads.
    splitting: false,
  },
  {
    entry: { 'hr-avatar': 'src/global.ts' },
    format: ['iife'],
    globalName: 'HrAvatar',
    minify: true,
    esbuildOptions(options) {
      options.external = Object.keys(peerDependencies)
    },
  },
])
