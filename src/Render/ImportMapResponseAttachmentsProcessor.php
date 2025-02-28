<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Render;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Render\AttachmentsInterface;
use Drupal\Core\Render\AttachmentsResponseProcessorInterface;

/**
 * Defines a html attachments processor decorator that can handle import maps.
 *
 * Import maps can be attached to any render element using the 'import_maps' key
 * under '#attached'.
 * Each entry should be an array with a key of the scope and the import map
 * entries.
 *
 * Drupal #attached entries are merged so the entry should be an array of arrays
 * keyed by scope, not a single array keyed by scope. This ensures the values
 * aren't merged. This is not dissimilar to how #attached works for 'html_head'.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTML/Element/script/type/importmap
 *
 * @code
 *  $scope = '/modules/mymodule/js/file.js';
 *  $build['i_can_haz_imports'] = [
 *    '#type' => 'container',
 *    'foo' => ['#markup' => 'bar'],
 *    '#attached' => [
 *      'import_maps' => [
 *        [
 *          $scope => [
 *            'preact' => '/path/to/preact.js',
 *          ],
 *        ],
 *      ],
 *    ],
 *  ];
 * @endcode
 */
final class ImportMapResponseAttachmentsProcessor implements AttachmentsResponseProcessorInterface {

  public function __construct(
    private AttachmentsResponseProcessorInterface $htmlResponseAttachmentsProcessor,
  ) {

  }

  /**
   * {@inheritdoc}
   */
  public function processAttachments(AttachmentsInterface $response) {
    $original_attachments = $response->getAttachments();
    $import_maps = $original_attachments['import_maps'] ?? [];
    foreach ($import_maps as $entry) {
      foreach ($entry as $imports) {
        foreach ($imports as $import_url) {
          $original_attachments['html_head_link'][] = [
            [
              'rel' => 'modulepreload',
              'fetchpriority' => 'high',
              'href' => $import_url,
            ],
          ];
        }
      }
    }
    $import_map = self::buildHtmlTagForAttachedImportMaps($response);
    if ($import_map) {
      unset($original_attachments['import_maps']);
      $original_attachments['html_head'][] = [$import_map, 'xb_import_map'];
      // Set the attachments with the new script tag and without the import_maps
      // entry.
      $response->setAttachments($original_attachments);
    }
    // Call the decorated attachments processor.
    return $this->htmlResponseAttachmentsProcessor->processAttachments($response);
  }

  public static function buildHtmlTagForAttachedImportMaps(AttachmentsInterface $something): ?array {
    $import_maps = $something->getAttachments()['import_maps'] ?? [];

    if (\count($import_maps) === 0) {
      return NULL;
    }

    $map = ['scopes' => []];
    // Add each entry to the 'scopes' key.
    // @see https://developer.mozilla.org/en-US/docs/Web/HTML/Element/script/type/importmap#scoped_module_specifier_maps
    foreach ($import_maps as $entry) {
      $map['scopes'] += $entry;
    }
    // Transform it into a standard <script> tag.
    $import_map = [
      '#type' => 'html_tag',
      '#tag' => 'script',
      '#value' => Json::encode($map),
      '#attributes' => ['type' => 'importmap'],
    ];
    return $import_map;
  }

}
