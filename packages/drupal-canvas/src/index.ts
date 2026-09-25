import {
  drupalSettingsToCanvasContext,
  drupalSettingsToJsonApiRuntimeConfig,
  readCanvasDataV0,
} from './drupal-settings.js';
import {
  getPageData,
  getSiteData,
  sortMenu as sortLinksetMenu,
} from './drupal-utils.js';
import FormattedText from './FormattedText.js';
import {
  createJsonApiClient,
  DraftSessionError,
  isDraftSessionError,
  JsonApiClient,
} from './jsonapi-client.js';
import { getNodePath, sortMenu } from './jsonapi-utils.js';
import {
  AGENT_MIGRATION_PROMPT,
  formatLegacyApiError,
  LEGACY_API_GUIDANCE,
} from './migration.js';
import Image from './next-image-standalone.js';
import { Region, RegionsProvider } from './Region.js';
import {
  declareCanvasRuntime,
  getCanvasRuntime,
  isLegacyRuntimeSupported,
} from './runtime.js';
import { cn } from './utils.js';

export type { CanvasContext, PageContext, SiteContext } from './context.js';
export type {
  BreadcrumbLink,
  EntityMetadata,
  PageData,
  SiteData,
  ThemeAssets,
  TranslationMetadata,
} from './drupal-utils.js';
export type {
  CanvasJsonApiClient,
  JsonApiClientConfig,
  JsonApiRuntimeConfig,
} from './jsonapi-client.js';
export type { CanvasDataV0 } from './drupal-settings.js';
export type { CanvasRuntimeEnvironment } from './runtime.js';

export {
  FormattedText,
  Image,
  Region,
  RegionsProvider,

  // utils
  cn,

  // Runtime integration (Drupal and Workbench integrations only)
  declareCanvasRuntime,
  getCanvasRuntime,
  isLegacyRuntimeSupported,
  readCanvasDataV0,
  drupalSettingsToCanvasContext,
  drupalSettingsToJsonApiRuntimeConfig,
  AGENT_MIGRATION_PROMPT,
  LEGACY_API_GUIDANCE,
  formatLegacyApiError,

  // drupal-utils
  getPageData,
  getSiteData,
  sortLinksetMenu,

  // jsonapi-utils
  getNodePath,
  sortMenu,

  // jsonapi-client
  JsonApiClient,
  createJsonApiClient,
  DraftSessionError,
  isDraftSessionError,
};
