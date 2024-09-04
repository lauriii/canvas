import type { LayoutModelSliceState } from '@/features/layout/layoutModelSlice';
import type { Component } from '@/types/Component';

export interface Section {
  name: string;
  id: string;
  default_markup: string;
  components: Component[];
  layoutModel: LayoutModelSliceState;
}

export interface SectionsList {
  [key: string]: Section;
}
