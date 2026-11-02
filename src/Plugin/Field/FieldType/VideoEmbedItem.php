<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Field\FieldType;

use Drupal\canvas\TypedData\VideoEmbedIframeUrl;
use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\UriItem;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\media\OEmbed\ProviderException;
use Drupal\media\OEmbed\ProviderRepositoryInterface;

/**
 * Stores the URL of a video on a video sharing site, such as YouTube.
 *
 * The computed `iframe_url` property points to core's oEmbed iframe route. That
 * route renders the provider's embed HTML in a separate document, so a
 * component only needs an `<iframe>` and never renders provider HTML itself.
 *
 * Only URLs of the providers that core's "Remote video" media source allows
 * get an `iframe_url`. To allow more providers, implement
 * hook_media_source_info_alter() for the `oembed:video` media source.
 *
 * @see json-schema-definitions://canvas.module/video-embed
 * @see \Drupal\canvas\Hook\ShapeMatchingHooks::mediaStorablePropShapeAlter()
 * @see \Drupal\media\Controller\OEmbedIframeController
 *
 * @internal
 */
#[FieldType(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Video embed'),
  description: new TranslatableMarkup('The URL of a video on a video sharing site, such as YouTube.'),
  default_widget: 'canvas_video_embed_url',
  no_ui: TRUE,
)]
final class VideoEmbedItem extends UriItem {

  public const string PLUGIN_ID = 'canvas_video_embed';

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties = parent::propertyDefinitions($field_definition);
    $properties['iframe_url'] = DataDefinition::create('uri')
      ->setLabel(new TranslatableMarkup('Embed URL'))
      ->setDescription(new TranslatableMarkup('The URL of the oEmbed iframe that plays the video.'))
      ->setComputed(TRUE)
      ->setReadOnly(TRUE)
      ->setClass(VideoEmbedIframeUrl::class);
    return $properties;
  }

  /**
   * {@inheritdoc}
   *
   * Also accepts an example value of the `video-embed` prop shape: its `src`
   * is then the URL of the video page, from which `iframe_url` is computed.
   *
   * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentDiscoveryBase::computeDefaultFieldValue()
   */
  // @phpstan-ignore-next-line method.childParameterType
  public function setValue($values, $notify = TRUE): void {
    if (\is_array($values) && \array_key_exists('src', $values) && !\array_key_exists('value', $values)) {
      $values = ['value' => $values['src']];
    }
    parent::setValue($values, $notify);
  }

  /**
   * {@inheritdoc}
   *
   * The iframe URL is computed with the Media module's oEmbed services, so
   * components using this field type depend on it.
   *
   * @see \Drupal\canvas\TypedData\VideoEmbedIframeUrl
   */
  public static function calculateStorageDependencies(FieldStorageDefinitionInterface $field_definition): array {
    return ['module' => ['media']];
  }

  /**
   * {@inheritdoc}
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition) {
    return ['value' => 'https://www.youtube.com/watch?v=15Nqbic6HZs'];
  }

  /**
   * Gets the oEmbed endpoints of the video providers that may be embedded.
   *
   * @return list<\Drupal\media\OEmbed\Endpoint>
   *   The endpoints. Empty if the oEmbed provider database is unavailable.
   *
   * @see \Drupal\media\Plugin\media\Source\OEmbed::getProviders()
   */
  public static function getAllowedEndpoints(): array {
    $provider_names = self::getAllowedProviderNames();
    $provider_repository = \Drupal::service(ProviderRepositoryInterface::class);
    $endpoints = [];
    foreach ($provider_names as $provider_name) {
      try {
        \array_push($endpoints, ...$provider_repository->get($provider_name)->getEndpoints());
      }
      // The provider database is unavailable, or does not know this provider.
      catch (ProviderException | \InvalidArgumentException) {
      }
    }
    return $endpoints;
  }

  /**
   * Gets the names of the video providers that may be embedded.
   *
   * @return list<string>
   *   The provider names, such as "YouTube".
   *
   * @see \Drupal\media\Plugin\media\Source\OEmbed
   */
  public static function getAllowedProviderNames(): array {
    return \Drupal::service('plugin.manager.media.source')->getDefinition('oembed:video')['providers'];
  }

}
