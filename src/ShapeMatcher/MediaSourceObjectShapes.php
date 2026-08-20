<?php

declare(strict_types=1);

namespace Drupal\canvas\ShapeMatcher;

use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaObjectRef;
use Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\canvas\TypedData\BetterEntityDataDefinition;
use Drupal\media\Entity\MediaType;
use Drupal\media\MediaTypeInterface;
use Drupal\media\Plugin\media\Source\File;
use Drupal\media\Plugin\media\Source\Image;
use Drupal\media\Plugin\media\Source\VideoFile;

/**
 * Maps Canvas object shapes to media source plugins and their media types.
 *
 * The media library integration creates fields targeting these media types
 * (static prop sources), and the entity field matcher suggests existing media
 * reference fields (dynamic prop sources). Both derive their matches from
 * this single mapping, so a media type usable in one context is usable in the
 * other.
 *
 * @see \Drupal\canvas\Hook\ShapeMatchingHooks::mediaLibraryStorablePropShapeAlter()
 * @see \Drupal\canvas\ShapeMatcher\EntityFieldPropSourceMatcher
 *
 * @internal
 */
final class MediaSourceObjectShapes {

  const SCHEMA_TO_MEDIA_SOURCE = [
    // @see \Drupal\media\Plugin\media\Source\Image
    JsonSchemaObjectRef::Image->value => Image::class,
    // @see \Drupal\media\Plugin\media\Source\VideoFile
    JsonSchemaObjectRef::Video->value => VideoFile::class,
    // @see \Drupal\media\Plugin\media\Source\File
    JsonSchemaObjectRef::Document->value => File::class,
  ];

  /**
   * Returns the media types whose media source matches an object shape.
   *
   * A media type matches when its source plugin is an instance of the class
   * mapped in SCHEMA_TO_MEDIA_SOURCE, and, if the shape declares
   * `x-allowed-file-extensions`, when its source field allows at least one of
   * those extensions. Intersection, not subset: core's `document` media type
   * allows `txt` next to `pdf` and must keep matching. The field's own
   * `file_extensions` validation still governs what can be uploaded.
   *
   * @param \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaObjectRef $ref
   *   An object shape with an entry in SCHEMA_TO_MEDIA_SOURCE.
   *
   * @return array<string, \Drupal\media\MediaTypeInterface>
   */
  public static function getMediaTypesForSchemaObject(JsonSchemaObjectRef $ref) : array {
    \assert(\array_key_exists($ref->value, self::SCHEMA_TO_MEDIA_SOURCE));
    $media_source_class = self::SCHEMA_TO_MEDIA_SOURCE[$ref->value];
    $allowed_file_extensions = $ref->allowedFileExtensions();
    // Allow all MediaTypes that use the "image" MediaSource, for example.
    // A MediaType whose source is a more specific subclass never matches its
    // parent class's schema: VideoFile extends File, so video MediaTypes must
    // not match the document shape. AudioFile also extends File, but needs no
    // such mapping: audio file extensions do not intersect the document
    // shape's `x-allowed-file-extensions` list.
    // @todo Map AudioFile to an `audio` object shape in SCHEMA_TO_MEDIA_SOURCE once one exists.
    // @see \Drupal\media\Plugin\media\Source\Image
    $media_types = \array_filter(
      MediaType::loadMultiple(),
      fn (MediaTypeInterface $type): bool => \is_a($type->getSource(), $media_source_class)
        && !self::hasMoreSpecificMediaSourceMapping($type, $media_source_class)
        && ($allowed_file_extensions === [] || self::sourceFieldAllowsExtensions($type, $allowed_file_extensions))
    );
    \ksort($media_types);
    $media_type_ids = \array_map(
    // @phpstan-ignore-next-line
      fn (MediaTypeInterface $type): string => $type->id(),
      $media_types
    );
    return \array_combine($media_type_ids, $media_types);
  }

  /**
   * Whether a media type's source field allows any of the given extensions.
   *
   * @param \Drupal\media\MediaTypeInterface $type
   *   A media type.
   * @param list<string> $allowed_file_extensions
   *   File extensions declared by the shape being matched.
   *
   * @return bool
   */
  private static function sourceFieldAllowsExtensions(MediaTypeInterface $type, array $allowed_file_extensions): bool {
    $source_field_extensions = $type->getSource()->getSourceFieldDefinition($type)?->getSetting('file_extensions');
    if (!\is_string($source_field_extensions)) {
      return FALSE;
    }
    return \array_intersect(
      \array_filter(\explode(' ', $source_field_extensions)),
      $allowed_file_extensions,
    ) !== [];
  }

  /**
   * Whether a MediaType's source is a more specific subclass of a source.
   *
   * A source class is more specific when it maps to another schema
   * (VideoFile): its media types must not match the schema mapped to the
   * given (parent) source class.
   *
   * @param \Drupal\media\MediaTypeInterface $type
   *   A media type.
   * @param class-string $media_source_class
   *   The MediaSource plugin class being matched.
   *
   * @return bool
   */
  private static function hasMoreSpecificMediaSourceMapping(MediaTypeInterface $type, string $media_source_class): bool {
    $more_specific_source_classes = \array_values(self::SCHEMA_TO_MEDIA_SOURCE);
    foreach ($more_specific_source_classes as $other_source_class) {
      if ($other_source_class !== $media_source_class
        && \is_subclass_of($other_source_class, $media_source_class)
        && \is_a($type->getSource(), $other_source_class)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns FieldObjectProp for the given MediaSource.
   *
   * @param class-string $media_source_class
   *   A MediaSource plugin class.
   * @param \Drupal\canvas\TypedData\BetterEntityDataDefinition $media_entity_type_and_bundle
   * @param string $source_field_name
   *
   * @return non-empty-array<string, \Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression|\Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldPropExpression>
   */
  public static function getObjectProps(string $media_source_class, BetterEntityDataDefinition $media_entity_type_and_bundle, string $source_field_name): array {
    return match ($media_source_class) {
      Image::class => [
        // TRICKY: Additional computed property on image fields added by
        // Drupal Canvas.
        // @see \Drupal\canvas\Plugin\Field\FieldTypeOverride\ImageItemOverride
        // @phpcs:disable Drupal.Files.LineLength.TooLong
        'src' => new FieldPropExpression($media_entity_type_and_bundle, $source_field_name, \NULL, 'src_with_alternate_widths'),
        // @phpcs:enable
        'alt' => new FieldPropExpression($media_entity_type_and_bundle, $source_field_name, \NULL, 'alt'),
        'width' => new FieldPropExpression($media_entity_type_and_bundle, $source_field_name, \NULL, 'width'),
        'height' => new FieldPropExpression($media_entity_type_and_bundle, $source_field_name, \NULL, 'height'),
      ],
      VideoFile::class => [
        'src' => new ReferenceFieldPropExpression(
          new FieldPropExpression($media_entity_type_and_bundle, $source_field_name, \NULL, 'entity'),
          new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', \NULL, 'url')
        ),
      ],
      File::class => [
        'src' => new ReferenceFieldPropExpression(
          new FieldPropExpression($media_entity_type_and_bundle, $source_field_name, \NULL, 'entity'),
          new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', \NULL, 'url')
        ),
        'filename' => new ReferenceFieldPropExpression(
          new FieldPropExpression($media_entity_type_and_bundle, $source_field_name, \NULL, 'entity'),
          new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'filename', \NULL, 'value')
        ),
        'filesize' => new ReferenceFieldPropExpression(
          new FieldPropExpression($media_entity_type_and_bundle, $source_field_name, \NULL, 'entity'),
          new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'filesize', \NULL, 'value')
        ),
        'mimetype' => new ReferenceFieldPropExpression(
          new FieldPropExpression($media_entity_type_and_bundle, $source_field_name, \NULL, 'entity'),
          new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'filemime', \NULL, 'value')
        ),
      ],
      default => throw new \InvalidArgumentException(\sprintf('%s is not a supported Media Source class for shape matching.', $media_source_class)),
    };
  }

}
