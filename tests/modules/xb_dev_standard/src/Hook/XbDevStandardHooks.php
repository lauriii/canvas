<?php

declare(strict_types=1);

namespace Drupal\xb_dev_standard\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\experience_builder\Storage\ComponentTreeLoader;

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
    $user = $this->currentUser;
    $items = [];
    $items['experience_builder'] = [
      '#cache' => [
        'contexts' => [
          'user.permissions',
          'url',
        ],
      ],
    ];
    // @see experience_builder.routing.yml
    // ⚠️ This is HORRIBLY HACKY way to provide a XB link for articles using `field_xb_demo` and will go away! ☺️
    if ($user->hasPermission('access administration pages')) {
      $node = $this->routeMatch->getParameter('node');
      if ($node) {
        try {
          $this->componentTreeLoader->load($node);
        }
        catch (\LogicException) {
          return $items;
        }
        $items['experience_builder'] += [
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
        ];
      }
    }
    return $items;
  }

}
