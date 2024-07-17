<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Traits;

use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;

trait ComponentTreeTestTrait {

  protected function getComponentTreeTestCases(): array {
    return [
      'valid values using dynamic props' => [
        [
          'tree' => '{"a548b48d-58a8-4077-aa04-da9405a6f418": [{"uuid":"dynamic-static-card2df","component":"sdc_test:my-cta"}]}',
          'props' => '{"dynamic-static-card2df":{"text":{"sourceType":"dynamic","expression":"ℹ︎␜entity:node:article␝title␞␟value"},"href":{"sourceType":"static:field_item:link","value":{"uri":"https:\/\/drupal.org","title":null,"options":[]},"expression":"ℹ︎link␟uri"}}}',
        ],
      ],
      'missing components, using dynamic props' => [
        [
          // Use a tree with 2 missing components and 1 existing.
          'tree' => '{"' . ComponentTreeStructure::ROOT_UUID . '": [{"uuid":"dynamic-static-card2df","component":"sdc_test:missing"}, {"uuid":"dynamic-static-card3","component":"sdc_test:missing-also"},{"uuid":"dynamic-static-card4","component":"sdc_test:my-cta"}]}',
          'props' => '{"dynamic-static-card2df":{"text":{"sourceType":"dynamic","expression":"ℹ︎␜entity:node:article␝title␞␟value"},"href":{"sourceType":"static:field_item:link","value":{"uri":"https:\/\/drupal.org","title":null,"options":[]},"expression":"ℹ︎link␟uri"}},"dynamic-static-card3":{"text":{"sourceType":"dynamic","expression":"ℹ︎␜entity:node:article␝title␞␟value"},"href":{"sourceType":"static:field_item:link","value":{"uri":"https:\/\/drupal.org","title":null,"options":[]},"expression":"ℹ︎link␟uri"}},"dynamic-static-card4":{"text":{"sourceType":"dynamic","expression":"ℹ︎␜entity:node:article␝title␞␟value"},"href":{"sourceType":"static:field_item:link","value":{"uri":"https:\/\/drupal.org","title":null,"options":[]},"expression":"ℹ︎link␟uri"}}}',
        ],
      ],
      'missing components, using only static props' => [
        [
          // Missing component.
          'tree' => '{"' . ComponentTreeStructure::ROOT_UUID . '": [{"uuid":"static-card2df","component":"sdc_test:missing"}]}',
          'props' => '{"static-card2df":{"text":{"sourceType":"static:field_item:link","value":{"uri":"https:\/\/drupal.org","title":null,"options":[]},"expression":"ℹ︎link␟uri"}}}',
        ],
      ],
      'props invalid, using dynamic props' => [
        [
          'tree' => '{"' . ComponentTreeStructure::ROOT_UUID . '": [{"uuid":"dynamic-static-card2df","component":"sdc_test:my-cta"}, {"uuid":"dynamic-static-card3","component":"sdc_test:my-cta"},{"uuid":"dynamic-static-card4","component":"sdc_test:my-cta"}]}',
          'props' => '{"dynamic-static-card2df":{"text-x":{"sourceType":"dynamic","expression":"ℹ︎␜entity:node:article␝title␞␟value"},"href":{"sourceType":"static:field_item:link","value":{"uri":"https:\/\/drupal.org","title":null,"options":[]},"expression":"ℹ︎link␟uri"}},"dynamic-static-card3":{"text-2":{"sourceType":"dynamic","expression":"ℹ︎␜entity:node:article␝title␞␟value"},"href":{"sourceType":"static:field_item:link","value":{"uri":"https:\/\/drupal.org","title":null,"options":[]},"expression":"ℹ︎link␟uri"}},"dynamic-static-card4":{"text":{"sourceType":"dynamic","expression":"ℹ︎␜entity:node:article␝title␞␟value"},"href":{"sourceType":"static:field_item:link","value":{"uri":"https:\/\/drupal.org","title":null,"options":[]},"expression":"ℹ︎link␟uri"}}}',
        ],
      ],
      'props invalid, using only static props' => [
        [
          'tree' => '{"' . ComponentTreeStructure::ROOT_UUID . '": [{"uuid":"static-card2df","component":"sdc_test:my-cta"}]}',
          'props' => '{"static-card2df":{"text-x":{"sourceType":"static:field_item:link","value":{"uri":"https:\/\/drupal.org","title":null,"options":[]},"expression":"ℹ︎link␟uri"}},"href":{"sourceType":"static:field_item:link","value":{"uri":"https:\/\/drupal.org","title":null,"options":[]},"expression":"ℹ︎link␟uri"}}',
        ],
      ],
      'missing props key' => [
        [
          'tree' => '{"' . ComponentTreeStructure::ROOT_UUID . '": [{"uuid":"dynamic-static-card2df","component":"sdc_test:my-cta"}, {"uuid":"dynamic-static-card3","component":"sdc_test:my-cta"},{"uuid":"dynamic-static-card4","component":"sdc_test:my-cta"}]}',
        ],
      ],
      'missing tree key' => [
        [
          'props' => '{"dynamic-static-card2df":{"text":{"sourceType":"dynamic","expression":"ℹ︎␜entity:node:article␝title␞␟value"},"href":{"sourceType":"static:field_item:link","value":{"uri":"https:\/\/drupal.org","title":null,"options":[]},"expression":"ℹ︎link␟uri"}}}',
        ],
      ],
    ];
  }

}
