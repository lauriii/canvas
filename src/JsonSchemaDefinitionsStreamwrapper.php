<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\Core\Extension\Exception\UnknownExtensionException;
use Drupal\Core\Extension\Exception\UnknownExtensionTypeException;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\StreamWrapper\LocalReadOnlyStream;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Defines the read-only json-schema-definitions:// stream wrapper.
 *
 * 🙏🤩Heavily inspired by https://git.drupalcode.org/project/ui_patterns/-/blob/28cf60dd776fb349d9520377afa510b0d85f3334/src/SchemaManager/StreamWrapper.php.
 *
 * @todo Investigate which of the other services that the UI Patterns module has are relevant to adopt:   # @see https://git.drupalcode.org/project/ui_patterns/-/blob/28cf60dd776fb349d9520377afa510b0d85f3334/ui_patterns.services.yml#L44-L58
 */
class JsonSchemaDefinitionsStreamwrapper extends LocalReadOnlyStream {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public static function getType() {
    return StreamWrapperInterface::LOCAL | StreamWrapperInterface::READ | StreamWrapperInterface::HIDDEN;
  }

  /**
   * {@inheritdoc}
   */
  // phpcs:disable Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
  public function stream_open($uri, $mode, $options, &$opened_path) {
    $definition = self::getDefinition($uri);
    if ($definition === NULL) {
      // @todo Logging/exception for better DX.
      return FALSE;
    }

    $stream = fopen('php://memory', 'r+');
    if (!\is_resource($stream)) {
      return FALSE;
    }

    $json = json_encode($definition);
    \assert(\is_string($json));
    fwrite($stream, $json);
    rewind($stream);
    $this->handle = $stream;

    return TRUE;
  }

  /**
   * Reads the JSON schema definition addressed by a stream wrapper URI.
   *
   * This resolves the definition directly from the extension's `schema.json`
   * file, without depending on this stream wrapper being registered in PHP's
   * global stream wrapper registry. That registration is not guaranteed during
   * every code path (for example, `hook_rebuild()` while a recipe installs
   * Canvas), so callers that must resolve `$ref`s during those paths use this
   * method instead of `file_get_contents()` on the URI.
   *
   * @param string $uri
   *   A `json-schema-definitions://<extension>.<extension type>/<definition>`
   *   URI.
   *
   * @return array|null
   *   The JSON schema definition as a decoded array, or NULL when the extension
   *   or its `schema.json` cannot be found.
   *
   * @throws \InvalidArgumentException
   *   When the `schema.json` exists but does not contain the requested
   *   definition.
   *
   * @see \Drupal\canvas\Plugin\ComponentPluginManager::resolveJsonSchemaReferences()
   */
  public static function getDefinition(string $uri): ?array {
    try {
      [$extension_path, $definition_name] = self::parseUri($uri);
    }
    catch (UnknownExtensionException | UnknownExtensionTypeException) {
      // @todo Re-throw with more precise exception message for better DX.
      return NULL;
    }
    if (!file_exists($extension_path . DIRECTORY_SEPARATOR . 'schema.json')) {
      return NULL;
    }

    $contents = file_get_contents($extension_path . DIRECTORY_SEPARATOR . 'schema.json');
    \assert(\is_string($contents));
    $json_schema = json_decode($contents, TRUE);
    // @todo validate this file is valid JSON schema.
    if (!\array_key_exists('$defs', $json_schema)) {
      throw new \InvalidArgumentException(\sprintf("%s does not contain any definitions.", $extension_path));
    }
    if (!\array_key_exists($definition_name, $json_schema['$defs'])) {
      throw new \InvalidArgumentException(\sprintf("%s does not contain a `%s` definition.", $extension_path, $definition_name));
    }

    $definition = $json_schema['$defs'][$definition_name];
    \assert(\is_array($definition));
    return $definition;
  }

  /**
   * {@inheritdoc}
   */
  public function getDirectoryPath() {
    // @todo This makes no sense for this stream wrapper, it's an additional concept layered by Drupal onto PHP stream wrappers we don't need; instead extend \Drupal\Core\StreamWrapper\ReadOnlyStream, but this will require implementing many more methods.
    // @phpstan-ignore-next-line
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return $this->t('JSON schema definitions');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('JSON schema definitions, provided by a `schema.json` in the root of any Drupal extension.');
  }

  /**
   * {@inheritdoc}
   */
  public function getExternalUrl() {
    // Perform no validation, just transform the stream wrapper URI to a HTTP(S)
    // URL that is publicly accessible. This is guaranteed to work because a
    // module/theme must live in the document root to be able to serve CSS, JS,
    // images or other assets. The `schema.json` file in the root is just
    // another asset.
    [$extension_path, $definition_name] = self::parseUri($this->uri);
    return Url::fromUri("base:$extension_path/schema.json")
      ->setAbsolute()
      ->setOption('fragment', "defs/$definition_name")
      ->toString(TRUE)
      ->getGeneratedUrl();
  }

  /**
   * @return array{0: string, 1: string}
   */
  private static function parseUri(string $uri): array {
    static $extension_path_resolver;
    // TRICKY: stream wrapper services cannot use dependency injection.
    if (!isset($extension_path_resolver)) {
      $extension_path_resolver = \Drupal::service(ExtensionPathResolver::class);
    }
    \assert($extension_path_resolver instanceof ExtensionPathResolver);

    $url_components = parse_url($uri);
    if ($url_components === FALSE || !\array_key_exists('host', $url_components) || !\array_key_exists('path', $url_components)) {
      throw new \InvalidArgumentException("$uri is not a valid JSON schema definition URI.");
    }
    ['host' => $host, 'path' => $extension_path] = $url_components;
    [$extension_name, $extension_type] = explode('.', $host, 2);
    $definition_name = substr($extension_path, 1);
    $extension_path = $extension_path_resolver->getPath($extension_type, $extension_name);
    return [
      $extension_path,
      $definition_name,
    ];
  }

}
