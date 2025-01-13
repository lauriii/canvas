import {
  mapComponents,
  mapSlots,
  getElementsByIdInHTMLComment,
  getSlotParentsByHTMLComments,
  getSlotParentElementByIdInHTMLComment,
} from '@/utils/function-utils.ts';

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
                        <div data-xb-uuid="content" data-xb-region="content">
                            <!-- xb-start-fce5e0e3-175f-48b5-a62c-176dbc5f3e91 -->
                            <div data-component-id="experience_builder:my-hero"
                                 class="my-hero__container">
                                <h1 class="my-hero__heading">
                                    <!-- xb-prop-start-fce5e0e3-175f-48b5-a62c-176dbc5f3e91/heading -->
                                    There goes my hero
                                    <!-- xb-prop-end-fce5e0e3-175f-48b5-a62c-176dbc5f3e91/heading --></h1>
                                <p class="my-hero__subheading">
                                    <!-- xb-prop-start-fce5e0e3-175f-48b5-a62c-176dbc5f3e91/subheading -->
                                    Watch him as he goes!
                                    <!-- xb-prop-end-fce5e0e3-175f-48b5-a62c-176dbc5f3e91/subheading --></p>
                                <div class="my-hero__actions">
                                    <a href="https://example.com"
                                       class="my-hero__cta my-hero__cta--primary">
                                        <!-- xb-prop-start-fce5e0e3-175f-48b5-a62c-176dbc5f3e91/cta1 -->
                                        View
                                        <!-- xb-prop-end-fce5e0e3-175f-48b5-a62c-176dbc5f3e91/cta1 --></a>
                                    <button class="my-hero__cta">
                                        <!-- xb-prop-start-fce5e0e3-175f-48b5-a62c-176dbc5f3e91/cta2 -->
                                        Click
                                        <!-- xb-prop-end-fce5e0e3-175f-48b5-a62c-176dbc5f3e91/cta2 --></button>
                                </div>
                            </div>
                            <!-- xb-end-fce5e0e3-175f-48b5-a62c-176dbc5f3e91 -->
                            <!-- xb-start-3c88f148-94e2-47c1-b734-24b5017e9e60 --><h2
                                class="my-section__h2">Our Mission</h2>
                            <div class="my-section__wrapper">
                                <div class="my-section__content-wrapper">
                                    <p class="my-section__paragraph">
                                        <!-- xb-prop-start-3c88f148-94e2-47c1-b734-24b5017e9e60/text -->
                                        Our mission is to deliver the best products and services to
                                        our customers. We strive to exceed expectations and
                                        continuously improve our offerings.
                                        <!-- xb-prop-end-3c88f148-94e2-47c1-b734-24b5017e9e60/text -->
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
                            <!-- xb-end-3c88f148-94e2-47c1-b734-24b5017e9e60 -->
                            <!-- xb-start-ad3eff8e-2180-4be1-a60f-df3f2c5ac393 --><div data-component-id="experience_builder:two_column" data-xb-uuid="ad3eff8e-2180-4be1-a60f-df3f2c5ac393">
          <div class="column-one width-25" data-xb-slot-id="ad3eff8e-2180-4be1-a60f-df3f2c5ac393/column_one">
            <!-- xb-slot-start-ad3eff8e-2180-4be1-a60f-df3f2c5ac393/column_one --><!-- xb-start-9bee944d-a92d-42b9-a0ae-abae0080cdfa --><h1 data-component-id="experience_builder:heading" class="primary" data-xb-uuid="9bee944d-a92d-42b9-a0ae-abae0080cdfa">A heading element</h1>
<!-- xb-end-9bee944d-a92d-42b9-a0ae-abae0080cdfa --><!-- xb-slot-end-ad3eff8e-2180-4be1-a60f-df3f2c5ac393/column_one -->
        </div>

          <div class="column-two width-75" data-xb-slot-id="ad3eff8e-2180-4be1-a60f-df3f2c5ac393/column_two">
            <!-- xb-slot-start-ad3eff8e-2180-4be1-a60f-df3f2c5ac393/column_two --><p>This is column 2 content</p><!-- xb-slot-end-ad3eff8e-2180-4be1-a60f-df3f2c5ac393/column_two -->
        </div>
    </div>
<!-- xb-end-ad3eff8e-2180-4be1-a60f-df3f2c5ac393 --><!-- xb-start-49132256-b0c2-4753-9800-fdc147fafae8 --><div data-component-id="experience_builder:one_column" class="width-full" data-xb-slot-id="49132256-b0c2-4753-9800-fdc147fafae8/content" data-xb-uuid="49132256-b0c2-4753-9800-fdc147fafae8">
      <!-- xb-slot-start-49132256-b0c2-4753-9800-fdc147fafae8/content --><div class="xb--sortable-slot-empty-placeholder"></div><!-- xb-slot-end-49132256-b0c2-4753-9800-fdc147fafae8/content -->
  </div>
<!-- xb-end-49132256-b0c2-4753-9800-fdc147fafae8 --></div>
                    </div>
                </div>
            </div>
        </main>
    </body>
</html>`;

describe('mapComponents', () => {
  it('should create a map of components based on HTML comments in the markup', () => {
    const parser = new DOMParser();
    const doc = parser.parseFromString(pageHTML, 'text/html');

    const expectedComponentMap = {
      'fce5e0e3-175f-48b5-a62c-176dbc5f3e91': {
        componentUuid: 'fce5e0e3-175f-48b5-a62c-176dbc5f3e91',
        elements: [doc.querySelector('.my-hero__container')],
      },
      '3c88f148-94e2-47c1-b734-24b5017e9e60': {
        componentUuid: '3c88f148-94e2-47c1-b734-24b5017e9e60',
        elements: [
          doc.querySelector('.my-section__h2'),
          doc.querySelector('.my-section__wrapper'),
        ],
      },
      'ad3eff8e-2180-4be1-a60f-df3f2c5ac393': {
        componentUuid: 'ad3eff8e-2180-4be1-a60f-df3f2c5ac393',
        elements: [
          doc.querySelector(
            '[data-component-id="experience_builder:two_column"]',
          ),
        ],
      },
      '9bee944d-a92d-42b9-a0ae-abae0080cdfa': {
        componentUuid: '9bee944d-a92d-42b9-a0ae-abae0080cdfa',
        elements: [
          doc.querySelector('[data-component-id="experience_builder:heading"]'),
        ],
      },
      '49132256-b0c2-4753-9800-fdc147fafae8': {
        componentUuid: '49132256-b0c2-4753-9800-fdc147fafae8',
        elements: [
          doc.querySelector(
            '[data-component-id="experience_builder:one_column"]',
          ),
        ],
      },
    };

    const componentMap = mapComponents(doc);

    expect(componentMap).to.deep.equal(expectedComponentMap);
  });
});

describe('getElementsByIdInHTMLComment', () => {
  it('should return elements between xb-start and xb-end comments for a given ID', () => {
    const parser = new DOMParser();
    const doc = parser.parseFromString(pageHTML, 'text/html');

    const elementsForFce5e0e3 = getElementsByIdInHTMLComment(
      'fce5e0e3-175f-48b5-a62c-176dbc5f3e91',
      doc,
    );
    expect(elementsForFce5e0e3).to.deep.equal([
      doc.querySelector('.my-hero__container'),
    ]);

    const elementsFor3c88f148 = getElementsByIdInHTMLComment(
      '3c88f148-94e2-47c1-b734-24b5017e9e60',
      doc,
    );
    expect(elementsFor3c88f148).to.deep.equal([
      doc.querySelector('.my-section__h2'),
      doc.querySelector('.my-section__wrapper'),
    ]);

    // Test for a non-existent ID
    const elementsForNonExistentId = getElementsByIdInHTMLComment(
      'non-existent-id',
      doc,
    );
    expect(elementsForNonExistentId).to.deep.equal([]);
  });
});

describe('mapSlots', () => {
  it('should create a map of slots based on HTML comments in the markup', () => {
    const parser = new DOMParser();
    const doc = parser.parseFromString(pageHTML, 'text/html');

    const expectedSlotsMap = {
      'ad3eff8e-2180-4be1-a60f-df3f2c5ac393/column_one': {
        element: doc.querySelector(
          '[data-xb-slot-id="ad3eff8e-2180-4be1-a60f-df3f2c5ac393/column_one"]',
        ),
        componentUuid: 'ad3eff8e-2180-4be1-a60f-df3f2c5ac393',
        slotName: 'column_one',
      },
      'ad3eff8e-2180-4be1-a60f-df3f2c5ac393/column_two': {
        element: doc.querySelector(
          '[data-xb-slot-id="ad3eff8e-2180-4be1-a60f-df3f2c5ac393/column_two"]',
        ),
        componentUuid: 'ad3eff8e-2180-4be1-a60f-df3f2c5ac393',
        slotName: 'column_two',
      },
      '49132256-b0c2-4753-9800-fdc147fafae8/content': {
        element: doc.querySelector(
          '[data-xb-slot-id="49132256-b0c2-4753-9800-fdc147fafae8/content"]',
        ),
        componentUuid: '49132256-b0c2-4753-9800-fdc147fafae8',
        slotName: 'content',
      },
    };

    const slotsMap = mapSlots(doc);

    expect(slotsMap).to.deep.equal(expectedSlotsMap);
  });
});

describe('getSlotParentsByHTMLComments', () => {
  it('should return an array of parent elements for each xb-slot-start comment', () => {
    const parser = new DOMParser();
    const doc = parser.parseFromString(pageHTML, 'text/html');

    const expectedSlotParents = [
      doc.querySelector('.column-one.width-25'),
      doc.querySelector('.column-two.width-75'),
      doc.querySelector('.width-full'),
    ];

    const slotParents = getSlotParentsByHTMLComments(doc);

    expect(slotParents).to.have.members(expectedSlotParents);
  });
});

describe('getSlotParentElementByIdInHTMLComment', () => {
  it('should return the immediate parent element for a given slotId', () => {
    const parser = new DOMParser();
    const doc = parser.parseFromString(pageHTML, 'text/html');

    // Test for an existing slot
    const slotParentForColumnOne = getSlotParentElementByIdInHTMLComment(
      'ad3eff8e-2180-4be1-a60f-df3f2c5ac393/column_one',
      doc,
    );
    expect(slotParentForColumnOne).to.equal(
      doc.querySelector('.column-one.width-25'),
    );

    // Test for another existing slot
    const slotParentForContent = getSlotParentElementByIdInHTMLComment(
      '49132256-b0c2-4753-9800-fdc147fafae8/content',
      doc,
    );
    expect(slotParentForContent).to.equal(doc.querySelector('.width-full'));

    // Test for a non-existent slot
    const slotParentForNonExistent = getSlotParentElementByIdInHTMLComment(
      'non-existent-slot-id',
      doc,
    );
    expect(slotParentForNonExistent).to.be.null;
  });
});
