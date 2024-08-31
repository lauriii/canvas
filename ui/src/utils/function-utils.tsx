export function handleNonWorkingBtn(): void {
  alert('Not yet supported.');
}

export const preventHover = (event: any) => {
  const e = event as Event;
  e.preventDefault();
};

export function parseValue(value: any) {
  if (value === '') return value;
  const parsed = Number(value);
  return isNaN(parsed) ? value : parsed;
}
