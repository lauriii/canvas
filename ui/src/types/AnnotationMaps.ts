interface RegionInfo {
  element: HTMLElement;
  regionId: string;
}
interface ComponentInfo {
  elements: HTMLElement[];
  componentUuid: string;
}
interface SlotInfo {
  element: HTMLElement;
  componentUuid: string;
  slotName: string;
}

export type RegionsMap = Record<string, RegionInfo>;
export type ComponentsMap = Record<string, ComponentInfo>;
export type SlotsMap = Record<string, SlotInfo>;
