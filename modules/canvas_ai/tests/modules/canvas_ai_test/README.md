# Drupal Canvas AI test

Answers every request under `/admin/api/canvas/ai` from a fixture, so browser
tests can drive the AI chat without an AI provider. The response is picked from
the last user message, slugified: "What is a CMS?" is answered from
`fixtures/what_is_a_cms.json`.

## Fixtures for a turn that hops

The dev chat (`canvas_dev_ai`) runs one turn as several requests: the agent
pauses after each tool decision and the browser re-POSTs under the same
`request_id` until a response reports `should_continue: false`. Because every
hop sends the same messages, the slug alone cannot vary the answer.

Add one file per hop to answer such a turn:

- `<slug>.json` — the first hop
- `<slug>-2.json` — the second hop
- `<slug>-3.json` — the third, and so on

Hops with no numbered file fall back to `<slug>.json`, which is what
single-request turns rely on.

Keep `should_continue: true` in every hop but the last, and give the last one
the `message` the chat renders as the answer. A turn whose final hop is missing
never terminates: the loop falls back to `<slug>.json` and keeps hopping.

@see \Drupal\canvas_ai_test\EventSubscriber\CanvasAiRequestInterceptor
@see tests/src/Playwright/tests/isolatedPerTest/aiDev.spec.ts
