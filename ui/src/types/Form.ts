import type { ComponentsList } from '@/types/Component';
import type {
  LayoutNode,
  ComponentModels,
} from '@/features/layout/layoutModelSlice';

export interface PropsValues {
  [key: string]: any;
}

export interface InputMessage {
  type: 'error' | 'warning' | 'info';
  message: string;
}

export interface InputUIData {
  selectedComponent: string;
  components: ComponentsList | undefined;
  selectedComponentType: string;
  layout: LayoutNode;
  model?: ComponentModels;
  node?: LayoutNode | null;
  inputValue?: any;
  setInputValue?: React.Dispatch<React.SetStateAction<any>>;
}
