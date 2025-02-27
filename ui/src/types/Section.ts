import type { LayoutModelPiece } from '@/features/layout/layoutModelSlice';

export interface Section {
  layoutModel: LayoutModelPiece;
  name: string;
  id: string;
  default_markup: string;
  css: string;
  js_header: string;
  js_footer: string;
}

// Type for the API response, an object keyed by section ID
export interface SectionsList {
  [key: string]: Section;
}
