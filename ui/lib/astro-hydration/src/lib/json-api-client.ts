/* cspell:ignore Jsona jsona */
import {
  JsonApiClient,
  createCache,
  type JsonApiClientOptions, type GetOptions, type RawApiResponseWithData,
} from '@drupal-api-client/json-api-client';
import { type BaseUrl } from '@drupal-api-client/api-client';
import { Jsona } from "jsona";

class XbJsonApiClient extends JsonApiClient {
  constructor(baseUrl?: BaseUrl, options?: JsonApiClientOptions) {
    const clientOptions = {
      serializer: new Jsona(),
      cache: createCache(),
      ...options,
    };
    try {
      const drupalWindow = parent.window as any;
      let autoBaseUrl =
        drupalWindow.location.origin + drupalWindow.drupalSettings.path.baseUrl;
      super(autoBaseUrl, clientOptions);
    } catch (error) {
      if (!baseUrl) {
        console.error(error);
        throw new Error(
          'Unable to autodetect baseUrl, please explicitly provide one.',
        );
      } else {
        super(baseUrl, clientOptions);
      }
    }
  }
}

export * from "@drupal-api-client/json-api-client";
export { XbJsonApiClient as JsonApiClient };
