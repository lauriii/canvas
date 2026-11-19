<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Field\FieldWidget;

use Drupal\canvas\Plugin\Field\FieldType\VideoEmbedItem;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\UriWidget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\media\OEmbed\Endpoint;

/**
 * Defines the 'canvas_video_embed_url' field widget.
 *
 * A URL input that only accepts URLs of the allowed video providers. The
 * `pattern` attribute lets the browser (and Canvas's UI) reject other URLs
 * before they reach the preview.
 *
 * @see \Drupal\canvas\Plugin\Field\FieldType\VideoEmbedItem
 * @see ui/src/components/form/react-hook-form/fields/componentFieldValidation.ts
 */
#[FieldWidget(
  id: 'canvas_video_embed_url',
  label: new TranslatableMarkup('Video URL'),
  field_types: [VideoEmbedItem::PLUGIN_ID],
)]
final class VideoEmbedUrlWidget extends UriWidget {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);

    $help = $this->t('Paste the URL of a video on @providers.', ['@providers' => \implode(', ', VideoEmbedItem::getAllowedProviderNames())]);
    $element['value']['#description'] = empty($element['value']['#description']) ? $help : $element['value']['#description'];
    // Shown when the URL does not match the pattern below.
    $element['value']['#attributes']['title'] = $help;

    $schemes = \array_merge(...\array_map(
      fn (Endpoint $endpoint): array => $endpoint->getSchemes(),
      VideoEmbedItem::getAllowedEndpoints(),
    ));
    // Without schemes (no provider database), only server-side checks apply.
    // @see \Drupal\canvas\TypedData\VideoEmbedIframeUrl
    if ($schemes !== []) {
      // Converts schemes to regular expressions like core does. The result is
      // valid in both PHP and the HTML `pattern` attribute.
      // @see \Drupal\media\OEmbed\Endpoint::matchUrl()
      $element['value']['#pattern'] = \implode('|', \array_map(
        fn (string $scheme): string => \str_replace(['.', '*', '?'], ['\.', '.*', '\?'], $scheme),
        $schemes,
      ));
    }
    return $element;
  }

}
