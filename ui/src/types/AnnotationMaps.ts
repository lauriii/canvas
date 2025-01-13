interface SlotInfo {
  element: HTMLElement;
  componentUuid: string;
  slotName: string;
}

interface ComponentInfo {
  elements: HTMLElement[];
  componentUuid: string;
}

export type SlotsMap = Record<string, SlotInfo>;

export type ComponentsMap = Record<string, ComponentInfo>;
