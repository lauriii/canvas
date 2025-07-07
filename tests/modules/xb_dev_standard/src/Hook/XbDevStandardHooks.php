<?php

declare(strict_types=1);

namespace Drupal\xb_dev_standard\Hook;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\experience_builder\Storage\ComponentTreeLoader;
use Drupal\node\NodeInterface;

class XbDevStandardHooks {

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
    $items['experience_builder'] = [
      '#cache' => [
        'contexts' => [
          'url',
        ],
      ],
    ];
    // @see experience_builder.routing.yml
    // ⚠️ This is HORRIBLY HACKY way to provide a XB link for articles using `field_xb_demo` and will go away! ☺️
    $node = $this->routeMatch->getParameter('node');
    if ($node) {
      assert($node instanceof NodeInterface);
      try {
        $xb_field = $this->componentTreeLoader->load($node);
        $entity_access = $node->access('update', $this->currentUser, TRUE);
        $xb_field_access = $xb_field->access('edit', $this->currentUser, TRUE);
        $xb_access = $entity_access->andIf($xb_field_access);
        assert($xb_access instanceof AccessResult);
        $items['experience_builder']['#cache']['contexts'] = $xb_access->getCacheContexts();
        $items['experience_builder']['#cache']['tags'] = $xb_access->getCacheTags();
        $items['experience_builder']['#cache']['max-age'] = $xb_access->getCacheMaxAge();
        if (!$xb_access->isAllowed()) {
          return $items;
        }
      }
      catch (\LogicException) {
        return $items;
      }
      $items['experience_builder'] = NestedArray::mergeDeep($items['experience_builder'], [
        '#type' => 'toolbar_item',
        'tab' => [
          '#type' => 'link',
          '#title' => new TranslatableMarkup('Experience Builder: %title', ['%title' => $node->label()]),
          '#url' => Url::fromRoute('experience_builder.experience_builder', [
            'entity_type' => 'node',
            'entity' => $node->id(),
          ]),
          '#attributes' => [
            'title' => new TranslatableMarkup('Experience Builder'),
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
