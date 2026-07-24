import { getClassNameString, isVhMeasurementCandidate } from './vh-detection';

/** Matches the inline pin Canvas applies once a viewport-relative height settles. */
export const STABLE_HEIGHT_ATTRIBUTE = 'data-canvas-preview-max-height';

const DEFAULT_PROBE_MULTIPLIERS = [3, 8];

export interface VhSignatureCacheEntry {
  maxHeight: number;
  shouldCapMaxHeight: boolean;
}

interface StyleSnapshot {
  element: HTMLElement;
  height: string;
  heightPriority: string;
  minHeight: string;
  minHeightPriority: string;
  maxHeight: string;
  maxHeightPriority: string;
  stableHeight: string | null;
}

interface StableHeightCandidate {
  element: HTMLElement;
  signature: string;
}

export interface ViewportHeightProbeController {
  setViewportHeight: (height: number) => Promise<void> | void;
  restoreViewportHeight: () => Promise<void> | void;
}

export interface StabilizeViewportHeightsOptions {
  roots: HTMLElement[];
  effectiveViewportHeight: number;
  baseViewportHeight?: number;
  probeController?: ViewportHeightProbeController;
  probeMultipliers?: number[];
  /**
   * Gates pinning of newly-confirmed elements. Elements already pinned on a
   * prior call are still re-applied from cache regardless. Callers use this
   * to avoid pinning while there's no swapped-in preview iframe to pin for.
   */
  shouldPinNewElements?: () => boolean;
}

export interface StabilizeViewportHeightsResult {
  pinnedElements: Set<HTMLElement>;
  didProbe: boolean;
}

function snapshotStyles(elements: HTMLElement[]): StyleSnapshot[] {
  const snapshots = elements.map((element) => ({
    element,
    height: element.style.getPropertyValue('height'),
    heightPriority: element.style.getPropertyPriority('height'),
    minHeight: element.style.getPropertyValue('min-height'),
    minHeightPriority: element.style.getPropertyPriority('min-height'),
    maxHeight: element.style.getPropertyValue('max-height'),
    maxHeightPriority: element.style.getPropertyPriority('max-height'),
    stableHeight: element.getAttribute(STABLE_HEIGHT_ATTRIBUTE),
  }));

  return snapshots;
}

function resetRootHeights(elements: HTMLElement[]): void {
  for (const element of elements) {
    element.style.setProperty('height', 'auto', 'important');
    element.style.setProperty('min-height', '0px', 'important');
  }
}

function restore(snapshots: StyleSnapshot[]): void {
  for (const {
    element,
    height,
    heightPriority,
    minHeight,
    minHeightPriority,
    maxHeight,
    maxHeightPriority,
    stableHeight,
  } of snapshots) {
    if (height) {
      element.style.setProperty('height', height, heightPriority);
    } else {
      element.style.removeProperty('height');
    }

    if (minHeight) {
      element.style.setProperty('min-height', minHeight, minHeightPriority);
    } else {
      element.style.removeProperty('min-height');
    }

    if (maxHeight) {
      element.style.setProperty('max-height', maxHeight, maxHeightPriority);
    } else {
      element.style.removeProperty('max-height');
    }

    if (stableHeight === null) {
      element.removeAttribute(STABLE_HEIGHT_ATTRIBUTE);
    } else {
      element.setAttribute(STABLE_HEIGHT_ATTRIBUTE, stableHeight);
    }
  }
}

export function getElementSignature(element: HTMLElement): string {
  return [
    element.tagName,
    element.id,
    element.getAttribute('data-div') ?? '',
    element.getAttribute('data-testid') ?? '',
    getClassNameString(element),
    element.getAttribute('style') ?? '',
  ].join('|');
}

export function collectElementsUnderRoots(roots: HTMLElement[]): HTMLElement[] {
  const elements: HTMLElement[] = [];
  const seen = new Set<HTMLElement>();

  for (const root of roots) {
    if (root.nodeType !== Node.ELEMENT_NODE || seen.has(root)) {
      continue;
    }
    seen.add(root);
    elements.push(root);

    root.querySelectorAll<HTMLElement>('*').forEach((element) => {
      if (!seen.has(element)) {
        seen.add(element);
        elements.push(element);
      }
    });
  }

  return elements;
}

function isHeightExplicitlyConstrained(element: HTMLElement): boolean {
  const baseline = element.clientHeight;
  const originalStyle = element.getAttribute('style');

  element.style.setProperty('height', 'auto', 'important');
  void element.offsetHeight;

  const withAutoHeight = element.clientHeight;

  if (originalStyle === null) {
    element.removeAttribute('style');
  } else {
    element.setAttribute('style', originalStyle);
  }
  void element.offsetHeight;

  return Math.abs(withAutoHeight - baseline) > 2;
}

export function usesViewportHeightProperty(
  element: HTMLElement,
  _effectiveViewportHeight?: number,
): boolean {
  return isHeightExplicitlyConstrained(element);
}

export function applyStableHeight(
  element: HTMLElement,
  entry: VhSignatureCacheEntry,
): void {
  const pixelHeight = `${entry.maxHeight}px`;
  element.style.setProperty('min-height', pixelHeight, 'important');
  const naturalHeight = element.clientHeight;

  if (entry.shouldCapMaxHeight) {
    element.style.setProperty('height', pixelHeight, 'important');
    element.style.setProperty('max-height', pixelHeight, 'important');
  } else {
    element.style.removeProperty('height');
    if (naturalHeight > entry.maxHeight + 2) {
      element.style.removeProperty('max-height');
    } else {
      element.style.setProperty('max-height', pixelHeight, 'important');
    }
  }

  element.setAttribute(STABLE_HEIGHT_ATTRIBUTE, `${entry.maxHeight}`);
}

export class StableHeightReader {
  #elementCache = new WeakMap<HTMLElement, VhSignatureCacheEntry>();
  readonly #signatureCache = new Map<string, VhSignatureCacheEntry>();
  readonly #pinSnapshots = new Map<HTMLElement, StyleSnapshot>();

  clear(): void {
    restore([...this.#pinSnapshots.values()]);
    this.#elementCache = new WeakMap<HTMLElement, VhSignatureCacheEntry>();
    this.#signatureCache.clear();
    this.#pinSnapshots.clear();
  }

  #getCachedEntry(element: HTMLElement): VhSignatureCacheEntry | undefined {
    return (
      this.#elementCache.get(element) ??
      this.#signatureCache.get(getElementSignature(element))
    );
  }

  async stabilize(
    options: StabilizeViewportHeightsOptions,
  ): Promise<StabilizeViewportHeightsResult> {
    const { roots, effectiveViewportHeight } = options;
    if (roots.length === 0) {
      return { pinnedElements: new Set(), didProbe: false };
    }

    const pinnedElements = new Set<HTMLElement>();
    const candidates: StableHeightCandidate[] = [];

    for (const element of collectElementsUnderRoots(roots)) {
      const signature = getElementSignature(element);
      const cached = this.#getCachedEntry(element);

      if (cached) {
        applyStableHeight(element, cached);
        pinnedElements.add(element);
        continue;
      }

      if (isVhMeasurementCandidate(element, effectiveViewportHeight)) {
        candidates.push({ element, signature });
      }
    }

    if (candidates.length === 0) {
      return { pinnedElements, didProbe: false };
    }

    const baseViewportHeight =
      options.baseViewportHeight ?? effectiveViewportHeight;
    const canPinNewElements = options.shouldPinNewElements?.() ?? true;

    if (options.probeController && baseViewportHeight > 0) {
      try {
        const confirmed = await this.#confirmByProbe(
          candidates,
          baseViewportHeight,
          options.probeController,
          options.probeMultipliers ?? DEFAULT_PROBE_MULTIPLIERS,
        );

        if (canPinNewElements) {
          for (const [candidate, maxHeight] of confirmed) {
            this.#pinElement(candidate, maxHeight, pinnedElements);
          }
        }
      } finally {
        await options.probeController.restoreViewportHeight();
      }

      return { pinnedElements, didProbe: true };
    }

    return { pinnedElements, didProbe: false };
  }

  async measureDocumentHeight(
    document: Document,
    options: Omit<
      StabilizeViewportHeightsOptions,
      'roots' | 'effectiveViewportHeight'
    > = {},
  ): Promise<number> {
    const { body, documentElement } = document;
    const effectiveViewportHeight =
      document.defaultView?.innerHeight ?? documentElement.clientHeight;

    const rootElements = [documentElement, body].filter(
      (element): element is HTMLElement => element instanceof HTMLElement,
    );
    await this.stabilize({
      ...options,
      roots: [documentElement],
      effectiveViewportHeight,
    });

    const snapshots = snapshotStyles(rootElements);
    try {
      resetRootHeights(rootElements);
      return documentElement.offsetHeight;
    } finally {
      restore(snapshots);
    }
  }

  async #confirmByProbe(
    candidates: StableHeightCandidate[],
    baseViewportHeight: number,
    probeController: ViewportHeightProbeController,
    probeMultipliers: number[],
  ): Promise<Map<StableHeightCandidate, number>> {
    const inferredHeights = new WeakMap<HTMLElement, number[]>();

    for (const multiplier of probeMultipliers) {
      await probeController.setViewportHeight(baseViewportHeight * multiplier);

      for (const { element } of candidates) {
        if (element.clientHeight <= 10) {
          continue;
        }
        const heights = inferredHeights.get(element) ?? [];
        heights.push(Math.floor(element.clientHeight / multiplier));
        inferredHeights.set(element, heights);
      }
    }

    const confirmed = new Map<StableHeightCandidate, number>();

    for (const candidate of candidates) {
      const heights = inferredHeights.get(candidate.element) ?? [];
      if (
        heights.length === probeMultipliers.length &&
        heights.every((height) => height === heights[0]) &&
        heights[0] > 0
      ) {
        confirmed.set(candidate, heights[0]);
      }
    }

    return confirmed;
  }

  #pinElement(
    candidate: StableHeightCandidate,
    maxHeight: number,
    pinnedElements: Set<HTMLElement>,
  ): void {
    const { element, signature } = candidate;
    const entry = {
      maxHeight,
      shouldCapMaxHeight: usesViewportHeightProperty(element),
    };

    if (!this.#pinSnapshots.has(element)) {
      const [snapshot] = snapshotStyles([element]);
      this.#pinSnapshots.set(element, snapshot);
    }
    applyStableHeight(element, entry);
    this.#elementCache.set(element, entry);
    this.#signatureCache.set(signature, entry);
    pinnedElements.add(element);
  }
}
