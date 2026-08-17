// The unit letter follows @count with no separator, matching the existing
// English ("5m ago"). Drupal substitutes @count by plain string replacement, so
// the letter is left in place.
// cspell:ignore countd counth countm

export function formatTimestamp(timestamp: number): string {
  const now = Date.now();
  const diffMs = now - timestamp;
  const diffMinutes = Math.floor(diffMs / 60000);
  const diffHours = Math.floor(diffMs / 3600000);
  const diffDays = Math.floor(diffMs / 86400000);

  if (diffMinutes < 1) return Drupal.t('Now');
  if (diffMinutes < 60)
    return Drupal.formatPlural(diffMinutes, '1m ago', '@countm ago');
  if (diffHours < 24)
    return Drupal.formatPlural(diffHours, '1h ago', '@counth ago');
  return Drupal.formatPlural(diffDays, '1d ago', '@countd ago');
}
