import {
  getPageData,
  getSiteData,
  sortMenu as sortLinksetMenu,
} from './drupal-utils.js';
import FormattedText from './FormattedText.js';
import { JsonApiClient } from './jsonapi-client.js';
import { getNodePath, sortMenu } from './jsonapi-utils.js';
import Image from './next-image-standalone.js';
import { cn } from './utils.js';

// When importing third-party dependencies, evaluate whether to add them to
// `noExternal` in `tsdown.config.ts`.
// @see docs/adr/0007-drupal-canvas-no-external-bundling.md

export {
  FormattedText,
  Image,

  // utils
  cn,

  // drupal-utils
  getPageData,
  getSiteData,
  sortLinksetMenu,

  // jsonapi-utils
  getNodePath,
  sortMenu,

  // jsonapi-client
  JsonApiClient,
};
