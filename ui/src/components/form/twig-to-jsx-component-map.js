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
const twigToJSXComponentMap = {
  'drupal-container--text-format-filter-guidelines--xbxb':
    ContainerTextFormatFilterGuidelines,
  'drupal-container--text-format-filter-help--xbxb':
    ContainerTextFormatFilterHelp,
  'drupal-container--text-format-filter-wrapper--xbxb':
    ContainerTextFormatFilterWrapper,
  'drupal-details--xbxb': AccordionDetails,
  'drupal-form--xbxb': Form,
  'drupal-form-element--xbxb': FormElement,
  'drupal-form-element-label--xbxb': FormElementLabel,
  'drupal-input--checkbox--inwidget-boolean-checkbox--xbxb': Toggle,
  'drupal-input--url--xbxb': UrlInput,
  'drupal-input--xbxb': Input,
  'drupal-select--xbxb': Select,
  'drupal-textarea--xbxb': TextArea,
  'drupal-vertical-tabs--xbxb': AccordionRoot,
};

export default twigToJSXComponentMap;
