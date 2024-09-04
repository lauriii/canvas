import Textarea from '@/components/form/Textarea';
import Form from '@/components/form/Form';
import FormElement from '@/components/form/FormElement';
import FormElementLabel from '@/components/form/FormElementLabel';
import Input from '@/components/form/Input';
import UrlInput from '@/components/form/UrlInput';
import Select from '@/components/form/Select';
import Toggle from '@/components/form/Toggle';

// This is where we map the Drupal Twig templates to the corresponding JSX component.
// @see experience_builder_theme_suggestions_alter()
// @see themes/engines/semi_coupled/README.md
const twigToJSXComponentMap = {
  'drupal-input--xbxb': Input,
  'drupal-textarea--xbxb': Textarea,
  'drupal-form--xbxb': Form,
  'drupal-form-element--xbxb': FormElement,
  'drupal-form-element-label--xbxb': FormElementLabel,
  'drupal-input--url--xbxb': UrlInput,
  'drupal-select--xbxb': Select,
  'drupal-input--checkbox--inwidget-boolean-checkbox--xbxb': Toggle,
};

export default twigToJSXComponentMap;
