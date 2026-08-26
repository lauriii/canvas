import { describe, expect, it } from 'vitest';

import layoutFixture from '../../../tests/fixtures/layout-default.json';
import { buildCurrentLayout } from './currentLayout';

import type {
  ComponentModels,
  RegionNode,
} from '@/features/layout/layoutModelSlice';

describe('buildCurrentLayout', () => {
  // The shape \Drupal\canvas_ai\CanvasAiPageBuilderHelper reads: regions carry
  // nodePathPrefix for getRegionIndex(), components carry name, uuid and props
  // for getComponentsByUuid(), and slot children nest under their slot id. The
  // version hash is stripped from the component type. This snapshot is the
  // payload the AI receives.
  it('returns the layout with every resolved prop value attached', () => {
    expect(
      buildCurrentLayout(
        layoutFixture.layout as RegionNode[],
        layoutFixture.model as ComponentModels,
      ),
    ).toMatchInlineSnapshot(`
      {
        "regions": {
          "content": {
            "components": [
              {
                "name": "sdc.canvas_test_sdc.two_column",
                "props": {
                  "width": 50,
                },
                "slots": {
                  "a7470350-deb2-4d9f-982c-464d356403d4/column_one": {
                    "components": [
                      {
                        "name": "sdc.canvas_test_sdc.my-cta",
                        "props": {
                          "href": "https://drupal.org",
                          "text": "hello, world!",
                        },
                        "uuid": "static-static-card1ab",
                      },
                      {
                        "name": "sdc.canvas_test_sdc.image",
                        "props": {
                          "image": {
                            "alt": "asd",
                            "height": 1118,
                            "src": "public://2024-07/framer.png",
                            "width": 518,
                          },
                        },
                        "uuid": "static-image-udf7d",
                      },
                    ],
                  },
                },
                "uuid": "a7470350-deb2-4d9f-982c-464d356403d4",
              },
              {
                "name": "sdc.canvas_test_sdc.my-cta",
                "props": {
                  "href": "https://drupal.org",
                  "text": "Art",
                },
                "uuid": "static-static-card2df",
              },
              {
                "name": "sdc.canvas_test_sdc.my-cta",
                "props": {
                  "href": "public://2024-07/framer.png",
                  "text": "Art",
                },
                "uuid": "static-static-card3rr",
              },
              {
                "name": "sdc.canvas_test_sdc.image",
                "props": {
                  "image": {
                    "alt": "asd",
                    "height": 100,
                    "src": "http://canvas.ddev.site/sites/default/files/styles/thumbnail/public/2024-07/framer.png.webp?itok=k9sN8Fqf",
                    "width": 46,
                  },
                },
                "uuid": "static-image-static-imageStyle-something7d",
              },
              {
                "name": "sdc.canvas_test_sdc.two_column",
                "props": {
                  "width": 33,
                },
                "slots": {
                  "ee07d472-a754-4427-b6d4-acfc6f92bbdc/column_one": {
                    "components": [
                      {
                        "name": "sdc.canvas_test_sdc.my-section",
                        "props": {
                          "text": "Our mission is to deliver the best products and services to our customers. We strive to exceed expectations and continuously improve our offerings.",
                        },
                        "uuid": "6f3224e2-cb61-46e4-a9e4-35b4d18f0a82",
                      },
                    ],
                  },
                  "ee07d472-a754-4427-b6d4-acfc6f92bbdc/column_two": {
                    "components": [
                      {
                        "name": "sdc.canvas_test_sdc.heading",
                        "props": {
                          "element": "h1",
                          "style": "primary",
                          "text": "Hello",
                        },
                        "uuid": "3b709ed2-99d3-4db2-869d-ca426f69fbb9",
                      },
                      {
                        "name": "sdc.canvas_test_sdc.my-hero",
                        "props": {
                          "cta1": "View",
                          "cta1href": "https://example.com",
                          "cta2": "Click",
                          "heading": "There goes my hero",
                          "subheading": "Watch him as he goes!",
                        },
                        "uuid": "eaa37ee1-7d50-4041-b04c-c80bdbac3412",
                      },
                    ],
                  },
                },
                "uuid": "ee07d472-a754-4427-b6d4-acfc6f92bbdc",
              },
            ],
            "nodePathPrefix": [
              0,
            ],
          },
        },
      }
    `);
  });
});
