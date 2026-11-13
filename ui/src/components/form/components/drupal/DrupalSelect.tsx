import SearchableSelect, {
  needsSearchableSelect,
} from '@/components/form/components/SearchableSelect';
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
  // Long lists, such as options from site data, need search, and nested
  // options read better as a tree.
  if (
    formContext?.formId === FORM_TYPES.COMPONENT_INSTANCE_FORM &&
    needsSearchableSelect(attributes, options)
  ) {
    return <SearchableSelect options={options} attributes={attributes} />;
  }
  return <Select options={options} attributes={attributes} />;
};

export default withRHF(DrupalSelect);
