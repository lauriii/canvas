export function handleNonWorkingBtn(): void {
  alert('Not yet supported.');
}

export const preventHover = (event: any) => {
  const e = event as Event;
  e.preventDefault();
};

export function parseValue(value: any, element: HTMLInputElement) {
  if (element && Object.prototype.hasOwnProperty.call(element, 'checked')) {
    return element.checked;
  }
  if (value === '') {
    return value;
  }
  const parsed = Number(value);
  return isNaN(parsed) ? value : parsed;
}
