<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Adapter;

use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file\FileInterface;

#[Adapter(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Make relative image URL absolute'),
  inputs: [
    'image' => ['type' => 'object', '$ref' => 'json-schema-definitions://canvas.module/image'],
  ],
  requiredInputs: ['image'],
  output: ['type' => 'object', '$ref' => 'json-schema-definitions://canvas.module/image'],
)]
final class ImageAdapter extends AdapterBase implements ContainerFactoryPluginInterface {

  use EntityTypeManagerDependentAdapterTrait;

  public const string PLUGIN_ID = 'image_url_rel_to_abs';

  /**
   * @var array{src: string, alt: string, width:integer, height:integer}
   */
  protected array $image;

  public function adapt(): EvaluationResult {
    $files = $this->entityTypeManager
      ->getStorage('file')
      ->loadByProperties(['filename' => urldecode(basename($this->image['src']))]);
    $image = reset($files);
    if (!$image instanceof FileInterface) {
      throw new \Exception('No image file found');
    }

    return new EvaluationResult(
      [
        'src' => $image->createFileUrl(FALSE),
        'alt' => $this->image['alt'],
        'width' => $this->image['width'],
        'height' => $this->image['height'],
      ],
      $image,
    );
  }

}
