import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: {
    environment: 'jsdom',
    include: ['tests/unit/**/*.spec.ts'],
    coverage: {
      provider: 'v8',
      include: ['src/**/*.ts'],
      thresholds: { lines: 90, branches: 90, functions: 90 },
    },
  },
})
