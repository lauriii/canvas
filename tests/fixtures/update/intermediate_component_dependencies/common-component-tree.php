<?php

/**
 * @file
 * This file contains constants defining a component tree and its dependencies.
 *
 * This is useful to simplify upgrade paths testing, so we always have the same
 * data and dependencies, no matter the type of component tree container (e.g.
 * patterns, page regions, etc.) we are testing against.
 */

const COMPONENT_TREE_INCLUDING_REFERENCE_FIELD_TYPE_PROP_EXPRESSION = [
  [
    "uuid" => "392fdd2a-354c-43cf-bb64-396a63217cb9",
    "component_id" => "js.canvas_test_code_components_vanilla_image",
    "component_version" => "b5e039b71b27fef6",
    "inputs" => [
      "image" => [
        "target_id" => "1",
      ],
    ],
  ],
];

const COMPONENT_TREE_INCLUDING_DYNAMIC_PROP_EXPRESSION = [
  [
    "uuid" => "f24970d0-be14-4de3-ad2c-c189eefab31c",
    "component_id" => "sdc.canvas_test_sdc.heading",
    "component_version" => "8dd7b865998f53b0",
    "inputs" => [
      "text" => [
        "sourceType" => 'dynamic',
        "expression" => 'ℹ︎␜entity:node:article␝title␞␟value',
      ],
      "style" => 'secondary',
      "element" => 'h3',
    ],
  ],
];

const COMPONENT_TREE_INCLUDING_REFERENCE_FIELD_TYPE_PROP_EXPRESSION_DEPENDENCIES_BEFORE = [
  'config' => [
    'canvas.component.js.canvas_test_code_components_vanilla_image',
    'field.field.media.image.field_media_image',
    'image.style.canvas_parametrized_width',
    'media.type.image',
  ],
  'content' => [
    'media:image:346210de-12d8-4d02-9db4-455f1bdd99f7',
  ],
  'module' => [
    'file',
    'media',
  ],
];
