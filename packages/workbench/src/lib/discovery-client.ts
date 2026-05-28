import type { DiscoveredPage, DiscoveryResult } from '@drupal-canvas/discovery';

export type {
  DiscoveredComponent,
  DiscoveredContentTemplate,
  DiscoveredPage,
  DiscoveredRegion,
  DiscoveryResult,
  DiscoveryWarning,
} from '@drupal-canvas/discovery';

export type EnrichedDiscoveredPage = DiscoveredPage & {
  pagePath: string | null;
};

export type EnrichedDiscoveryResult = Omit<DiscoveryResult, 'pages'> & {
  pages: EnrichedDiscoveredPage[];
  /**
   * Absolute filesystem path to the user's optional layout component, or
   * null when no layout file exists. Sent as a Vite `/@fs/` URL to the iframe.
   */
  layoutPath: string | null;
};

export async function fetchDiscoveryResult(): Promise<EnrichedDiscoveryResult> {
  const response = await fetch('/__canvas/discovery');

  if (!response.ok) {
    throw new Error(`Discovery request failed with status ${response.status}.`);
  }

  const data = (await response.json()) as EnrichedDiscoveryResult;
  return data;
}
