// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';

import { STABLE_HEIGHT_ATTRIBUTE, StableHeightReader } from './stable-height';

type HeightMode = 'fixed' | 'viewport';

function installHeightHarness(options: {
  element: HTMLElement;
  mode: HeightMode;
  fixedHeight?: number;
  viewportRatio?: number;
  initialViewportHeight: number;
}) {
  const {
    element,
    mode,
    fixedHeight = 0,
    viewportRatio = 1,
    initialViewportHeight,
  } = options;

  let viewportHeight = initialViewportHeight;

  vi.spyOn(window, 'innerHeight', 'get').mockImplementation(
    () => viewportHeight,
  );
  vi.spyOn(document.documentElement, 'clientHeight', 'get').mockImplementation(
    () => viewportHeight,
  );
  vi.spyOn(document.documentElement, 'offsetHeight', 'get').mockImplementation(
    () => element.clientHeight,
  );

  Object.defineProperty(element, 'clientHeight', {
    configurable: true,
    get: () => {
      if (element.style.height === 'auto') {
        return 0;
      }

      const pinnedHeight = /^\d+px$/.test(element.style.height)
        ? parseInt(element.style.height, 10)
        : NaN;
      if (Number.isFinite(pinnedHeight)) {
        return pinnedHeight;
      }

      if (mode === 'fixed') {
        return fixedHeight;
      }

      return Math.round(viewportHeight * viewportRatio);
    },
  });

  return {
    setViewportHeight: (nextViewportHeight: number) => {
      viewportHeight = nextViewportHeight;
    },
    getViewportHeight: () => viewportHeight,
  };
}

afterEach(() => {
  document.body.innerHTML = '';
  vi.restoreAllMocks();
});

describe('StableHeightReader', () => {
  it('confirms and pins viewport-relative heights through shared probe logic', async () => {
    const section = document.createElement('section');
    section.style.height = '150vh';
    document.body.appendChild(section);

    const harness = installHeightHarness({
      element: section,
      mode: 'viewport',
      viewportRatio: 1.5,
      initialViewportHeight: 500,
    });
    const reader = new StableHeightReader();

    const result = await reader.stabilize({
      roots: [document.documentElement],
      effectiveViewportHeight: 500,
      baseViewportHeight: 500,
      probeMultipliers: [3, 8],
      probeController: {
        setViewportHeight: harness.setViewportHeight,
        restoreViewportHeight: () => harness.setViewportHeight(500),
      },
    });

    expect(result.didProbe).toBe(true);
    expect(result.pinnedElements.has(section)).toBe(true);
    expect(harness.getViewportHeight()).toBe(500);
    expect(section.getAttribute(STABLE_HEIGHT_ATTRIBUTE)).toBe('750');
    expect(section.style.minHeight).toBe('750px');
    expect(section.style.height).toBe('750px');
    expect(section.style.maxHeight).toBe('750px');
  });

  it('keeps a confirmed viewport-relative height pinned for headless measurement', async () => {
    const section = document.createElement('section');
    section.style.height = '150vh';
    document.body.appendChild(section);

    const harness = installHeightHarness({
      element: section,
      mode: 'viewport',
      viewportRatio: 1.5,
      initialViewportHeight: 500,
    });
    const reader = new StableHeightReader();

    expect(
      await reader.measureDocumentHeight(document, {
        baseViewportHeight: 500,
        probeMultipliers: [3, 8],
        probeController: {
          setViewportHeight: harness.setViewportHeight,
          restoreViewportHeight: () => harness.setViewportHeight(500),
        },
      }),
    ).toBe(750);
    expect(section.getAttribute(STABLE_HEIGHT_ATTRIBUTE)).toBe('750');
    expect(section.style.height).toBe('750px');
    expect(harness.getViewportHeight()).toBe(500);

    harness.setViewportHeight(750);

    expect(await reader.measureDocumentHeight(document)).toBe(750);
    expect(section.style.height).toBe('750px');
  });

  it('does not pin a tall fixed-height element that does not scale with the viewport', async () => {
    const section = document.createElement('section');
    section.style.height = '900px';
    document.body.appendChild(section);

    const harness = installHeightHarness({
      element: section,
      mode: 'fixed',
      fixedHeight: 900,
      initialViewportHeight: 500,
    });
    const reader = new StableHeightReader();

    const result = await reader.stabilize({
      roots: [document.documentElement],
      effectiveViewportHeight: 500,
      baseViewportHeight: 500,
      probeMultipliers: [3, 8],
      probeController: {
        setViewportHeight: harness.setViewportHeight,
        restoreViewportHeight: () => harness.setViewportHeight(500),
      },
    });

    expect(result.didProbe).toBe(true);
    expect(result.pinnedElements).not.toContain(section);
    expect(section.getAttribute(STABLE_HEIGHT_ATTRIBUTE)).toBeNull();
    expect(section.style.height).toBe('900px');
    expect(harness.getViewportHeight()).toBe(500);
  });

  it('pins viewport min-height without clipping taller content', async () => {
    const section = document.createElement('section');
    section.style.minHeight = '150vh';
    document.body.appendChild(section);

    let viewportHeight = 500;
    vi.spyOn(window, 'innerHeight', 'get').mockImplementation(
      () => viewportHeight,
    );
    vi.spyOn(
      document.documentElement,
      'clientHeight',
      'get',
    ).mockImplementation(() => viewportHeight);
    vi.spyOn(
      document.documentElement,
      'offsetHeight',
      'get',
    ).mockImplementation(() => section.clientHeight);
    Object.defineProperty(section, 'clientHeight', {
      configurable: true,
      get: () => {
        const pinnedMinHeight = /^\d+px$/.test(section.style.minHeight)
          ? parseInt(section.style.minHeight, 10)
          : viewportHeight * 1.5;
        return Math.max(1000, pinnedMinHeight);
      },
    });

    const reader = new StableHeightReader();
    const height = await reader.measureDocumentHeight(document, {
      baseViewportHeight: 500,
      probeMultipliers: [3, 8],
      probeController: {
        setViewportHeight: (nextViewportHeight) => {
          viewportHeight = nextViewportHeight;
        },
        restoreViewportHeight: () => {
          viewportHeight = 500;
        },
      },
    });

    expect(height).toBe(1000);
    expect(section.getAttribute(STABLE_HEIGHT_ATTRIBUTE)).toBe('750');
    expect(section.style.minHeight).toBe('750px');
    expect(section.style.height).toBe('');
    expect(section.style.maxHeight).toBe('');
    expect(viewportHeight).toBe(500);
  });

  it('restores pinned styles when its state is cleared', async () => {
    const section = document.createElement('section');
    section.style.height = '150vh';
    document.body.appendChild(section);

    const harness = installHeightHarness({
      element: section,
      mode: 'viewport',
      viewportRatio: 1.5,
      initialViewportHeight: 500,
    });
    const reader = new StableHeightReader();

    await reader.stabilize({
      roots: [document.documentElement],
      effectiveViewportHeight: 500,
      baseViewportHeight: 500,
      probeMultipliers: [3, 8],
      probeController: {
        setViewportHeight: harness.setViewportHeight,
        restoreViewportHeight: () => harness.setViewportHeight(500),
      },
    });
    reader.clear();

    expect(section.getAttribute(STABLE_HEIGHT_ATTRIBUTE)).toBeNull();
    expect(section.style.height).toBe('150vh');
    expect(section.style.minHeight).toBe('');
    expect(section.style.maxHeight).toBe('');
  });

  it('temporarily resets html and body before reading the document height', async () => {
    const section = document.createElement('section');
    section.textContent = 'Tall content';
    document.body.appendChild(section);
    document.body.style.setProperty('height', '100%', 'important');
    document.documentElement.style.setProperty('min-height', '100%');

    vi.spyOn(window, 'innerHeight', 'get').mockReturnValue(500);
    Object.defineProperty(section, 'clientHeight', {
      configurable: true,
      get: () => 1200,
    });
    vi.spyOn(
      document.documentElement,
      'offsetHeight',
      'get',
    ).mockImplementation(() => {
      if (
        document.body.style.getPropertyValue('height') === 'auto' &&
        document.documentElement.style.getPropertyValue('min-height') === '0px'
      ) {
        return 1200;
      }

      return 500;
    });

    const reader = new StableHeightReader();

    expect(await reader.measureDocumentHeight(document)).toBe(1200);
    expect(document.body.style.getPropertyValue('height')).toBe('100%');
    expect(document.body.style.getPropertyPriority('height')).toBe('important');
    expect(document.documentElement.style.getPropertyValue('min-height')).toBe(
      '100%',
    );
  });

  it('re-detects previously tagged stylesheet-driven elements after the reader cache clears', async () => {
    const section = document.createElement('section');
    section.setAttribute(STABLE_HEIGHT_ATTRIBUTE, '500');
    document.body.appendChild(section);

    let viewportHeight = 500;
    vi.spyOn(window, 'innerHeight', 'get').mockImplementation(
      () => viewportHeight,
    );
    vi.spyOn(
      document.documentElement,
      'clientHeight',
      'get',
    ).mockImplementation(() => viewportHeight);
    Object.defineProperty(section, 'clientHeight', {
      configurable: true,
      get: () => viewportHeight,
    });

    const reader = new StableHeightReader();
    reader.clear();

    const result = await reader.stabilize({
      roots: [document.documentElement],
      effectiveViewportHeight: 500,
      baseViewportHeight: 500,
      probeMultipliers: [3, 8],
      probeController: {
        setViewportHeight: (nextViewportHeight) => {
          viewportHeight = nextViewportHeight;
        },
        restoreViewportHeight: () => {
          viewportHeight = 500;
        },
      },
    });

    expect(result.pinnedElements.has(section)).toBe(true);
    expect(section.getAttribute(STABLE_HEIGHT_ATTRIBUTE)).toBe('500');
  });
});
