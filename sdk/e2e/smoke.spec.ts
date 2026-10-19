/**
 * Smoke test of the script-tag build in a real browser (Chromium and WebKit), with the mock
 * transport and a stubbed service (`page.route`): connect, a scripted avatar line, the transcript
 * forwarded to /events in the documented batch shape with stable idempotency keys, and the end call.
 */
import { expect, test } from '@playwright/test'

const CONVERSATION = '**/v1/client/conversations/conv_smoke'
const CLIENT_SECRET = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJjb252X3Ntb2tlIn0.signature'
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/

interface Batch {
  events: Array<Record<string, unknown>>
}

test('connects, shows a scripted line, forwards the transcript with stable keys, and ends', async ({
  page,
}) => {
  const batches: Batch[] = []
  let connectHeaders: Record<string, string> = {}
  let ends = 0

  await page.route(`${CONVERSATION}/connect`, (route) => {
    connectHeaders = route.request().headers()
    return route.fulfill({ json: { provider: 'mock', transport: 'mock' } })
  })
  await page.route(`${CONVERSATION}/events`, (route) => {
    batches.push(route.request().postDataJSON() as Batch)
    // The first delivery fails, so the retry proves the idempotency keys are stable.
    return batches.length === 1
      ? route.fulfill({ status: 503, json: { code: 'service_unavailable', detail: 'Try again.' } })
      : route.fulfill({ json: { accepted: 2, duplicates: 0 } })
  })
  await page.route(`${CONVERSATION}/end`, (route) => {
    ends += 1
    return route.fulfill({
      json: { id: 'conv_smoke', status: 'ended', ended_reason: 'client' },
    })
  })

  await page.goto('/')

  await expect(page.locator('#state')).toHaveText('live')
  await expect(page.locator('#transcript li')).toHaveText(['Hello from the mock avatar'])
  await expect(page.locator('#mount video')).toHaveCount(1)
  expect(connectHeaders['x-hra-publishable-key']).toBe('hra_pk_test_smoke')
  expect(connectHeaders['authorization']).toBe(`Bearer ${CLIENT_SECRET}`)

  // Batched by time (2 s), failed once, retried after 1 s: the same events, the same keys.
  await expect.poll(() => batches.length, { timeout: 15_000 }).toBeGreaterThanOrEqual(2)
  const [failed, delivered] = batches as [Batch, Batch]
  const keys = (batch: Batch) => batch.events.map((event) => event.idempotency_key)
  expect(keys(delivered)).toEqual(keys(failed))
  expect(new Set(keys(delivered)).size).toBe(2)
  for (const key of keys(delivered)) expect(key).toMatch(UUID)
  expect(delivered.events.map((event) => event.type)).toEqual(['lifecycle', 'utterance'])
  expect(delivered.events[0]).toMatchObject({ type: 'lifecycle', name: 'live' })
  expect(delivered.events[1]).toMatchObject({
    type: 'utterance',
    role: 'avatar',
    text: 'Hello from the mock avatar',
  })
  for (const event of delivered.events) {
    expect(new Date(event.occurred_at as string).toISOString()).toBe(event.occurred_at)
  }

  await page.locator('#end').click()

  await expect(page.locator('#state')).toHaveText('ended')
  expect(ends).toBe(1)
})
