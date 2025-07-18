/* cspell:ignore Jsona jsona */
import {
  JsonApiClient,
  type JsonApiClientOptions,
} from '@drupal-api-client/json-api-client';
import { type BaseUrl } from '@drupal-api-client/api-client';
import { Jsona } from 'jsona';

class XbJsonApiClient extends JsonApiClient {
  constructor(baseUrl?: BaseUrl, options?: JsonApiClientOptions) {
    if (window.drupalSettings?.xbData?.v0?.jsonapiSettings === null) {
      throw new Error(
        'The JSON:API module is not installed. Please install it to use @drupal-api-client/json-api-client.',
      );
    }

    const clientBaseUrl = baseUrl || window.drupalSettings?.xbData?.v0?.baseUrl;
    const clientOptions = {
      apiPrefix: window.drupalSettings?.xbData?.v0?.jsonapiSettings?.apiPrefix,
      serializer: new Jsona(),
      ...options,
    };
    super(clientBaseUrl, clientOptions);
  }
}

export * from '@drupal-api-client/json-api-client';
export { XbJsonApiClient as JsonApiClient };
