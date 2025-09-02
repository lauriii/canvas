<?php

declare(strict_types=1);

namespace Drupal\canvas_dev_standard\Hook;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\canvas\Storage\ComponentTreeLoader;
use Drupal\node\NodeInterface;

class CanvasDevStandardHooks {

  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly ComponentTreeLoader $componentTreeLoader,
    private readonly RouteMatchInterface $routeMatch,
  ) {
  }

  /**
   * Implements hook_toolbar().
   */
  #[Hook('toolbar')]
  public function toolbar(): array {
    $items = [];
    $items['canvas'] = [
      '#cache' => [
        'contexts' => [
          'url',
        ],
      ],
    ];
    // @see canvas.routing.yml
    // ⚠️ This is HORRIBLY HACKY way to provide a Canvas link for articles using `field_canvas_demo` and will go away! ☺️
    $node = $this->routeMatch->getParameter('node');
    if ($node) {
      assert($node instanceof NodeInterface);
      try {
        $canvas_field = $this->componentTreeLoader->load($node);
        $entity_access = $node->access('update', $this->currentUser, TRUE);
        $canvas_field_access = $canvas_field->access('edit', $this->currentUser, TRUE);
        $canvas_access = $entity_access->andIf($canvas_field_access);
        assert($canvas_access instanceof AccessResult);
        $items['canvas']['#cache']['contexts'] = $canvas_access->getCacheContexts();
        $items['canvas']['#cache']['tags'] = $canvas_access->getCacheTags();
        $items['canvas']['#cache']['max-age'] = $canvas_access->getCacheMaxAge();
        if (!$canvas_access->isAllowed()) {
          return $items;
        }
      }
      catch (\LogicException) {
        return $items;
      }
      $items['canvas'] = NestedArray::mergeDeep($items['canvas'], [
        '#type' => 'toolbar_item',
        'tab' => [
          '#type' => 'link',
          '#title' => new TranslatableMarkup('Drupal Canvas: %title', ['%title' => $node->label()]),
          '#url' => Url::fromRoute('canvas.boot.entity', [
            'entity_type' => 'node',
            'entity' => $node->id(),
          ]),
          '#attributes' => [
            'title' => new TranslatableMarkup('Drupal Canvas'),
            'class' => ['toolbar-icon', 'toolbar-icon-edit'],
          ],
        ],
        '#weight' => 1000,
        '#cache' => [
          'tags' => $node->getCacheTags(),
        ],
      ]);
    }
    return $items;
  }

}
