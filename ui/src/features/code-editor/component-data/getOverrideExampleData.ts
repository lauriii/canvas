import system_breadcrumb_block from '@experimental/js-components-as-block-overrides/example-data/system_breadcrumb_block.json';
import system_menu_block from '@experimental/js-components-as-block-overrides/example-data/system_menu_block.json';
import system_branding_block from '@experimental/js-components-as-block-overrides/example-data/system_branding_block.json';

type OverrideExampleDataPropsOrSlots = Record<string, any>;
type OverrideExampleData = {
  props?: OverrideExampleDataPropsOrSlots;
  slots?: OverrideExampleDataPropsOrSlots;
} & (
  | { props: OverrideExampleDataPropsOrSlots }
  | { slots: OverrideExampleDataPropsOrSlots }
);

const EXAMPLE_DATA: Record<string, OverrideExampleData> = {
  system_breadcrumb_block,
  system_menu_block,
  system_branding_block,
};

export default function getOverrideExampleData(
  block: keyof typeof EXAMPLE_DATA,
  propsOrSlots: 'props' | 'slots',
): OverrideExampleDataPropsOrSlots | null {
  const data = EXAMPLE_DATA[block];
  if (!data) {
    throw new Error(`Block ${block} not found in example data`);
  }

  const result = data[propsOrSlots];
  if (!result) {
    return null;
  }

  return result;
}
