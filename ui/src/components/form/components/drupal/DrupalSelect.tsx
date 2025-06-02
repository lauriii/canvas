import InputBehaviors from '@/components/form/inputBehaviors';
import type { Attributes } from '@/types/DrupalAttribute';
import Select from '@/components/form/components/Select';

export interface DrupalSelectProps {
  attributes?: Attributes;
  options?: Array<{
    value: string;
    label: string;
    selected: boolean;
    type: string;
  }>;
}

const DrupalSelect: React.FC<DrupalSelectProps> = ({
  attributes = {},
  options = [],
}) => {
  return <Select options={options} attributes={attributes} />;
};

export default InputBehaviors(DrupalSelect);
