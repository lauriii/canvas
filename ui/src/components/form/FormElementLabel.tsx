import { a2p } from '@/local_packages/utils.js';
import clsx from 'clsx';
import { Text } from '@radix-ui/themes';
import styles from './Form.module.css';

export type LabelTitle = {
  '#markup': string;
};
const FormElementLabel = ({
  title = { '#markup': '' },
  titleDisplay = '',
  required = '',
  attributes = {},
}) => {
  const labelTitle: LabelTitle | string = title;
  const classes = clsx(
    styles.formLabel,
    titleDisplay === 'after' ? 'option' : '',
    titleDisplay === 'invisible' ? 'visually-hidden' : '',
    required ? 'js-form-required' : '',
    required ? 'form-required' : '',
  );
  const show = !!title || !!required;
  return (
    show && (
      <Text as="label" {...a2p(attributes, { class: classes })}>
        {title['#markup'] ? labelTitle['#markup'] : labelTitle}
      </Text>
    )
  );
};

export default FormElementLabel;
