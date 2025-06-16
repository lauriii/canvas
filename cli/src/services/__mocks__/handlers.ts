import { http, HttpResponse } from 'msw';

export const handlers = [
  http.post('*/oauth/token', async ({ request }) => {
    const body = await request.text();
    const params = new URLSearchParams(body);

    if (
      params.get('client_id') === 'invalid' ||
      params.get('client_secret') === 'invalid'
    ) {
      return HttpResponse.json({}, { status: 401 });
    }

    if (params.get('scope') === 'xb:this-scope-is-invalid') {
      return HttpResponse.json(
        {
          error: 'invalid_scope',
          error_description:
            'The requested scope is invalid, unknown, or malformed',
          hint: 'Check the `xb:invalid` scope',
        },
        { status: 400 },
      );
    }

    if (params.get('scope') === 'xb:this-scope-is-valid-but-no-permission') {
      return HttpResponse.json({
        access_token: 'test-access-token-no-permission',
      });
    }

    return HttpResponse.json({ access_token: 'test-access-token' });
  }),

  http.get('*/xb/api/v0/config/js_component', async ({ request }) => {
    if (
      request.headers.get('Authorization') ===
      'Bearer test-access-token-no-permission'
    ) {
      return HttpResponse.json({}, { status: 403 });
    }

    return HttpResponse.json({});
  }),
];
