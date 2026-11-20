const revisionDateFormatter = new Intl.DateTimeFormat(undefined, {
  dateStyle: 'medium',
  timeStyle: 'short',
});

/**
 * When a revision was made, e.g. "Nov 15, 2026, 5:14 PM".
 */
export const formatRevisionDate = (timestamp: number): string =>
  revisionDateFormatter.format(new Date(timestamp * 1000));
