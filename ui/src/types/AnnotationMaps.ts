export interface RegionInfo {
  elements: HTMLElement[];
  regionId: string;
}
export interface ComponentInfo {
  elements: HTMLElement[];
  componentUuid: string;
}

export type StackDirection =
  | 'vertical'
  | 'vertical-grid'
  | 'vertical-flex'
  | 'horizontal-flex'
  | 'horizontal-grid';

export interface SlotInfo {
  element: HTMLElement;
  componentUuid: string;
  slotName: string;
  stackDirection: StackDirection;
}

export type RegionsMap = Record<string, RegionInfo>;
export type ComponentsMap = Record<string, ComponentInfo>;
export type SlotsMap = Record<string, SlotInfo>;
