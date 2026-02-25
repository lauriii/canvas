import type { DiscoveryResult } from '@drupal-canvas/discovery';

export type {
  DiscoveredComponent,
  DiscoveredPage,
  DiscoveryResult,
  DiscoveryWarning,
} from '@drupal-canvas/discovery';

export async function fetchDiscoveryResult(): Promise<DiscoveryResult> {
  const response = await fetch('/__canvas/discovery');

  if (!response.ok) {
    throw new Error(`Discovery request failed with status ${response.status}.`);
  }

  const data = (await response.json()) as DiscoveryResult;
  return data;
}
