import type { DiscoveredPage, DiscoveryResult } from '@drupal-canvas/discovery';

export type {
  DiscoveredComponent,
  DiscoveredPage,
  DiscoveryResult,
  DiscoveryWarning,
} from '@drupal-canvas/discovery';

export type EnrichedDiscoveredPage = DiscoveredPage & {
  pagePath: string | null;
};

export type EnrichedDiscoveryResult = Omit<DiscoveryResult, 'pages'> & {
  pages: EnrichedDiscoveredPage[];
};

export async function fetchDiscoveryResult(): Promise<EnrichedDiscoveryResult> {
  const response = await fetch('/__canvas/discovery');

  if (!response.ok) {
    throw new Error(`Discovery request failed with status ${response.status}.`);
  }

  const data = (await response.json()) as EnrichedDiscoveryResult;
  return data;
}
