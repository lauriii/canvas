<?php

declare(strict_types=1);

namespace Drupal\canvas\Asset;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\CanvasAssetInterface;
use Drupal\Core\Asset\AssetCollectionRendererInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Adds the version of the draft asset to links to draft CSS and JS.
 *
 * Draft asset libraries link to fixed URLs, and asset library definitions are
 * cached. This adds the version when the links are rendered, so browsers can
 * cache the draft assets until they change.
 *
 * @see \Drupal\canvas\Hook\LibraryHooks::libraryInfoBuild()
 * @see \Drupal\canvas\Controller\ApiConfigAutoSaveControllers::getCss()
 */
final class DraftAssetCollectionRenderer implements AssetCollectionRendererInterface {

  public function __construct(
    private readonly AssetCollectionRendererInterface $inner,
    private readonly AutoSaveManager $autoSaveManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function render(array $assets): array {
    $elements = $this->inner->render($assets);
    foreach ($elements as &$element) {
      foreach (['href', 'src'] as $attribute) {
        $url = $element['#attributes'][$attribute] ?? NULL;
        if (\is_string($url) && \preg_match('#/canvas/api/v0/auto-saves/(css|js)/([^/?]+)/([^/?]+)#', $url, $matches)) {
          $entity = $this->entityTypeManager->getStorage($matches[2])->load($matches[3]);
          if ($entity instanceof CanvasAssetInterface) {
            [, $version] = $this->autoSaveManager->getDraftAsset($entity, $matches[1]);
            $element['#attributes'][$attribute] .= (\str_contains($url, '?') ? '&' : '?') . 'v=' . $version;
          }
        }
      }
    }
    return $elements;
  }

}
