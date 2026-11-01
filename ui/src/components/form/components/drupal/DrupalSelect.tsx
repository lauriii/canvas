import SegmentedSelect, {
  fitsSegmentedControl,
} from '@/components/form/components/SegmentedSelect';
import Select from '@/components/form/components/Select';
import { useSafeFormContext } from '@/components/form/contexts/FormContext';
import { withRHF } from '@/components/form/react-hook-form/withRHF';
import { FORM_TYPES } from '@/features/form/constants';

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
  const formContext = useSafeFormContext();
  // Short enum props (variant, size, alignment …) read better as a segmented
  // control than as a dropdown.
  if (
    formContext?.formId === FORM_TYPES.COMPONENT_INSTANCE_FORM &&
    fitsSegmentedControl(attributes, options)
  ) {
    return <SegmentedSelect options={options} attributes={attributes} />;
  }
  return <Select options={options} attributes={attributes} />;
};

export default withRHF(DrupalSelect);
