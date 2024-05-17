<?php

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Controller\ControllerBase;

class ExperienceBuilderController extends ControllerBase {

  // phpcs:disable Drupal.Commenting.FunctionComment.WrongStyle
  // https://git.drupalcode.org/project/experience_builder/-/merge_requests/8 is
  // changing this, so ignore.
  // @phpstan-ignore-next-line
  public function content(): array {
    return [
      '#markup' => '<div id="experience-builder" class="experience-builder-container">Loading react app...</div>',
      '#attached' => [
        'library' => [
          'experience_builder/eb-ui',
        ],
      ],
    ];
  }

}
