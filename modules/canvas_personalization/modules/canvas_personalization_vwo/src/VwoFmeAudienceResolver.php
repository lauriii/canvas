<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization_vwo;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use wingify\Wingify;
use wingify\WingifyBuilder;
use wingify\WingifyClient;

/**
 * Resolves audience membership through VWO's FME PHP SDK.
 *
 * VWO has no API that answers "is visitor X in audience Y". Its audiences are
 * an evaluation *input* to a campaign or a feature flag, never a queryable
 * output: the REST API has no audiences, segments or profiles endpoints; the
 * one UUID-keyed endpoint returns campaigns and variations with no segment
 * field, is Enterprise-only, and is rate limited to one request per second per
 * token — unusable in a render path; and Data360 segments are documented as
 * post-segmentation for reports only.
 *
 * So an audience is modeled as an FME feature flag whose rule targets it, and
 * membership is that flag being enabled for the visitor. The SDK evaluates the
 * flag locally against a downloaded settings file, which is what makes this
 * viable at render time at all.
 *
 * Two consequences a site builder has to know, both VWO's, not Canvas':
 * - Only targeting the SDK can evaluate from the settings file applies.
 *   Behavioral and historical conditions, and VWO Testing or Data360
 *   audiences, are not reachable from a server at all.
 * - Geolocation and user-agent targeting need VWO's self-hosted Gateway
 *   Service, which this module does not wire up; a flag whose rules use them
 *   will not evaluate correctly here.
 *
 * @see https://developers.wingify.com/v2/docs/fme-unified-experimentation-identity-synchronization.md
 * @see https://developers.wingify.com/v2/docs/fme-php-flags.md
 */
final class VwoFmeAudienceResolver implements VwoAudienceResolverInterface {

  /**
   * Where the credentials live: $settings['canvas_personalization_vwo'].
   *
   * Kept out of configuration on purpose. Segment rules are exported with site
   * config and readable over the segment HTTP API, so a secret placed there
   * leaks; the plugin holds only the flag key.
   */
  public const string SETTINGS_KEY = 'canvas_personalization_vwo';

  /**
   * The SDK's own settings-fetch timeout defaults to 50 seconds.
   *
   * That is a page-hanging default for a synchronous runtime, so a timeout is
   * always passed explicitly.
   */
  private const int DEFAULT_TIMEOUT_MS = 2000;

  /**
   * The least retrying the SDK can be asked to do, to bound a VWO outage.
   *
   * By default the SDK retries a failed network call three times, sleeping 2,
   * 4, then 8 seconds between attempts with a synchronous `sleep()`. Against
   * an unreachable VWO that is roughly 22 seconds of blocked page render — far
   * past anything a variant is worth.
   *
   * It cannot be turned off. `shouldRetry => FALSE` reads as "no retries" but
   * gates the request loop itself, so the SDK makes no call at all and every
   * lookup fails; and `maxRetries` is validated as `>= 1`, with an invalid
   * value making `Wingify::init()` return NULL. One retry after a one second
   * sleep is therefore the floor, putting the worst case at
   * `2 × timeout_ms + 1s` rather than at the timeout alone.
   *
   * @see \wingify\Packages\NetworkLayer\Client\NetworkClient::makeCurlRequest()
   * @see \wingify\Packages\NetworkLayer\Manager\NetworkManager::validateRetryConfig()
   */
  private const array MINIMUM_RETRIES = [
    'shouldRetry' => TRUE,
    'maxRetries' => 1,
    'initialDelay' => 1,
    'backoffMultiplier' => 2,
  ];

  public function __construct(
    private readonly CacheBackendInterface $cache,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function isInAudience(string $flag_key, string $visitor_uuid): bool {
    if (!\class_exists(Wingify::class)) {
      throw new \RuntimeException('The VWO FME PHP SDK is not installed; run `composer require wingify/wingify-fme-php-sdk`.');
    }
    $credentials = Settings::get(self::SETTINGS_KEY, []);
    $sdk_key = $credentials['sdk_key'] ?? NULL;
    $account_id = $credentials['account_id'] ?? NULL;
    if (!\is_string($sdk_key) || $sdk_key === '' || $account_id === NULL) {
      throw new \RuntimeException(\sprintf('VWO credentials are missing; set $settings[\'%s\'] to an array with `sdk_key` and `account_id`.', self::SETTINGS_KEY));
    }

    $client = $this->client($sdk_key, (string) $account_id);
    // ⚠️ `timeout_ms` bounds the settings fetch and nothing else. Every
    // ::getFlag() also posts an impression to VWO's events host over a socket
    // the SDK opens with no timeout of its own, and the SDK exposes no seam to
    // bound, disable or batch that call away — `batchEvents` is accepted and
    // then ignored, its implementation commented out upstream. Measured
    // against an events host that blackholes packets, this adds ~10s to every
    // membership lookup that is not already cached. See the README.
    //
    // `useIdForWeb` makes the SDK reject a UUID it would not accept as a
    // browser-issued one instead of silently deriving a different, server-side
    // UUID — which would bucket the visitor differently from the browser and
    // serve a variant that disagrees with what VWO reports.
    $flag = $client->getFlag($flag_key, [
      'id' => $visitor_uuid,
      'useIdForWeb' => TRUE,
    ]);
    return (bool) $flag->isEnabled();
  }

  /**
   * {@inheritdoc}
   */
  public function listAudiences(): array {
    if (!\class_exists(Wingify::class)) {
      return [];
    }
    $credentials = Settings::get(self::SETTINGS_KEY, []);
    $sdk_key = $credentials['sdk_key'] ?? NULL;
    $account_id = $credentials['account_id'] ?? NULL;
    if (!\is_string($sdk_key) || $sdk_key === '' || $account_id === NULL) {
      return [];
    }
    // The settings file is the flag catalogue, and membership resolution
    // already caches it, so listing normally costs nothing. Only a cold cache
    // pays for a fetch, and ::client() is what fills it.
    $cid = self::settingsCacheId($sdk_key, (string) $account_id);
    $cached = $this->cache->get($cid);
    if ($cached === FALSE || !\is_string($cached->data)) {
      try {
        $this->client($sdk_key, (string) $account_id);
      }
      catch (\Throwable) {
        // An authoring UI that cannot list audiences degrades to a text field.
        return [];
      }
      $cached = $this->cache->get($cid);
    }
    if ($cached === FALSE || !\is_string($cached->data)) {
      return [];
    }
    $settings = \json_decode($cached->data, TRUE);
    $audiences = [];
    foreach ((\is_array($settings) ? $settings['features'] ?? [] : []) as $feature) {
      if (!\is_array($feature) || !\is_string($feature['key'] ?? NULL)) {
        continue;
      }
      $audiences[$feature['key']] = \is_string($feature['name'] ?? NULL) && $feature['name'] !== ''
        ? $feature['name']
        : $feature['key'];
    }
    \asort($audiences);
    return $audiences;
  }

  /**
   * Where the settings file for one account and key is cached.
   */
  private static function settingsCacheId(string $sdk_key, string $account_id): string {
    return 'canvas_personalization_vwo:settings:' . \hash('xxh128', $sdk_key . ':' . $account_id);
  }

  /**
   * The configured ceiling on any single call to VWO, in milliseconds.
   */
  private function timeoutMs(): int {
    $timeout = (int) $this->configFactory->get('canvas_personalization_vwo.settings')->get('timeout_ms');
    return $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT_MS;
  }

  /**
   * An initialized SDK client, reusing a cached settings file where possible.
   *
   * PHP is shared-nothing, so without this every lookup would fetch VWO's
   * settings file from their CDN inside the page render. The settings file is
   * account-wide and small; caching it for `settings_ttl` turns the common
   * case into a local evaluation with no network at all.
   */
  private function client(string $sdk_key, string $account_id): WingifyClient {
    $settings = $this->configFactory->get('canvas_personalization_vwo.settings');
    $options = [
      'sdkKey' => $sdk_key,
      'accountId' => $account_id,
      'settingsConfig' => ['timeout' => $this->timeoutMs()],
      'retryConfig' => self::MINIMUM_RETRIES,
      // A PHP request is shared-nothing; nothing here should phone home on its
      // own schedule.
      'isUsageStatsDisabled' => TRUE,
    ];

    $cid = self::settingsCacheId($sdk_key, $account_id);
    $cached = $this->cache->get($cid);
    if ($cached !== FALSE && \is_string($cached->data)) {
      $builder = new WingifyBuilder($options);
      $client = Wingify::init($options + ['settings' => $cached->data, 'wingifyBuilder' => $builder]);
      // A rejected settings blob does not make init() fail. It logs, swaps in
      // an empty settings object, and returns a perfectly usable client that
      // reports every flag disabled — so the returned client says nothing
      // about whether the settings were accepted, and that has to be asked
      // separately or a poisoned cache entry silently un-personalizes the
      // site for as long as it lives.
      if ($client !== NULL && $builder->getSettingsService()->isSettingsValidOnInit) {
        return $client;
      }
      // Stale or rejected settings: fall through and re-fetch once.
    }

    // The client is built through an explicitly constructed builder because
    // only the builder keeps VWO's settings file in a form that can be cached;
    // see below.
    $builder = new WingifyBuilder($options);
    $client = Wingify::init($options + ['wingifyBuilder' => $builder]);
    if ($client === NULL) {
      // Wingify::init() swallows every failure and returns NULL. Turn that
      // back into a throwable so the membership layer negatively caches it
      // rather than retrying on every render.
      throw new \RuntimeException('Initializing the VWO FME SDK failed.');
    }
    // Not $client->originalSettings: that is a SettingsModel whose properties
    // are all private, so json_encode() returns "{}" for it — which is itself
    // accepted as a string here and then rejected as settings on every later
    // request. The builder holds the settings file as VWO sent it.
    $fetched = \json_encode($builder->originalSettings);
    if (\is_string($fetched) && $fetched !== 'null' && $fetched !== '{}') {
      $settings_ttl = (int) $settings->get('settings_ttl');
      $ttl = $settings_ttl > 0 ? $settings_ttl : 300;
      $this->cache->set($cid, $fetched, $this->time->getRequestTime() + $ttl);
    }
    return $client;
  }

}
