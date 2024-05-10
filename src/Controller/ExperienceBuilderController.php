<?php

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Controller\ControllerBase;

class ExperienceBuilderController extends ControllerBase {
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

