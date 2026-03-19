<?php

declare(strict_types=1);

// cspell:ignore msword openxmlformats officedocument wordprocessingml

namespace Drupal\Tests\canvas\Kernel\ShapeMatcher;

use Drupal\canvas\PropShape\PropShape;
use Drupal\canvas\PropSource\PropSource;
use Drupal\canvas\ShapeMatcher\EntityFieldPropSourceMatcher;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\link\LinkItemInterface;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\field\Traits\EntityReferenceFieldCreationTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[CoversClass(EntityFieldPropSourceMatcher::class)]
#[Group('canvas')]
#[Group('canvas_shape_matching')]
class EntityFieldPropSourceMatcherTest extends PropSourceMatcherTestBase {

  use EntityReferenceFieldCreationTrait;
  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected string $testedPropSourceMatcherClass = EntityFieldPropSourceMatcher::class;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // Create sample configurable fields on the `node` entity type.
    'field',
    'node',
    // All other core modules providing field types (in addition to the ones
    // installed by CanvasKernelTestBase).
    'comment',
    'datetime_range',
    'telephone',
  ];

  /**
   * {@inheritdoc}
   *
   * The same as the parent, but now keyed by entity Typed Data data type.
   *
   * Note: each of these expected matches is just an array representation of
   * an EntityFieldPropSource. For example:
   * @code
   * (new EntityFieldPropSource(StructuredDataPropExpression::fromString('ℹ︎␜entity:user␝default_langcode␞␟value')))->toArray()
   * @endcode
   * matches the first expectation.
   */
  protected array $expectedMatches = [
    // Only provide test expectations for content entity types for which a
    // Content Template could make sense.
    'entity:canvas_page' => FALSE,
    'entity:path_alias' => FALSE,
    'entity:file' => FALSE,
    // This would be 99% identical to `entity:media:baby_videos`.
    'entity:media:vacation_videos' => FALSE,
    // An entity type with typically zero configurable fields.
    'entity:user' => [
      'type=boolean!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:user␝default_langcode␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:user␝status␞␟value'],
      ],
      'type=integer&maximum=2147483648&minimum=-2147483648!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:user␝access␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:user␝changed␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:user␝created␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:user␝login␞␟value'],
      ],
      'type=string' => ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:user␝name␞␟value'],
      'type=string&format=email!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:user␝init␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:user␝mail␞␟value'],
      ],
      'type=string&format=idn-email!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:user␝init␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:user␝mail␞␟value'],
      ],
      'type=string&minLength=2' => ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:user␝name␞␟value'],
    ],
    // The typical example; with a variety of field types.
    'entity:node:foo' => [
      'type=boolean!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝default_langcode␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝status␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media:press_releases␝field_media_file␞␟display'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝default_langcode␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝revision_default␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝status␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟display'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝default_langcode␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝revision_default␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝status␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media:baby_videos␝field_media_video_file␞␟display'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟display'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝default_langcode␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝revision_default␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝status␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝promote␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝revision_default␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝default_langcode␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝status␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝status␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝sticky␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝default_langcode␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝status␞␟value'],
      ],
      'type=integer' => ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝one_from_an_integer_list␞␟value'],
      'type=integer!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝filesize␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟height'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟width'],
      ],
      'type=integer&maximum=2147483648&minimum=-2147483648!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝changed␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝created␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝changed␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝created␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝changed␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝created␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝revision_created␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝changed␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝created␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝revision_created␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝changed␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝created␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝revision_created␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝revision_timestamp␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝access␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝changed␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝created␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝login␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝access␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝changed␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝created␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝login␞␟value'],
      ],
      'type=number' => ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝one_from_an_integer_list␞␟value'],
      'type=number!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝filesize␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟height'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟width'],
      ],
      'type=object&$ref=json-schema-definitions://canvas.module/date-range' => ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_event_duration␞␟{from↠value,to↠end_value}'],
      'type=object&$ref=json-schema-definitions://canvas.module/image' => ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}'],
      'type=object&$ref=json-schema-definitions://canvas.module/image!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝thumbnail␞␟{src↠src_with_alternate_widths,width↝entity␜␜entity:file␝filesize␞␟value}'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟{src↝entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟url,alt↝entity␜␜entity:media␝revision_user␞␟entity␜␜entity:user␝name␞␟value,width↝entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝filesize␞␟value,height↝entity␜␜entity:media:press_releases␝field_media_file␞␟entity␜␜entity:file␝filesize␞␟value}'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝thumbnail␞␟{src↠src_with_alternate_widths,width↝entity␜␜entity:file␝filesize␞␟value}'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟{src↝entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟url,alt↝entity␜␜entity:media␝revision_user␞␟entity␜␜entity:user␝name␞␟value,width↝entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝filesize␞␟value,height↝entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟entity␜␜entity:file␝filesize␞␟value}'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝thumbnail␞␟{src↠src_with_alternate_widths,width↝entity␜␜entity:file␝filesize␞␟value}'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟{src↝entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟url,alt↝entity␜␜entity:media␝revision_user␞␟entity␜␜entity:user␝name␞␟value,width↝entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝filesize␞␟value,height↝entity␜␜entity:media:baby_videos␝field_media_video_file␞␟entity␜␜entity:file␝filesize␞␟value}'],
      ],
      'type=object&$ref=json-schema-definitions://canvas.module/video' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟{src↝entity␜␜entity:file␝uri␞␟url}'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟{src↝entity␜␜entity:media:baby_videos␝field_media_video_file␞␟entity␜␜entity:file␝uri␞␟url,poster↝entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟url}'],
      ],
      'type=object&$ref=json-schema-definitions://canvas.module/video!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟{src↝entity␜␜entity:file␝uri␞␟url}'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟{src↝entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟entity␜␜entity:file␝uri␞␟url,poster↝entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟url}'],
      ],
      'type=string' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝name␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝name␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝one_from_an_string_list␞␟label'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝title␞␟value'],
      ],
      'type=string!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_check_it_out␞␟title'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟alt'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟title'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media:press_releases␝field_media_file␞␟description'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝revision_log_message␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟description'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝name␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝revision_log_message␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media:baby_videos␝field_media_video_file␞␟description'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟description'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝revision_log_message␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝revision_log␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝name␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝name␞␟value'],
      ],
      'type=string&$ref=json-schema-definitions://canvas.module/image-uri' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟src_with_alternate_widths'],
      ],
      'type=string&$ref=json-schema-definitions://canvas.module/image-uri!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝thumbnail␞␟src_with_alternate_widths'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝thumbnail␞␟src_with_alternate_widths'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝thumbnail␞␟src_with_alternate_widths'],
      ],
      'type=string&$ref=json-schema-definitions://canvas.module/stream-wrapper-image-uri' => ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value'],
      'type=string&$ref=json-schema-definitions://canvas.module/stream-wrapper-image-uri!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟value'],
      ],
      'type=string&$ref=json-schema-definitions://canvas.module/stream-wrapper-uri' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media:baby_videos␝field_media_video_file␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟entity␜␜entity:file␝uri␞␟value'],
      ],
      'type=string&$ref=json-schema-definitions://canvas.module/stream-wrapper-uri!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟value'],
      ],
      'type=string&format=date' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_event_duration␞␟end_value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_event_duration␞␟value'],
      ],
      'type=string&format=date-time' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_event_duration␞␟end_value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_event_duration␞␟value'],
      ],
      'type=string&format=email!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝init␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝mail␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝init␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝mail␞␟value'],
      ],
      'type=string&format=idn-email!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝init␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝mail␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝init␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝mail␞␟value'],
      ],
      'type=string&format=iri' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media:baby_videos␝field_media_video_file␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟entity␜␜entity:file␝uri␞␟value'],
      ],
      'type=string&format=iri!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟value'],
      ],
      'type=string&format=iri-reference' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_check_it_out␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟src_with_alternate_widths'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media:baby_videos␝field_media_video_file␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media:baby_videos␝field_media_video_file␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟url'],
      ],
      'type=string&format=iri-reference!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_check_it_out␞␟uri'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media:press_releases␝field_media_file␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝revision_user␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝thumbnail␞␟src_with_alternate_widths'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝revision_user␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝thumbnail␞␟src_with_alternate_widths'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media:baby_videos␝field_media_video_file␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝revision_user␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝thumbnail␞␟src_with_alternate_widths'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟url'],
      ],
      'type=string&format=uri' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media:baby_videos␝field_media_video_file␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟entity␜␜entity:file␝uri␞␟value'],
      ],
      'type=string&format=uri!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟value'],
      ],
      'type=string&format=uri-reference' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_check_it_out␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟src_with_alternate_widths'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media:baby_videos␝field_media_video_file␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media:baby_videos␝field_media_video_file␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟url'],
      ],
      'type=string&format=uri-reference!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_check_it_out␞␟uri'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media:press_releases␝field_media_file␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝revision_user␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝thumbnail␞␟src_with_alternate_widths'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝revision_user␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝thumbnail␞␟src_with_alternate_widths'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media:baby_videos␝field_media_video_file␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝revision_user␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝thumbnail␞␟src_with_alternate_widths'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟url'],
      ],
      'type=string&format=uri-reference&x-allowed-schemes[0]=http&x-allowed-schemes[1]=https' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_check_it_out␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟src_with_alternate_widths'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media:baby_videos␝field_media_video_file␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟url'],
      ],
      'type=string&format=uri-reference&x-allowed-schemes[0]=http&x-allowed-schemes[1]=https!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media:press_releases␝field_media_file␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝revision_user␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝thumbnail␞␟src_with_alternate_widths'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝revision_user␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝thumbnail␞␟src_with_alternate_widths'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media:baby_videos␝field_media_video_file␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝revision_user␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝thumbnail␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝thumbnail␞␟src_with_alternate_widths'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟url'],
      ],
      'type=string&minLength=2' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝name␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝name␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝one_from_an_string_list␞␟label'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝title␞␟value'],
      ],
      'type=string&minLength=2!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_check_it_out␞␟title'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟alt'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝field_silly_image␞␟title'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media:press_releases␝field_media_file␞␟description'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝revision_log_message␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟description'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝name␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝revision_log_message␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media:baby_videos␝field_media_video_file␞␟description'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media:vacation_videos␝field_media_video_file_1␞␟description'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝revision_log_message␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝revision_log␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝name␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝uid␞␟entity␜␜entity:user␝name␞␟value'],
      ],
      'type=string&pattern=(.|\r?\n)*!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝marketing_docs␞␟entity␜␜entity:media␝revision_log_message␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_optional_vacation_videos␞␟entity␜␜entity:media␝revision_log_message␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝media_video_field␞␟entity␜␜entity:media␝revision_log_message␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:foo␝revision_log␞␟value'],
      ],
    ],
    // Two different media types — each powered by a different MediaSource.
    'entity:media:press_releases' => [
      'type=boolean!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝default_langcode␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝field_media_file␞␟display'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝field_media_file␞␟entity␜␜entity:file␝status␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝revision_default␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝revision_user␞␟entity␜␜entity:user␝default_langcode␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝revision_user␞␟entity␜␜entity:user␝status␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝status␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝thumbnail␞␟entity␜␜entity:file␝status␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝uid␞␟entity␜␜entity:user␝default_langcode␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝uid␞␟entity␜␜entity:user␝status␞␟value'],
      ],
      'type=integer!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝field_media_file␞␟entity␜␜entity:file␝filesize␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝thumbnail␞␟entity␜␜entity:file␝filesize␞␟value'],
      ],
      'type=integer&maximum=2147483648&minimum=-2147483648!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝changed␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝created␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝field_media_file␞␟entity␜␜entity:file␝changed␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝field_media_file␞␟entity␜␜entity:file␝created␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝revision_created␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝revision_user␞␟entity␜␜entity:user␝access␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝revision_user␞␟entity␜␜entity:user␝changed␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝revision_user␞␟entity␜␜entity:user␝created␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝revision_user␞␟entity␜␜entity:user␝login␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝thumbnail␞␟entity␜␜entity:file␝changed␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝thumbnail␞␟entity␜␜entity:file␝created␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝uid␞␟entity␜␜entity:user␝access␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝uid␞␟entity␜␜entity:user␝changed␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝uid␞␟entity␜␜entity:user␝created␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝uid␞␟entity␜␜entity:user␝login␞␟value'],
      ],
      'type=number!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝field_media_file␞␟entity␜␜entity:file␝filesize␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝thumbnail␞␟entity␜␜entity:file␝filesize␞␟value'],
      ],
      'type=object&$ref=json-schema-definitions://canvas.module/image!optional' => [
        'sourceType' => PropSource::EntityField->value,
        'expression' => 'ℹ︎␜entity:media:press_releases␝thumbnail␞␟{src↠src_with_alternate_widths,alt↝entity␜␜entity:file␝uid␞␟entity␜␜entity:user␝name␞␟value,width↝entity␜␜entity:file␝filesize␞␟value}',
      ],
      'type=string' => ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝name␞␟value'],
      'type=string!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝field_media_file␞␟description'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝revision_log_message␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝revision_user␞␟entity␜␜entity:user␝name␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝uid␞␟entity␜␜entity:user␝name␞␟value'],
      ],
      'type=string&$ref=json-schema-definitions://canvas.module/image-uri!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝thumbnail␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝thumbnail␞␟src_with_alternate_widths'],
      ],
      'type=string&$ref=json-schema-definitions://canvas.module/stream-wrapper-image-uri!optional' => ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝thumbnail␞␟entity␜␜entity:file␝uri␞␟value'],
      'type=string&$ref=json-schema-definitions://canvas.module/stream-wrapper-uri!optional' => ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝thumbnail␞␟entity␜␜entity:file␝uri␞␟value'],
      'type=string&format=email!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝revision_user␞␟entity␜␜entity:user␝init␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝revision_user␞␟entity␜␜entity:user␝mail␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝uid␞␟entity␜␜entity:user␝init␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝uid␞␟entity␜␜entity:user␝mail␞␟value'],
      ],
      'type=string&format=idn-email!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝revision_user␞␟entity␜␜entity:user␝init␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝revision_user␞␟entity␜␜entity:user␝mail␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝uid␞␟entity␜␜entity:user␝init␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝uid␞␟entity␜␜entity:user␝mail␞␟value'],
      ],
      'type=string&format=iri!optional' => ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝thumbnail␞␟entity␜␜entity:file␝uri␞␟value'],
      'type=string&format=iri-reference!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝field_media_file␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝revision_user␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝thumbnail␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝thumbnail␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝thumbnail␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝thumbnail␞␟src_with_alternate_widths'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝uid␞␟url'],
      ],
      'type=string&format=uri!optional' => ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝thumbnail␞␟entity␜␜entity:file␝uri␞␟value'],
      'type=string&format=uri-reference!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝field_media_file␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝revision_user␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝thumbnail␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝thumbnail␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝thumbnail␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝thumbnail␞␟src_with_alternate_widths'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝uid␞␟url'],
      ],
      'type=string&format=uri-reference&x-allowed-schemes[0]=http&x-allowed-schemes[1]=https!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝field_media_file␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝revision_user␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝thumbnail␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝thumbnail␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝thumbnail␞␟src_with_alternate_widths'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝uid␞␟url'],
      ],
      'type=string&minLength=2' => ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝name␞␟value'],
      'type=string&minLength=2!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝field_media_file␞␟description'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝revision_log_message␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝revision_user␞␟entity␜␜entity:user␝name␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝uid␞␟entity␜␜entity:user␝name␞␟value'],
      ],
      'type=string&pattern=(.|\r?\n)*!optional' => ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:press_releases␝revision_log_message␞␟value'],
    ],
    'entity:media:baby_videos' => [
      'type=boolean!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝default_langcode␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝field_media_video_file␞␟display'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝field_media_video_file␞␟entity␜␜entity:file␝status␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝revision_default␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝revision_user␞␟entity␜␜entity:user␝default_langcode␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝revision_user␞␟entity␜␜entity:user␝status␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝status␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝thumbnail␞␟entity␜␜entity:file␝status␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝uid␞␟entity␜␜entity:user␝default_langcode␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝uid␞␟entity␜␜entity:user␝status␞␟value'],
      ],
      'type=integer!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝field_media_video_file␞␟entity␜␜entity:file␝filesize␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝thumbnail␞␟entity␜␜entity:file␝filesize␞␟value'],
      ],
      'type=integer&maximum=2147483648&minimum=-2147483648!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝changed␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝created␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝field_media_video_file␞␟entity␜␜entity:file␝changed␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝field_media_video_file␞␟entity␜␜entity:file␝created␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝revision_created␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝revision_user␞␟entity␜␜entity:user␝access␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝revision_user␞␟entity␜␜entity:user␝changed␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝revision_user␞␟entity␜␜entity:user␝created␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝revision_user␞␟entity␜␜entity:user␝login␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝thumbnail␞␟entity␜␜entity:file␝changed␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝thumbnail␞␟entity␜␜entity:file␝created␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝uid␞␟entity␜␜entity:user␝access␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝uid␞␟entity␜␜entity:user␝changed␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝uid␞␟entity␜␜entity:user␝created␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝uid␞␟entity␜␜entity:user␝login␞␟value'],
      ],
      'type=number!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝field_media_video_file␞␟entity␜␜entity:file␝filesize␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝thumbnail␞␟entity␜␜entity:file␝filesize␞␟value'],
      ],
      'type=object&$ref=json-schema-definitions://canvas.module/image!optional' => [
        'sourceType' => PropSource::EntityField->value,
        'expression' => 'ℹ︎␜entity:media:baby_videos␝thumbnail␞␟{src↠src_with_alternate_widths,alt↝entity␜␜entity:file␝uid␞␟entity␜␜entity:user␝name␞␟value,width↝entity␜␜entity:file␝filesize␞␟value}',
      ],
      'type=object&$ref=json-schema-definitions://canvas.module/video' => ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝field_media_video_file␞␟{src↝entity␜␜entity:file␝uri␞␟url}'],
      'type=string' => ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝name␞␟value'],
      'type=string!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝field_media_video_file␞␟description'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝revision_log_message␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝revision_user␞␟entity␜␜entity:user␝name␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝uid␞␟entity␜␜entity:user␝name␞␟value'],
      ],
      'type=string&$ref=json-schema-definitions://canvas.module/image-uri!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝thumbnail␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝thumbnail␞␟src_with_alternate_widths'],
      ],
      'type=string&$ref=json-schema-definitions://canvas.module/stream-wrapper-image-uri!optional' => ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝thumbnail␞␟entity␜␜entity:file␝uri␞␟value'],
      'type=string&$ref=json-schema-definitions://canvas.module/stream-wrapper-uri' => ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝field_media_video_file␞␟entity␜␜entity:file␝uri␞␟value'],
      'type=string&$ref=json-schema-definitions://canvas.module/stream-wrapper-uri!optional' => ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝thumbnail␞␟entity␜␜entity:file␝uri␞␟value'],
      'type=string&format=email!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝revision_user␞␟entity␜␜entity:user␝init␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝revision_user␞␟entity␜␜entity:user␝mail␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝uid␞␟entity␜␜entity:user␝init␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝uid␞␟entity␜␜entity:user␝mail␞␟value'],
      ],
      'type=string&format=idn-email!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝revision_user␞␟entity␜␜entity:user␝init␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝revision_user␞␟entity␜␜entity:user␝mail␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝uid␞␟entity␜␜entity:user␝init␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝uid␞␟entity␜␜entity:user␝mail␞␟value'],
      ],
      'type=string&format=iri' => ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝field_media_video_file␞␟entity␜␜entity:file␝uri␞␟value'],
      'type=string&format=iri!optional' => ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝thumbnail␞␟entity␜␜entity:file␝uri␞␟value'],
      'type=string&format=iri-reference' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝field_media_video_file␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝field_media_video_file␞␟entity␜␜entity:file␝uri␞␟value'],
      ],
      'type=string&format=iri-reference!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝field_media_video_file␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝revision_user␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝thumbnail␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝thumbnail␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝thumbnail␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝thumbnail␞␟src_with_alternate_widths'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝uid␞␟url'],
      ],
      'type=string&format=uri' => ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝field_media_video_file␞␟entity␜␜entity:file␝uri␞␟value'],
      'type=string&format=uri!optional' => ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝thumbnail␞␟entity␜␜entity:file␝uri␞␟value'],
      'type=string&format=uri-reference' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝field_media_video_file␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝field_media_video_file␞␟entity␜␜entity:file␝uri␞␟value'],
      ],
      'type=string&format=uri-reference!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝field_media_video_file␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝revision_user␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝thumbnail␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝thumbnail␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝thumbnail␞␟entity␜␜entity:file␝uri␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝thumbnail␞␟src_with_alternate_widths'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝uid␞␟url'],
      ],
      'type=string&format=uri-reference&x-allowed-schemes[0]=http&x-allowed-schemes[1]=https' => ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝field_media_video_file␞␟entity␜␜entity:file␝uri␞␟url'],
      'type=string&format=uri-reference&x-allowed-schemes[0]=http&x-allowed-schemes[1]=https!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝field_media_video_file␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝revision_user␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝thumbnail␞␟entity␜␜entity:file␝uid␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝thumbnail␞␟entity␜␜entity:file␝uri␞␟url'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝thumbnail␞␟src_with_alternate_widths'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝uid␞␟url'],
      ],
      'type=string&minLength=2' => ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝name␞␟value'],
      'type=string&minLength=2!optional' => [
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝field_media_video_file␞␟description'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝revision_log_message␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝revision_user␞␟entity␜␜entity:user␝name␞␟value'],
        ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝uid␞␟entity␜␜entity:user␝name␞␟value'],
      ],
      'type=string&pattern=(.|\r?\n)*!optional' => ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:media:baby_videos␝revision_log_message␞␟value'],
    ],
  ];

  protected ?string $testedHostEntityTypeId = NULL;
  protected ?string $testedHostEntityBundle = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('field_storage_config');
    $this->installEntitySchema('field_config');
    // Create a "Foo" node type.
    NodeType::create([
      'name' => 'Foo',
      'type' => 'foo',
    ])->save();
    // Create a "silly image" field on the "Foo" node type.
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_silly_image',
      'type' => 'image',
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_silly_image',
      'bundle' => 'foo',
      'required' => TRUE,
    ])->save();
    // Create a "check it out" field.
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_check_it_out',
      'type' => 'link',
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_check_it_out',
      'bundle' => 'foo',
      'required' => TRUE,
      'settings' => [
        'title' => DRUPAL_OPTIONAL,
        'link_type' => LinkItemInterface::LINK_GENERIC,
      ],
    ])->save();
    // Create a "event duration" field on the "Foo" node type.
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_event_duration',
      'type' => 'daterange',
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_event_duration',
      'bundle' => 'foo',
      'required' => TRUE,
    ])->save();
    $this->createMediaType('video_file', ['id' => 'baby_videos']);
    $this->createMediaType('video_file', ['id' => 'vacation_videos']);
    FieldStorageConfig::create([
      'field_name' => 'media_video_field',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => [
        'target_type' => 'media',
      ],
    ])->save();
    FieldConfig::create([
      'label' => 'A Media Video Field',
      'field_name' => 'media_video_field',
      'entity_type' => 'node',
      'bundle' => 'foo',
      'field_type' => 'entity_reference',
      'required' => TRUE,
      'settings' => [
        'handler_settings' => [
          'target_bundles' => [
            'baby_videos' => 'baby_videos',
            'vacation_videos' => 'vacation_videos',
          ],
        ],
      ],
    ])->save();
    // Optional, single-cardinality video media reference field.
    FieldStorageConfig::create([
      'field_name' => 'media_optional_vacation_videos',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => [
        'target_type' => 'media',
      ],
    ])->save();
    FieldConfig::create([
      'label' => 'Vacation videos',
      'field_name' => 'media_optional_vacation_videos',
      'entity_type' => 'node',
      'bundle' => 'foo',
      'field_type' => 'entity_reference',
      'required' => FALSE,
      'settings' => [
        'handler_settings' => [
          'target_bundles' => [
            'vacation_videos' => 'vacation_videos',
          ],
        ],
      ],
    ])->save();
    $this->createMediaType('file', ['id' => 'press_releases']);
    FieldStorageConfig::create([
      'field_name' => 'marketing_docs',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => [
        'target_type' => 'media',
      ],
    ])->save();
    FieldConfig::create([
      'label' => 'Marketing docs',
      'field_name' => 'marketing_docs',
      'entity_type' => 'node',
      'bundle' => 'foo',
      'field_type' => 'entity_reference',
      'required' => TRUE,
      'settings' => [
        'handler_settings' => [
          'target_bundles' => [
            // Targets `text/*` *and* `application/*`! Specifically:
            // - text/plain
            // - application/msword
            // - application/vnd.openxmlformats-officedocument.wordprocessingml.document
            // - application/pdf
            'press_releases' => 'press_releases',
          ],
        ],
      ],
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'one_from_an_integer_list',
      'entity_type' => 'node',
      'type' => 'list_integer',
      'cardinality' => 1,
      'settings' => [
        'allowed_values' => [
          // Make sure that 0 works as an option.
          0 => 'Zero',
          1 => 'One',
          // Make sure that option text is properly sanitized.
          2 => 'Some <script>dangerous</script> & unescaped <strong>markup</strong>',
        ],
      ],
    ])->save();
    FieldConfig::create([
      'label' => 'A pre-defined integer',
      'field_name' => 'one_from_an_integer_list',
      'entity_type' => 'node',
      'bundle' => 'foo',
      'field_type' => 'list_integer',
      'required' => TRUE,
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'one_from_an_string_list',
      'entity_type' => 'node',
      'type' => 'list_string',
      'cardinality' => 1,
      'settings' => [
        'allowed_values' => [
          'first_key' => 'First Value',
          'second_key' => 'Second Value',
          // Make sure that the allowed value's label is properly sanitized.
          'sanitization_required' => 'Some <script>dangerous</script> & unescaped <strong>markup</strong>',
        ],
      ],
    ])->save();
    FieldConfig::create([
      'label' => 'A pre-defined string',
      'field_name' => 'one_from_an_string_list',
      'entity_type' => 'node',
      'bundle' => 'foo',
      'field_type' => 'list_string',
      'required' => TRUE,
    ])->save();
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->getDefinition(EntityFieldPropSourceMatcher::class)->setPublic(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function performMatch(bool $is_required, PropShape $prop_shape): array {
    $matcher = \Drupal::service(EntityFieldPropSourceMatcher::class);
    \assert($matcher instanceof EntityFieldPropSourceMatcher);
    \assert(\is_string($this->testedHostEntityTypeId));
    \assert(\is_string($this->testedHostEntityBundle));
    return $matcher->match($is_required, $prop_shape, $this->testedHostEntityTypeId, $this->testedHostEntityBundle);
  }

  public function test(): void {
    $original_expected_matches = $this->expectedMatches;

    // Gather the full list of fieldable entity types' IDs and bundles to find
    // matches for.
    $entity_types = $this->container->get(EntityTypeManagerInterface::class)->getDefinitions();
    $bundle_info = $this->container->get(EntityTypeBundleInfoInterface::class);
    foreach ($entity_types as $entity_type_id => $entity_type) {
      if (!$entity_type->entityClassImplements(FieldableEntityInterface::class)) {
        continue;
      }
      $bundles = \array_keys($bundle_info->getBundleInfo($entity_type_id));
      foreach ($bundles as $bundle) {
        $entity_type_and_bundle = EntityDataDefinition::create($entity_type_id, $bundle);
        if (!\array_key_exists($entity_type_and_bundle->getDataType(), $original_expected_matches)) {
          throw new \OutOfRangeException('Test expectations incomplete — missing: ' . $entity_type_and_bundle->getDataType());
        }
        $expectations = $original_expected_matches[$entity_type_and_bundle->getDataType()];
        if ($expectations === FALSE) {
          // It may be technically possible to compute matches, but they would
          // never be used. Do not test what is not used. That increases the
          // maintenance burden unnecessarily.
          continue;
        }

        // Manipulate $this->expectedMatches and then call the parent. The
        // parent can hence remain simple.
        $this->expectedMatches = $expectations;
        $this->testedHostEntityTypeId = $entity_type_id;
        $this->testedHostEntityBundle = $bundle;
        parent::test();
      }
    }
  }

}
