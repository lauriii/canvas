<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Adapter;

use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file\FileInterface;

#[Adapter(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Extract image URL'),
  inputs: [
    'imageUri' => ['type' => 'string', '$ref' => 'json-schema-definitions://canvas.module/stream-wrapper-image-uri'],
  ],
  requiredInputs: ['imageUri'],
  output: ['type' => 'string', '$ref' => 'json-schema-definitions://canvas.module/image-uri'],
)]
final class ImageUriAdapter extends AdapterBase implements ContainerFactoryPluginInterface {

  use EntityTypeManagerDependentAdapterTrait;

  public const string PLUGIN_ID = 'image_extract_url';

  protected string $imageUri;

  public function adapt(): EvaluationResult {
    $files = $this->entityTypeManager
      ->getStorage('file')
      ->loadByProperties(['filename' => urldecode(basename($this->imageUri))]);
    $image = reset($files);
    if (!$image instanceof FileInterface) {
      throw new \Exception('No image file found');
    }

    return new EvaluationResult(
      $image->createFileUrl(FALSE),
      (new CacheableMetadata())
        ->addCacheableDependency($image)
        // An absolute URL was generated, so a different one must be generated
        // per site served by this Drupal instance.
        // @see \Drupal\Core\Cache\Context\SiteCacheContext
        ->addCacheContexts(['url.site']),
    );
  }

}
