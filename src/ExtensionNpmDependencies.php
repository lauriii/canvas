<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Collects the npm packages that installed modules and themes declare.
 *
 * A module that ships JavaScript for code components publishes it on npm and
 * declares the package in its info file, so that a CLI project paired with the
 * site depends on the same package at the same version:
 *
 * @code
 * canvas:
 *   npm:
 *     '@acme/canvas-forms': 1.2.0
 *     '@acme/canvas-forms-client':
 *       version: 2.0.0
 *       force: true
 * @endcode
 *
 * The version is the one the extension was built and tested against. A
 * `canvas pull` adds a missing declared package to the project's package.json
 * once and otherwise leaves the developer's values alone, reporting
 * disagreements. A declaration marked `force: true` is one the extension
 * requires: pull sets that version even where the developer has another, and
 * re-adds the package if it was removed. Use it when a module update needs its
 * client package updated with it.
 *
 * @internal
 */
final class ExtensionNpmDependencies {

  public const string INFO_KEY = 'npm';

  /**
   * An npm package name: optional scope, then lowercase URL-safe characters.
   *
   * @see https://github.com/npm/validate-npm-package-name
   */
  private const string PACKAGE_NAME_PATTERN = '/^(?:@[a-z0-9-~][a-z0-9-._~]*\/)?[a-z0-9-~][a-z0-9-._~]*$/';

  /**
   * An exact semantic version, optionally with a pre-release or build suffix.
   *
   * Only an exact version is accepted: it is what the extension was built
   * against, and it is what a build can compare an installed copy to without a
   * range resolver. Anything else (a range, a tag, a URL, a path) is rejected
   * so an info file cannot make a project install arbitrary sources. This is
   * the official SemVer 2.0.0 pattern, so leading zeros and empty identifiers
   * are rejected too.
   *
   * @see https://semver.org/#is-there-a-suggested-regular-expression-regex-to-check-a-semver-string
   */
  private const string VERSION_PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';

  public function __construct(
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly ThemeExtensionList $themeExtensionList,
    #[Autowire(service: 'logger.channel.canvas')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * @return array<string, array{version: string, force: bool}>
   *   Package name => declaration, sorted by package name. Empty when no
   *   installed extension declares any.
   */
  public function getDependencies(): array {
    $dependencies = [];
    // Which extension declared each package, to name both sides of a conflict.
    $declared_by = [];
    foreach ([$this->moduleExtensionList, $this->themeExtensionList] as $extension_list) {
      foreach ($extension_list->getAllInstalledInfo() as $extension => $info) {
        $declared = $info['canvas'][self::INFO_KEY] ?? [];
        if (!\is_array($declared)) {
          continue;
        }
        foreach ($declared as $package => $declaration) {
          $normalized = self::normalizeDeclaration($package, $declaration);
          if ($normalized === NULL) {
            continue;
          }
          if (isset($dependencies[$package]) && $dependencies[$package] !== $normalized) {
            // Two extensions want different things for one package. A project
            // can only install one version, so the choice is deterministic: a
            // forced declaration beats an unforced one, then the higher
            // version wins. The disagreement is logged for the site owner to
            // settle, because the extension that lost gets a copy it did not
            // declare, exactly as when two modules disagree on any library.
            $chosen = self::chooseDeclaration($dependencies[$package], $normalized);
            $this->logger->warning('Extensions @a and @b declare different versions of the npm package @package (@va and @vb). Using @chosen.', [
              '@a' => $declared_by[$package],
              '@b' => $extension,
              '@package' => $package,
              '@va' => self::describe($dependencies[$package]),
              '@vb' => self::describe($normalized),
              '@chosen' => self::describe($chosen),
            ]);
            if ($chosen === $dependencies[$package]) {
              continue;
            }
          }
          $dependencies[$package] = $normalized;
          $declared_by[$package] = $extension;
        }
      }
    }
    \ksort($dependencies);
    return $dependencies;
  }

  /**
   * Normalizes a declaration to its canonical shape, or NULL if it is invalid.
   *
   * A declaration is either the version string, or a mapping with `version`
   * and an optional boolean `force`.
   *
   * @return array{version: string, force: bool}|null
   */
  public static function normalizeDeclaration(mixed $package, mixed $declaration): ?array {
    if (\is_string($declaration)) {
      $declaration = ['version' => $declaration];
    }
    if (!\is_array($declaration)) {
      return NULL;
    }
    $version = $declaration['version'] ?? NULL;
    $force = $declaration['force'] ?? FALSE;
    if (!self::isValidDeclaration($package, $version) || !\is_bool($force)) {
      return NULL;
    }
    \assert(\is_string($version));
    return ['version' => $version, 'force' => $force];
  }

  /**
   * Whether an entry is a well-formed npm package name and an exact version.
   */
  public static function isValidDeclaration(mixed $package, mixed $version): bool {
    return \is_string($package)
      && \is_string($version)
      && \strlen($package) <= 214
      && \preg_match(self::PACKAGE_NAME_PATTERN, $package) === 1
      && \preg_match(self::VERSION_PATTERN, $version) === 1;
  }

  /**
   * Picks between two declarations of one package: forced, then higher.
   *
   * @param array{version: string, force: bool} $a
   * @param array{version: string, force: bool} $b
   *
   * @return array{version: string, force: bool}
   */
  private static function chooseDeclaration(array $a, array $b): array {
    if ($a['force'] !== $b['force']) {
      return $a['force'] ? $a : $b;
    }
    return \version_compare($b['version'], $a['version'], '>') ? $b : $a;
  }

  /**
   * @param array{version: string, force: bool} $declaration
   */
  private static function describe(array $declaration): string {
    return $declaration['version'] . ($declaration['force'] ? ' (forced)' : '');
  }

}
