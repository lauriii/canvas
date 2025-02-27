<?php

/**
 * @file
 * Hook implementations that make "code component"-powered Block overrides work.
 *
 * @see https://www.drupal.org/project/experience_builder/issues/3505993
 * @see docs/components.md, section 3.3.3
 */

use Drupal\Core\Theme\ThemeCommonElements;
use Drupal\Core\Url;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent;

/**
 * Implements hook_theme().
 */
function experience_builder_theme(): array {
  // @phpstan-ignore-next-line
  $common_elements = version_compare(\Drupal::VERSION, '11.1', '>=') ? ThemeCommonElements::commonElements() : drupal_common_theme();
  return [
    'block__system_menu_block__as_js_component' => [
      'base hook' => 'block',
      'template' => 'just-children',
    ],
    'menu__as_js_component' => [
      'base hook' => 'menu',
      'template' => 'just-children',
      'variables' => $common_elements['menu']['variables'] + [
        'rendering_context' => NULL,
      ],
    ],
    'block__system_branding_block__as_js_component' => [
      'base hook' => 'block',
      'template' => 'just-children',
    ],
    'block__system_breadcrumb_block__as_js_component' => [
      'base hook' => 'block',
      'template' => 'just-children',
    ],
    'breadcrumb__as_js_component' => [
      'base hook' => 'breadcrumb',
      'template' => 'just-children',
      'variables' => $common_elements['breadcrumb']['variables'] + [
        'rendering_context' => NULL,
      ],
    ],
  ];
}

/**
 * Implements hook_preprocess_HOOK().
 */
function experience_builder_preprocess_block__system_menu_block__as_js_component(array &$variables): void {
  $variables['children'] = $variables['content'];
  $variables['children']['#theme'] = 'menu__as_js_component';
  $variables['children']['#rendering_context']['block'] = $variables;
}

/**
 * Implements hook_preprocess_HOOK().
 */
function template_preprocess_menu__as_js_component(array &$variables): void {
  $block_variables = $variables['rendering_context']['block'];

  $normalizeLinks = function ($items) use (&$normalizeLinks) {
    $links = [];
    foreach ($items as $key => $item) {
      $link = [
        'key' => $key,
        'title' => $item['title'],
        'url' => $item['url']->toString(),
        'isExpanded' => $item['is_expanded'],
        'isCollapsed' => $item['is_collapsed'],
        'inActiveTrail' => $item['in_active_trail'],
      ];
      if ($item['below']) {
        $link['submenu']['links'] = $normalizeLinks($item['below']);
      }
      $links[] = $link;
    }
    return $links;
  };

  $props = [
    'id' => $block_variables['attributes']['id'],
    'links' => $normalizeLinks($variables['items']),
  ];

  $slots = [
    'label' => $block_variables['label'] ?: $block_variables['configuration']['label'],
  ];

  $variables['children'] = _experience_builder_render_js_component_from_block_element(
    $block_variables['elements'],
    $props,
    $slots
  );
}

/**
 * Implements hook_preprocess_HOOK().
 */
function experience_builder_preprocess_block__system_branding_block__as_js_component(array &$variables): void {
  $block_variables = $variables;

  $props = [
    'homeUrl' => Url::fromRoute('<front>')->toString(),
    'logo' => $block_variables['site_logo'],
    'siteName' => $block_variables['site_name'],
  ];

  $slots = [
    'siteSlogan' => $block_variables['site_slogan'],
  ];

  $variables['children'] = _experience_builder_render_js_component_from_block_element(
    $block_variables['elements'],
    $props,
    $slots
  );
}

/**
 * Implements hook_preprocess_HOOK().
 */
function experience_builder_preprocess_block__system_breadcrumb_block__as_js_component(array &$variables): void {
  $variables['children'] = $variables['content'];
  $variables['children']['#theme'] = 'breadcrumb__as_js_component';
  $variables['children']['#rendering_context']['block'] = $variables;
}

/**
 * Implements hook_preprocess_HOOK().
 */
function template_preprocess_breadcrumb__as_js_component(array &$variables): void {
  $block_variables = $variables['rendering_context']['block'];

  $normalizeLinks = function ($items) {
    $links = [];
    foreach ($items as $key => $item) {
      $link = [
        'key' => $key,
        'text' => $item['text'],
        'url' => $item['url'],
      ];
      $links[] = $link;
    }
    return $links;
  };

  $props = [
    'links' => $normalizeLinks($variables['breadcrumb']),
  ];

  $slots = [];

  $variables['children'] = _experience_builder_render_js_component_from_block_element(
    $block_variables['elements'],
    $props,
    $slots
  );
}

/**
 * Renders a block element with a #js_component override as that JS component.
 */
function _experience_builder_render_js_component_from_block_element(array $block_element, array $props, array $slots): array {
  $js_component = $block_element['#js_component'];
  $instance_uuid = $block_element['#id'];

  // @todo Would it be better to instantiate the source plugin without going
  //   through an intermediary ephemeral Component entity?
  $source = JsComponent::createConfigEntity($js_component)->getComponentSource();

  $build = $source->renderComponent(['props' => $props], $instance_uuid);
  assert($source instanceof JsComponent);
  $source->setSlots($build, $slots);
  return $build;
}
