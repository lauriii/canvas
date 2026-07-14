/**
 * The payload format version. The Drupal-side reader hard-fails on an
 * unknown version instead of mis-parsing — this is a cross-repo,
 * cross-deploy contract.
 *
 * A leaf module on purpose: both the request-time manifest reader and the
 * build-time writer need it, and those two must not share any module that
 * does filesystem work (see ./manifest-read.ts for why).
 */
export const COMPONENT_METADATA_PAYLOAD_VERSION = 1;
