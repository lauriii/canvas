import Textarea from '@/components/form/Textarea';
import Form from '@/components/form/Form';
import FormElement from '@/components/form/FormElement';
import FormElementLabel from '@/components/form/FormElementLabel';
import Input from '@/components/form/Input';

// This is where we map the Drupal Twig templates to the corresponding JSX component.
// @see experience_builder_theme_suggestions_alter()
// @see themes/engines/semi_coupled/README.md
const twigToJSXComponentMap = {
  'drupal-input--xbxb': Input,
  'drupal-textarea--xbxb': Textarea,
  'drupal-form--xbxb': Form,
  'drupal-form-element--xbxb': FormElement,
  'drupal-form-element-label--xbxb': FormElementLabel,
};

export default twigToJSXComponentMap;

