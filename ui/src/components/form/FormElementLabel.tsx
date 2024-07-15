import {a2p} from '@/local_packages/utils.js';
import clsx from 'clsx';

export type LabelTitle = {
  '#markup': string;
}
const FormElementLabel = ({title  ={'#markup': ''}, titleDisplay = '', required = '', attributes = {}}) => {
  const labelTitle:LabelTitle|string = title;
  const classes = clsx(
    'form-label',
    titleDisplay === 'after' ? 'option' : '',
    titleDisplay === 'invisible' ? 'visually-hidden' : '',
    required ? 'js-form-required' : '',
    required ? 'form-required' : '',
  )
  const show = !!title || !!required;
  return (show && <label {...a2p(attributes, {'class': classes})}>
      {title['#markup'] ?
        labelTitle['#markup'] :
        labelTitle
      }
    </label>
  )
}

export default FormElementLabel;
