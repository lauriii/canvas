<?php

declare(strict_types=1);

namespace Drupal\canvas\TypedData;

use Drupal\canvas\Plugin\DataType\ComputedDataTypeWithCacheabilityTrait;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Entity\Plugin\DataType\EntityReference;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\canvas\Entity\ParametrizedImageStyle;
use Drupal\canvas\Plugin\DataType\UriTemplate;
use Drupal\canvas\Plugin\Field\FieldTypeOverride\ImageItemOverride;
use Drupal\Core\GeneratedUrl;
use Drupal\file\Entity\File;

/**
 * Computes URI template with a `{width}` variable to populate `<img srcset>`.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/API/HTMLImageElement/srcset#value
 * @see https://tools.ietf.org/html/rfc6570
 * @internal
 */
final class ImageDerivativeWithParametrizedWidth extends UriTemplate implements CacheableDependencyInterface {

  use ComputedDataTypeWithCacheabilityTrait {
    getValue as private traitGetValue;
  }

  private ?GeneratedUrl $computedValue;

  /**
   * {@inheritdoc}
   */
  public function getValue(): ?GeneratedUrl {
    return $this->traitGetValue();
  }

  /**
   * {@inheritdoc}
   */
  public function getCastedValue(): ?string {
    return $this->getValue()?->getGeneratedUrl();
  }

  private function getParametrizedImageStyle(): ParametrizedImageStyle {
    // @phpstan-ignore-next-line
    return ParametrizedImageStyle::load('canvas_parametrized_width');
  }

  /**
   * {@inheritdoc}
   */
  public function computeValue() : ?GeneratedUrl {
    if ($this->getParent() === NULL) {
      return NULL;
    }
    \assert($this->getParent() instanceof ImageItemOverride);

    $entity = $this->getParent()->get('entity');

    // The image field may still be empty.
    if ($entity === NULL) {
      return NULL;
    }
    \assert($entity instanceof EntityReference);
    $file = $entity->getTarget()?->getValue();
    \assert($file instanceof File);

    \assert(is_string($file->getFileUri()));
    $parametrized_image_style = $this->getParametrizedImageStyle();
    $url_template = $parametrized_image_style->buildUrlTemplate($file->getFileUri());
    \assert(str_contains($url_template, '{width}'));

    // Transform absolute to relative URL template.
    $file_url_generator = \Drupal::service(FileUrlGeneratorInterface::class);
    \assert($file_url_generator instanceof FileUrlGeneratorInterface);
    $url_template = $file_url_generator->transformRelative($url_template);
    \assert(str_contains($url_template, '{width}'));
    return (new GeneratedUrl())->setGeneratedUrl($url_template)
      ->addCacheableDependency($parametrized_image_style)
      ->addCacheableDependency($file);
  }

}
