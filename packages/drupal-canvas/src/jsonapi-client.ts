// cspell:ignore Jsona jsona

import { Jsona } from 'jsona';
import { type BaseUrl } from '@drupal-api-client/api-client';
import { JsonApiClient } from '@drupal-api-client/json-api-client';

import type { JsonApiClientOptions } from '@drupal-api-client/json-api-client';

class CanvasJsonApiClient extends JsonApiClient {
  constructor(baseUrl?: BaseUrl, options?: JsonApiClientOptions) {
    if (window.drupalSettings?.canvasData?.v0?.jsonapiSettings === null) {
      throw new Error(
        'The JSON:API module is not installed. Please install it to use JsonApiClient.',
      );
    }

    const clientBaseUrl =
      baseUrl || window.drupalSettings?.canvasData?.v0?.baseUrl;

    if (!baseUrl && !clientBaseUrl) {
      throw new Error(
        "Could not determine your site's base URL for the JSON:API client. " +
          'If working outside of Drupal Canvas, you can use the @drupal-canvas/vite-plugin to automatically configure it for you. ' +
          'Otherwise you must explicitly provide a base URL, i.e. `const client = new JsonApiClient("https://...")`',
      );
    }

    const clientOptions = {
      apiPrefix:
        window.drupalSettings?.canvasData?.v0?.jsonapiSettings?.apiPrefix,
      serializer: new Jsona(),
      ...options,
    };
    super(clientBaseUrl, clientOptions);
  }
}

export * from '@drupal-api-client/json-api-client';
export { CanvasJsonApiClient as JsonApiClient };
