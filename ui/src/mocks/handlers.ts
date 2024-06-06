import { delay, http, HttpResponse } from "msw";
import components from "./fixtures/components.json"
import layoutDefault from "./fixtures/layout-default.json"
import mockPreviewDocument from "./preview";

const DEFAULT_DELAY = 200;

const handlers = [
  http.get('/api/components', async () => {
    await delay(DEFAULT_DELAY);
    return HttpResponse.json(components)
  }),
  http.get('/api/layout/:id', async () => {
    await delay(DEFAULT_DELAY);
    return HttpResponse.json(layoutDefault)
  }),
  http.post<{}, { layout: any; model: any }>('/api/preview', async ({ request }) => {
    await delay(DEFAULT_DELAY);
    const {layout, model} = await request.json()
    return HttpResponse.json({html: mockPreviewDocument(layout, model)});
  }),
];

export default handlers;
