import { defineConfig } from 'tsup'

// The IIFE build is added with the size budget (slice 39). tsup never bundles `peerDependencies`,
// so the optional provider libraries stay external and are only loaded lazily by the transports.
export default defineConfig({
  entry: ['src/index.ts'],
  format: ['esm', 'cjs'],
  dts: true,
  clean: true,
})
