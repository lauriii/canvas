import { indexCanvasGeometry } from '@/features/layout/preview/PreviewGeometryContext';
import { isConsecutive, mapCanvasDocument } from '@/utils/function-utils';

const pageHTML = `<!DOCTYPE html>
<html lang="">
<head>
    <title>Test</title>
</head>
    <body>
        <main role="main">
            <div class="region region--content grid-full layout--pass--content-medium" id="content">
                <div class="block block-system block-system-main-block">
                    <div class="block__content">
                        <div data-canvas-uuid="content" data-canvas-region="content">
                            <!-- canvas-start-fce5e0e3-175f-48b5-a62c-176dbc5f3e91 -->
                            <div data-component-id="canvas_test_sdc:my-hero"
                                 class="my-hero__container">
                                <h1 class="my-hero__heading">
                                    <!-- canvas-prop-start-fce5e0e3-175f-48b5-a62c-176dbc5f3e91/heading -->
                                    There goes my hero
                                    <!-- canvas-prop-end-fce5e0e3-175f-48b5-a62c-176dbc5f3e91/heading --></h1>
                                <p class="my-hero__subheading">
                                    <!-- canvas-prop-start-fce5e0e3-175f-48b5-a62c-176dbc5f3e91/subheading -->
                                    Watch him as he goes!
                                    <!-- canvas-prop-end-fce5e0e3-175f-48b5-a62c-176dbc5f3e91/subheading --></p>
                                <div class="my-hero__actions">
                                    <a href="https://example.com"
                                       class="my-hero__cta my-hero__cta--primary">
                                        <!-- canvas-prop-start-fce5e0e3-175f-48b5-a62c-176dbc5f3e91/cta1 -->
                                        View
                                        <!-- canvas-prop-end-fce5e0e3-175f-48b5-a62c-176dbc5f3e91/cta1 --></a>
                                    <button class="my-hero__cta">
                                        <!-- canvas-prop-start-fce5e0e3-175f-48b5-a62c-176dbc5f3e91/cta2 -->
                                        Click
                                        <!-- canvas-prop-end-fce5e0e3-175f-48b5-a62c-176dbc5f3e91/cta2 --></button>
                                </div>
                            </div>
                            <!-- canvas-end-fce5e0e3-175f-48b5-a62c-176dbc5f3e91 -->
                            <!-- canvas-start-3c88f148-94e2-47c1-b734-24b5017e9e60 --><h2
                                class="my-section__h2">Our Mission</h2>
                            <div class="my-section__wrapper">
                                <div class="my-section__content-wrapper">
                                    <p class="my-section__paragraph">
                                        <!-- canvas-prop-start-3c88f148-94e2-47c1-b734-24b5017e9e60/text -->
                                        Our mission is to deliver the best products and services to
                                        our customers. We strive to exceed expectations and
                                        continuously improve our offerings.
                                        <!-- canvas-prop-end-3c88f148-94e2-47c1-b734-24b5017e9e60/text -->
                                    </p>
                                    <p class="my-section__paragraph">
                                        Join us on our journey to innovation and excellence. Your
                                        satisfaction is our priority.
                                    </p>
                                </div>
                                <div class="my-section__image-wrapper">
                                    <img alt="Placeholder Image" class="my-section__img" width="500"
                                         height="500"
                                         src="/test.png">
                                </div>
                            </div>
                            <!-- canvas-end-3c88f148-94e2-47c1-b734-24b5017e9e60 -->
                            <!-- canvas-start-ad3eff8e-2180-4be1-a60f-df3f2c5ac393 --><div data-component-id="canvas_test_sdc:two_column" data-canvas-uuid="ad3eff8e-2180-4be1-a60f-df3f2c5ac393">
          <div class="column-one width-25">
            <!-- canvas-slot-start-ad3eff8e-2180-4be1-a60f-df3f2c5ac393/column_one --><!-- canvas-start-9bee944d-a92d-42b9-a0ae-abae0080cdfa --><h1 data-component-id="canvas_test_sdc:heading" class="primary" data-canvas-uuid="9bee944d-a92d-42b9-a0ae-abae0080cdfa">A heading element</h1>
<!-- canvas-end-9bee944d-a92d-42b9-a0ae-abae0080cdfa --><!-- canvas-slot-end-ad3eff8e-2180-4be1-a60f-df3f2c5ac393/column_one -->
        </div>

          <div class="column-two width-75">
            <!-- canvas-slot-start-ad3eff8e-2180-4be1-a60f-df3f2c5ac393/column_two --><p>This is column 2 content</p><!-- canvas-slot-end-ad3eff8e-2180-4be1-a60f-df3f2c5ac393/column_two -->
        </div>
    </div>
<!-- canvas-end-ad3eff8e-2180-4be1-a60f-df3f2c5ac393 --><!-- canvas-start-49132256-b0c2-4753-9800-fdc147fafae8 --><div data-component-id="canvas_test_sdc:one_column" class="width-full" data-canvas-uuid="49132256-b0c2-4753-9800-fdc147fafae8">
      <!-- canvas-slot-start-49132256-b0c2-4753-9800-fdc147fafae8/content --><div class="canvas--slot-empty-placeholder"></div><!-- canvas-slot-end-49132256-b0c2-4753-9800-fdc147fafae8/content -->
  </div>
<!-- canvas-end-49132256-b0c2-4753-9800-fdc147fafae8 --></div>
                    </div>
                </div>
            </div>
        </main>
    </body>
</html>`;

describe('mapCanvasDocument', () => {
  it('maps component DOM references in one marker scan', () => {
    const parser = new DOMParser();
    const doc = parser.parseFromString(pageHTML, 'text/html');
    const { componentsMap } = mapCanvasDocument(doc);

    expect(componentsMap).to.deep.equal({
      'fce5e0e3-175f-48b5-a62c-176dbc5f3e91': {
        elements: [doc.querySelector('.my-hero__container')],
      },
      '3c88f148-94e2-47c1-b734-24b5017e9e60': {
        elements: [
          doc.querySelector('.my-section__h2'),
          doc.querySelector('.my-section__wrapper'),
        ],
      },
      'ad3eff8e-2180-4be1-a60f-df3f2c5ac393': {
        elements: [
          doc.querySelector('[data-component-id="canvas_test_sdc:two_column"]'),
        ],
      },
      '9bee944d-a92d-42b9-a0ae-abae0080cdfa': {
        elements: [
          doc.querySelector('[data-component-id="canvas_test_sdc:heading"]'),
        ],
      },
      '49132256-b0c2-4753-9800-fdc147fafae8': {
        elements: [
          doc.querySelector('[data-component-id="canvas_test_sdc:one_column"]'),
        ],
      },
    });
    expect(
      doc.querySelector('.my-hero__container').dataset.canvasUuid,
    ).to.equal('fce5e0e3-175f-48b5-a62c-176dbc5f3e91');
  });

  it('leaves content-region geometry to its boundary markers', () => {
    const parser = new DOMParser();
    const doc = parser.parseFromString(
      `
        <main>
          <!-- canvas-region-start-content -->
          <article>Content</article>
          <!-- canvas-region-end-content -->
        </main>
      `,
      'text/html',
    );

    mapCanvasDocument(doc);

    expect(doc.querySelector('main').dataset.canvasRegionId).to.be.undefined;
  });

  it('leaves slot geometry to its boundary markers', () => {
    const parser = new DOMParser();
    const doc = parser.parseFromString(
      `
        <main>
          <!-- canvas-slot-start-card/first -->
          <p>First slot</p>
          <!-- canvas-slot-end-card/first -->
        </main>
      `,
      'text/html',
    );

    mapCanvasDocument(doc);
    const container = doc.querySelector('main');

    expect(container.dataset.canvasSlotId).to.be.undefined;
  });

  it('supports template markers and ignores incomplete boundaries', () => {
    const parser = new DOMParser();
    const doc = parser.parseFromString(
      `
        <main>
          <template data-canvas-marker="start" data-canvas-type="component" data-canvas-uuid="card"></template>
          <article>Card</article>
          <template data-canvas-marker="end" data-canvas-type="component" data-canvas-uuid="card"></template>
          <template data-canvas-marker="start" data-canvas-type="slot" data-canvas-uuid="card/default"></template>
          <div>Slot</div>
          <template data-canvas-marker="end" data-canvas-type="slot" data-canvas-uuid="card/default"></template>
          <template data-canvas-marker="start" data-canvas-type="component" data-canvas-uuid="incomplete"></template>
        </main>
      `,
      'text/html',
    );

    const { componentsMap } = mapCanvasDocument(doc);

    expect(componentsMap.card.elements).to.deep.equal([
      doc.querySelector('article'),
    ]);
    expect(componentsMap.incomplete).to.be.undefined;
  });
});

describe('indexCanvasGeometry', () => {
  it('keeps equal IDs from different boundary types separate', () => {
    const rect = {
      top: 0,
      right: 10,
      bottom: 10,
      left: 0,
      width: 10,
      height: 10,
    };
    const geometryMap = indexCanvasGeometry([
      {
        type: 'component',
        id: 'content',
        markerFormat: 'comment',
        rect,
      },
      {
        type: 'region',
        id: 'content',
        markerFormat: 'comment',
        rect,
      },
    ]);

    expect(geometryMap.component.content.type).to.equal('component');
    expect(geometryMap.region.content.type).to.equal('region');
  });
});
describe('isConsecutive', () => {
  it('should return true for empty array', () => {
    expect(isConsecutive([])).to.be.true;
  });

  it('should return true for array with single element', () => {
    expect(isConsecutive([5])).to.be.true;
  });

  it('should return true for consecutive numbers in order', () => {
    expect(isConsecutive([1, 2, 3, 4, 5])).to.be.true;
    expect(isConsecutive([10, 11, 12])).to.be.true;
    expect(isConsecutive([-3, -2, -1, 0])).to.be.true;
  });

  it('should return false for non-consecutive numbers', () => {
    expect(isConsecutive([1, 2, 4, 5])).to.be.false;
    expect(isConsecutive([1, 3, 5, 7])).to.be.false;
    expect(isConsecutive([5, 4, 3, 2, 1])).to.be.false; // Reverse order
  });

  it('should return false for numbers with gaps', () => {
    expect(isConsecutive([1, 2, 5])).to.be.false;
    expect(isConsecutive([0, 2, 4])).to.be.false;
    expect(isConsecutive([10, 12, 14])).to.be.false;
  });

  it('should return true for consecutive numbers with any starting point', () => {
    expect(isConsecutive([100, 101, 102, 103])).to.be.true;
    expect(isConsecutive([-10, -9, -8])).to.be.true;
    expect(isConsecutive([0, 1, 2])).to.be.true;
  });

  it('should handle arrays that need to be sorted', () => {
    // Note: The function expects a pre-sorted array, so unsorted arrays
    // are not guaranteed to work properly
    expect(isConsecutive([3, 1, 2])).to.be.false; // Unsorted not guaranteed to work
    expect(isConsecutive([1, 2, 3])).to.be.true; // Sorted works correctly
  });
});
