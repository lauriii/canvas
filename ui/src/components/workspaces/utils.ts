import { format } from 'date-fns';

// Formats a Unix timestamp (seconds) for scheduled publish labels.
export const formatScheduledDate = (timestamp: number) =>
  format(new Date(timestamp * 1000), 'MMM d, yyyy h:mm a');
