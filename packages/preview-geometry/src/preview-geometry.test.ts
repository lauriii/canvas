// @vitest-environment jsdom

import { afterEach, describe, expect, it, vi } from 'vitest';

import {
  createCanvasGeometryObserver,
  discoverCanvasBoundaries,
  formatCanvasCommentMarker,
  getCanvasStackDirection,
  getCanvasTemplateMarkerAttributes,
  isCanvasGeometrySnapshot,
  measureCanvasBoundary,
  parseCanvasCommentMarker,
  unionCanvasRects,
} from './index';

import type { CanvasMarker } from './index';

afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe('preview marker grammar', () => {
  const commentMarkers = [
    {
      marker: { type: 'component', position: 'start', id: 'card' },
      value: 'canvas-start-card',
    },
    {
      marker: { type: 'component', position: 'end', id: 'card' },
      value: 'canvas-end-card',
    },
    {
      marker: { type: 'slot', position: 'start', id: 'card/body' },
      value: 'canvas-slot-start-card/body',
    },
    {
      marker: { type: 'slot', position: 'end', id: 'card/body' },
      value: 'canvas-slot-end-card/body',
    },
    {
      marker: { type: 'region', position: 'start', id: 'content' },
      value: 'canvas-region-start-content',
    },
    {
      marker: { type: 'region', position: 'end', id: 'content' },
      value: 'canvas-region-end-content',
    },
  ] satisfies Array<{ marker: CanvasMarker; value: string }>;

  it.each(commentMarkers)('formats and parses $value', ({ marker, value }) => {
    expect(formatCanvasCommentMarker(marker)).toBe(value);
    expect(parseCanvasCommentMarker(` ${value} `)).toEqual(marker);
  });

  it('formats template marker attributes', () => {
    expect(
      getCanvasTemplateMarkerAttributes({
        type: 'component',
        position: 'start',
        id: 'card',
      }),
    ).toEqual({
      'data-canvas-marker': 'start',
      'data-canvas-type': 'component',
      'data-canvas-uuid': 'card',
    });
    expect(
      getCanvasTemplateMarkerAttributes({
        type: 'slot',
        position: 'end',
        id: 'card/body',
      }),
    ).toEqual({
      'data-canvas-marker': 'end',
      'data-canvas-type': 'slot',
      'data-canvas-slot-name': 'body',
      'data-canvas-uuid': 'card/body',
    });
    expect(
      getCanvasTemplateMarkerAttributes({
        type: 'region',
        position: 'start',
        id: 'content',
      }),
    ).toEqual({
      'data-canvas-marker': 'start',
      'data-canvas-type': 'region',
      'data-canvas-region-id': 'content',
    });
  });
});

describe('preview marker discovery', () => {
  it('normalizes comment and template marker pairs', () => {
    document.body.innerHTML = `
      <!-- canvas-start-component-one -->
      <section>
        <!-- canvas-slot-start-component-one/body -->
        <p>Body</p>
        <!-- canvas-slot-end-component-one/body -->
      </section>
      <!-- canvas-end-component-one -->
      <template
        data-canvas-marker="start"
        data-canvas-type="component"
        data-canvas-uuid="component-two"
      ></template>
      <article>Card</article>
      <template
        data-canvas-marker="end"
        data-canvas-type="component"
        data-canvas-uuid="component-two"
      ></template>
    `;

    expect(
      discoverCanvasBoundaries(document).map(
        ({ type, id, markerFormat, componentUuid, slotName }) => ({
          type,
          id,
          markerFormat,
          componentUuid,
          slotName,
        }),
      ),
    ).toEqual([
      {
        type: 'component',
        id: 'component-one',
        markerFormat: 'comment',
        componentUuid: 'component-one',
        slotName: undefined,
      },
      {
        type: 'slot',
        id: 'component-one/body',
        markerFormat: 'comment',
        componentUuid: 'component-one',
        slotName: 'body',
      },
      {
        type: 'component',
        id: 'component-two',
        markerFormat: 'template',
        componentUuid: 'component-two',
        slotName: undefined,
      },
    ]);
  });

  it('ignores unmatched and unidentified markers', () => {
    document.body.innerHTML = `
      <!-- canvas-start-unmatched -->
      <template
        data-canvas-marker="start"
        data-canvas-type="component"
      ></template>
      <template
        data-canvas-marker="end"
        data-canvas-type="component"
      ></template>
    `;

    expect(discoverCanvasBoundaries(document)).toEqual([]);
  });
});

describe('preview geometry measurement', () => {
  it('unions disjoint rectangles', () => {
    expect(
      unionCanvasRects([
        domRect(10, 20, 40, 30),
        domRect(80, 5, 20, 10),
        domRect(0, 0, 0, 0),
      ]),
    ).toEqual({
      top: 5,
      right: 100,
      bottom: 50,
      left: 10,
      width: 90,
      height: 45,
    });
  });

  it('measures multi-root component output', () => {
    document.body.innerHTML = `
      <template data-canvas-marker="start" data-canvas-type="component" data-canvas-uuid="card"></template>
      <header>Heading</header>
      <main>Body</main>
      <template data-canvas-marker="end" data-canvas-type="component" data-canvas-uuid="card"></template>
    `;
    const [header, main] = Array.from(
      document.body.querySelectorAll('header, main'),
    );
    mockClientRects(header, [domRect(15, 10, 100, 20)]);
    mockClientRects(main, [domRect(5, 30, 140, 60)]);

    const [boundary] = discoverCanvasBoundaries(document);
    expect(measureCanvasBoundary(boundary)?.rect).toEqual({
      top: 10,
      right: 145,
      bottom: 90,
      left: 5,
      width: 140,
      height: 80,
    });
  });

  it('includes an element box when range fragments cover only its content', () => {
    document.body.innerHTML = `
      <!-- canvas-start-header -->
      <canvas-island style="display: contents">
        <header><span>Brand</span></header>
      </canvas-island>
      <!-- canvas-end-header -->
    `;
    const island = document.querySelector('canvas-island')!;
    const header = document.querySelector('header')!;
    mockClientRects(island, []);
    mockClientRects(header, [domRect(0, 0, 320, 80)]);
    const createRange = document.createRange.bind(document);
    vi.spyOn(document, 'createRange').mockImplementation(() => {
      const range = createRange();
      range.getClientRects = () =>
        [domRect(20, 20, 120, 40)] as unknown as DOMRectList;
      return range;
    });

    const [boundary] = discoverCanvasBoundaries(document);
    expect(measureCanvasBoundary(boundary)?.rect).toEqual({
      top: 0,
      right: 320,
      bottom: 80,
      left: 0,
      width: 320,
      height: 80,
    });
  });

  it('does not expand an element box to include out-of-flow descendant text', () => {
    document.body.innerHTML = `
      <!-- canvas-start-header -->
      <header>
        <span>Brand</span>
        <span class="offscreen-menu">Menu</span>
      </header>
      <!-- canvas-end-header -->
    `;
    const header = document.querySelector('header')!;
    mockClientRects(header, [domRect(0, 0, 320, 80)]);
    const createRange = document.createRange.bind(document);
    vi.spyOn(document, 'createRange').mockImplementation(() => {
      const range = createRange();
      range.getClientRects = () =>
        [
          domRect(20, 20, 120, 40),
          domRect(20, 500, 120, 40),
        ] as unknown as DOMRectList;
      return range;
    });

    const [boundary] = discoverCanvasBoundaries(document);
    expect(measureCanvasBoundary(boundary)?.rect).toEqual({
      top: 0,
      right: 320,
      bottom: 80,
      left: 0,
      width: 320,
      height: 80,
    });
  });

  it('excludes boundary whitespace beside an inline component', () => {
    document.body.innerHTML = `<!-- canvas-start-video-one --><video></video>
<!-- canvas-end-video-one --><!-- canvas-start-video-two --><video></video>
<!-- canvas-end-video-two -->`;
    const [firstVideo] = document.querySelectorAll('video');
    mockClientRects(firstVideo, [domRect(0, 0, 190, 100)]);
    const createRange = document.createRange.bind(document);
    vi.spyOn(document, 'createRange').mockImplementation(() => {
      const range = createRange();
      range.getClientRects = () =>
        [domRect(0, 0, 194, 100)] as unknown as DOMRectList;
      return range;
    });

    const [boundary] = discoverCanvasBoundaries(document);
    expect(measureCanvasBoundary(boundary)?.rect).toEqual({
      top: 0,
      right: 190,
      bottom: 100,
      left: 0,
      width: 190,
      height: 100,
    });
  });

  it('measures text output without boundary whitespace', () => {
    document.body.innerHTML = `Outside<!-- canvas-start-label -->
${'  Label  '}
<!-- canvas-end-label -->Outside`;
    const createdRanges: Range[] = [];
    const createRange = document.createRange.bind(document);
    vi.spyOn(document, 'createRange').mockImplementation(() => {
      const range = createRange();
      range.getClientRects = () =>
        [domRect(10, 20, 50, 20)] as unknown as DOMRectList;
      createdRanges.push(range);
      return range;
    });

    const [boundary] = discoverCanvasBoundaries(document);
    expect(measureCanvasBoundary(boundary)?.rect).toEqual({
      top: 20,
      right: 60,
      bottom: 40,
      left: 10,
      width: 50,
      height: 20,
    });
    expect(createdRanges).toHaveLength(2);
    expect(createdRanges[1].startOffset).toBe(3);
    expect(createdRanges[1].endOffset).toBe(8);
  });

  it('does not inspect text outside a boundary', () => {
    document.body.innerHTML =
      'Outside before<!-- canvas-start-label -->Label<!-- canvas-end-label -->Outside after';
    const outsideTextReads = vi.fn(() => 'Outside');
    [document.body.firstChild, document.body.lastChild].forEach((node) => {
      Object.defineProperty(node!, 'nodeValue', {
        configurable: true,
        get: outsideTextReads,
      });
    });
    const createRange = document.createRange.bind(document);
    vi.spyOn(document, 'createRange').mockImplementation(() => {
      const range = createRange();
      range.getClientRects = () =>
        [domRect(10, 20, 50, 20)] as unknown as DOMRectList;
      return range;
    });

    const [boundary] = discoverCanvasBoundaries(document);

    expect(measureCanvasBoundary(boundary)?.rect).toEqual({
      top: 20,
      right: 60,
      bottom: 40,
      left: 10,
      width: 50,
      height: 20,
    });
    expect(outsideTextReads).not.toHaveBeenCalled();
  });

  it('measures an empty slot by its placeholder', () => {
    document.body.innerHTML = `
      <div class="slot" data-canvas-slot-id="card/body">
        <template data-canvas-marker="start" data-canvas-type="slot" data-canvas-uuid="card/body" data-canvas-slot-name="body"></template>
        <div class="canvas--slot-empty-placeholder"></div>
        <template data-canvas-marker="end" data-canvas-type="slot" data-canvas-uuid="card/body" data-canvas-slot-name="body"></template>
      </div>
    `;
    const slot = document.querySelector('.slot')!;
    const placeholder = document.querySelector(
      '.canvas--slot-empty-placeholder',
    )!;
    mockClientRects(slot, [domRect(20, 30, 200, 80)]);
    mockClientRects(placeholder, [domRect(30, 40, 180, 40)]);
    const [boundary] = discoverCanvasBoundaries(document);

    expect(measureCanvasBoundary(boundary)?.rect).toEqual({
      top: 40,
      right: 210,
      bottom: 80,
      left: 30,
      width: 180,
      height: 40,
    });
  });

  it('measures a populated slot by its rendered range', () => {
    document.body.innerHTML = `
      <div data-canvas-slot-id="card/body">
        <!-- canvas-slot-start-card/body -->
        <p>Body</p>
        <!-- canvas-slot-end-card/body -->
      </div>
    `;
    const slot = document.querySelector('div')!;
    const body = document.querySelector('p')!;
    mockClientRects(slot, [domRect(10, 20, 240, 100)]);
    mockClientRects(body, [domRect(30, 40, 180, 40)]);
    const [boundary] = discoverCanvasBoundaries(document);

    expect(measureCanvasBoundary(boundary)?.rect).toEqual({
      top: 40,
      right: 210,
      bottom: 80,
      left: 30,
      width: 180,
      height: 40,
    });
  });

  it('measures an empty region by its placeholder', () => {
    document.body.innerHTML = `
      <main data-canvas-region-id="content">
        <!-- canvas-region-start-content -->
        <div class="canvas--region-empty-placeholder"></div>
        <!-- canvas-region-end-content -->
      </main>
    `;
    const region = document.querySelector('main')!;
    const placeholder = document.querySelector(
      '.canvas--region-empty-placeholder',
    )!;
    mockClientRects(region, [domRect(0, 0, 320, 480)]);
    mockClientRects(placeholder, [domRect(20, 30, 280, 320)]);
    const [boundary] = discoverCanvasBoundaries(document);

    expect(measureCanvasBoundary(boundary)?.rect).toEqual({
      top: 30,
      right: 300,
      bottom: 350,
      left: 20,
      width: 280,
      height: 320,
    });
  });

  it('measures a populated content region by its rendered range', () => {
    document.body.innerHTML = `
      <main data-canvas-region-id="content">
        <!-- canvas-region-start-content -->
        <article>Page content</article>
        <!-- canvas-region-end-content -->
      </main>
    `;
    const region = document.querySelector('main')!;
    const content = document.querySelector('article')!;
    mockClientRects(region, [domRect(0, 0, 320, 480)]);
    mockClientRects(content, [domRect(20, 30, 280, 80)]);
    const [boundary] = discoverCanvasBoundaries(document);

    expect(measureCanvasBoundary(boundary)?.rect).toEqual({
      top: 30,
      right: 300,
      bottom: 110,
      left: 20,
      width: 280,
      height: 80,
    });
  });

  it('detects flex and grid stacking directions', () => {
    const flex = document.createElement('div');
    flex.style.display = 'flex';
    flex.style.flexDirection = 'row';
    const grid = document.createElement('div');
    grid.style.display = 'grid';
    grid.style.gridTemplateColumns = '100px 100px';
    document.body.append(flex, grid);

    expect(getCanvasStackDirection(flex)).toBe('horizontal-flex');
    expect(getCanvasStackDirection(grid)).toBe('horizontal-grid');
  });

  it('batches observed changes and stops after disconnecting', async () => {
    document.body.innerHTML = `
      <!-- canvas-start-card -->
      <article>Card</article>
      <!-- canvas-end-card -->
    `;
    const article = document.querySelector('article')!;
    let rect = domRect(0, 0, 100, 20);
    vi.spyOn(article, 'getClientRects').mockImplementation(
      () => [rect] as unknown as DOMRectList,
    );
    Object.defineProperty(window, 'requestAnimationFrame', {
      configurable: true,
      value: (callback: FrameRequestCallback) =>
        window.setTimeout(() => callback(0), 0),
    });
    Object.defineProperty(window, 'cancelAnimationFrame', {
      configurable: true,
      value: (handle: number) => window.clearTimeout(handle),
    });
    const onChange = vi.fn();
    const observer = createCanvasGeometryObserver({
      root: document,
      onChange,
    });

    expect(onChange).toHaveBeenCalledTimes(1);
    rect = domRect(0, 0, 120, 20);
    article.setAttribute('data-updated', 'true');
    await vi.waitFor(() => expect(onChange).toHaveBeenCalledTimes(2));

    observer.disconnect();
    rect = domRect(0, 0, 140, 20);
    article.setAttribute('data-updated', 'again');
    await new Promise((resolve) => window.setTimeout(resolve, 10));
    expect(onChange).toHaveBeenCalledTimes(2);
  });

  it('emits after content changes even when geometry stays unchanged', async () => {
    document.body.innerHTML = `
      <!-- canvas-start-card -->
      <article>Card</article>
      <!-- canvas-end-card -->
    `;
    const article = document.querySelector('article')!;
    mockClientRects(article, [domRect(0, 0, 100, 20)]);
    vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) =>
      window.setTimeout(() => callback(0), 0),
    );
    vi.stubGlobal('cancelAnimationFrame', (handle: number) =>
      window.clearTimeout(handle),
    );
    const onChange = vi.fn();
    const observer = createCanvasGeometryObserver({
      root: document,
      onChange,
    });

    expect(onChange).toHaveBeenCalledTimes(1);
    article.firstChild!.nodeValue = 'Tile';
    await vi.waitFor(() => expect(onChange).toHaveBeenCalledTimes(2));
    expect(onChange.mock.calls[1][0]).toEqual(onChange.mock.calls[0][0]);

    observer.disconnect();
  });

  it('rediscovers resize targets only after child-list changes', async () => {
    document.body.innerHTML = `
      <!-- canvas-start-card -->
      <article>Card</article>
      <!-- canvas-end-card -->
    `;
    const article = document.querySelector('article')!;
    let rect = domRect(0, 0, 100, 20);
    vi.spyOn(article, 'getClientRects').mockImplementation(
      () => [rect] as unknown as DOMRectList,
    );
    const resizeObserverDisconnect = vi.fn();
    vi.stubGlobal(
      'ResizeObserver',
      class {
        observe = vi.fn();
        disconnect = resizeObserverDisconnect;
      },
    );
    vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) =>
      window.setTimeout(() => callback(0), 0),
    );
    vi.stubGlobal('cancelAnimationFrame', (handle: number) =>
      window.clearTimeout(handle),
    );
    const onChange = vi.fn();
    const observer = createCanvasGeometryObserver({
      root: document,
      onChange,
    });

    expect(resizeObserverDisconnect).toHaveBeenCalledTimes(1);
    rect = domRect(0, 0, 120, 20);
    article.setAttribute('data-updated', 'true');
    await vi.waitFor(() => expect(onChange).toHaveBeenCalledTimes(2));
    expect(resizeObserverDisconnect).toHaveBeenCalledTimes(1);

    article.append(document.createElement('span'));
    await vi.waitFor(() =>
      expect(resizeObserverDisconnect).toHaveBeenCalledTimes(2),
    );

    observer.disconnect();
  });

  it('observes the first boxed ancestor of display-contents targets', () => {
    document.body.innerHTML = `
      <main>
        <div class="marker-parent" style="display: contents">
          <!-- canvas-start-card -->
          <article style="display: contents"><span>Card</span></article>
          <!-- canvas-end-card -->
        </div>
      </main>
    `;
    const main = document.querySelector('main')!;
    const markerParent = document.querySelector('.marker-parent')!;
    const article = document.querySelector('article')!;
    mockClientRects(main, [domRect(0, 0, 100, 20)]);
    const observe = vi.fn();
    vi.stubGlobal(
      'ResizeObserver',
      class {
        observe = observe;
        disconnect = vi.fn();
      },
    );

    const observer = createCanvasGeometryObserver({
      root: document,
      onChange: vi.fn(),
    });

    expect(observe).toHaveBeenCalledWith(main);
    expect(observe).not.toHaveBeenCalledWith(markerParent);
    expect(observe).not.toHaveBeenCalledWith(article);

    observer.disconnect();
  });
});

describe('preview geometry validation', () => {
  const validGeometry = {
    type: 'slot',
    id: 'component-one/body',
    markerFormat: 'comment',
    componentUuid: 'component-one',
    slotName: 'body',
    stackDirection: 'vertical-flex',
    rect: {
      top: 10,
      right: 110,
      bottom: 60,
      left: 10,
      width: 100,
      height: 50,
    },
  };

  it('accepts valid serialized geometry', () => {
    expect(isCanvasGeometrySnapshot([validGeometry])).toBe(true);
  });

  it.each([
    ['a non-array value', {}],
    ['a null item', [null]],
    ['a sparse snapshot', new Array(1)],
    ['an unknown boundary type', [{ ...validGeometry, type: 'unknown' }]],
    ['an empty identity', [{ ...validGeometry, id: '' }]],
    ['an oversized identity', [{ ...validGeometry, id: 'x'.repeat(4_097) }]],
    [
      'an unknown marker format',
      [{ ...validGeometry, markerFormat: 'element' }],
    ],
    [
      'a non-finite coordinate',
      [
        {
          ...validGeometry,
          rect: { ...validGeometry.rect, top: Number.POSITIVE_INFINITY },
        },
      ],
    ],
    [
      'an impractically large coordinate',
      [
        {
          ...validGeometry,
          rect: { ...validGeometry.rect, right: 100_000_001 },
        },
      ],
    ],
    [
      'a negative rectangle size',
      [{ ...validGeometry, rect: { ...validGeometry.rect, width: -1 } }],
    ],
    [
      'an unknown stack direction',
      [{ ...validGeometry, stackDirection: 'diagonal' }],
    ],
    ['an oversized snapshot', new Array(20_001).fill(validGeometry)],
  ])('rejects %s', (_description, snapshot) => {
    expect(isCanvasGeometrySnapshot(snapshot)).toBe(false);
  });
});

function domRect(left: number, top: number, width: number, height: number) {
  return {
    x: left,
    y: top,
    top,
    right: left + width,
    bottom: top + height,
    left,
    width,
    height,
    toJSON: () => ({}),
  } as DOMRect;
}

function mockClientRects(element: Element, rects: DOMRect[]) {
  vi.spyOn(element, 'getClientRects').mockReturnValue(
    rects as unknown as DOMRectList,
  );
}
