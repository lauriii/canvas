import Form from '@/components/form/Form';
import FormElement from '@/components/form/FormElement';
import FormElementLabel from '@/components/form/FormElementLabel';
import Input from '@/components/form/Input';
import Select from '@/components/form/Select';
import TextArea from '@/components/form/TextArea';
import Toggle from '@/components/form/Toggle';
import UrlInput from '@/components/form/UrlInput';
import { AccordionRoot, AccordionDetails } from '@/components/form/Accordion';
import {
  ContainerTextFormatFilterGuidelines,
  ContainerTextFormatFilterHelp,
  ContainerTextFormatFilterWrapper,
} from '@/components/form/ContainerTextFormat';

// This is where we map the Drupal Twig templates to the corresponding JSX component.
// @see experience_builder_theme_suggestions_alter()
// @see themes/engines/semi_coupled/README.md
// @see themes/xb_stark/templates/process_as_jsx/
const twigToJSXComponentMap = {
  'drupal-container--text-format-filter-guidelines':
    ContainerTextFormatFilterGuidelines,
  'drupal-container--text-format-filter-help': ContainerTextFormatFilterHelp,
  'drupal-container--text-format-filter-wrapper':
    ContainerTextFormatFilterWrapper,
  'drupal-details': AccordionDetails,
  'drupal-form': Form,
  'drupal-form-element': FormElement,
  'drupal-form-element-label': FormElementLabel,
  'drupal-input--checkbox--inwidget-boolean-checkbox': Toggle,
  'drupal-input--url': UrlInput,
  'drupal-input': Input,
  'drupal-select': Select,
  'drupal-textarea': TextArea,
  'drupal-vertical-tabs': AccordionRoot,
};

export default twigToJSXComponentMap;
