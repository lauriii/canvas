import Select from '@/components/form/components/Select';
import { withRHF } from '@/components/form/react-hook-form/withRHF';

import type { Attributes } from '@/types/DrupalAttribute';

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

export default withRHF(DrupalSelect);
