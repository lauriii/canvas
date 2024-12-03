import { a2p } from '@/local_packages/utils.js';
import TextArea from '@/components/form/components/TextArea';
import InputBehaviors from '@/components/form/inputBehaviors';

import type { Attributes } from '@/types/DrupalAttribute';

const DrupalTextArea = ({
  attributes = {},
  wrapperAttributes = {},
}: {
  attributes?: Attributes;
  wrapperAttributes?: Attributes;
}) => (
  <div {...a2p(wrapperAttributes)}>
    <TextArea
      value={attributes.value?.toString() ?? ''}
      attributes={a2p(attributes, {}, { skipAttributes: ['value'] })}
    />
  </div>
);

export default InputBehaviors(DrupalTextArea);
