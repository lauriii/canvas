// Converts local datetime-local input to UTC ISO string
// Example: "2024-01-15T14:30" → "2024-01-15T19:30:00.000Z"
export const localTimeToUtcConversion = (datetimeLocal: string): string => {
  if (!datetimeLocal) return '';
  const date = new Date(datetimeLocal);
  return date.toISOString();
};

// Converts UTC ISO string to local datetime-local format for input display
// Example: "2024-01-15T19:30:00.000Z" → "2024-01-15T14:30"
export const utcToLocalTimeConversion = (isoUtc: string): string => {
  if (!isoUtc) return '';
  const d = new Date(isoUtc); // parsed in UTC, Date methods return local time
  const pad = (n: number) => String(n).padStart(2, '0');
  const yyyy = d.getFullYear();
  const mm = pad(d.getMonth() + 1);
  const dd = pad(d.getDate());
  const hh = pad(d.getHours());
  const min = pad(d.getMinutes());
  return `${yyyy}-${mm}-${dd}T${hh}:${min}`;
};
