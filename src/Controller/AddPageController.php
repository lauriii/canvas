<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\Entity\Page;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

final readonly class AddPageController {

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function __invoke(): RedirectResponse {
    $entity_type = $this->entityTypeManager->getDefinition(Page::ENTITY_TYPE_ID);
    $storage = $this->entityTypeManager->getStorage(Page::ENTITY_TYPE_ID);
    // Create a new Canvas page.
    $page = $storage->create([
      'title' => ApiContentControllers::defaultTitle($entity_type),
      'status' => FALSE,
    ]);
    $page->save();

    $url = Url::fromRoute(
      'entity.canvas_page.edit_form',
      [Page::ENTITY_TYPE_ID => $page->id()]
    );
    return new RedirectResponse($url->toString());
  }

}
