import CanvasBox from '@/components/form/canvas-components/CanvasBox';
import CanvasText from '@/components/form/canvas-components/CanvasText';
import DefaultImagePreview from '@/components/form/components/DefaultImagePreview';
import {
  DrupalContainerTextFormatFilterGuidelines,
  DrupalContainerTextFormatFilterHelp,
} from '@/components/form/components/drupal/DrupalContainerTextFormat';
import DrupalDatetimeMultivalueForm from '@/components/form/components/drupal/DrupalDatetimeMultivalueForm';
import DrupalDetails from '@/components/form/components/drupal/DrupalDetails';
import DrupalForm from '@/components/form/components/drupal/DrupalForm';
import DrupalFormElement from '@/components/form/components/drupal/DrupalFormElement';
import DrupalFormElementLabel from '@/components/form/components/drupal/DrupalFormElementLabel';
import DrupalInput from '@/components/form/components/drupal/DrupalInput';
import DrupalInputMultivalueForm from '@/components/form/components/drupal/DrupalInputMultivalueForm';
import DrupalMediaLibraryFieldset from '@/components/form/components/drupal/DrupalMediaLibraryFieldset.tsx';
import DrupalMediaLibraryItem from '@/components/form/components/drupal/DrupalMediaLibraryItem.tsx';
import DrupalMediaListContainer from '@/components/form/components/drupal/DrupalMediaListContainer.tsx';
import DrupalPathWidget from '@/components/form/components/drupal/DrupalPathWidget';
import { DrupalRadioGroup } from '@/components/form/components/drupal/DrupalRadio';
import DrupalSelect from '@/components/form/components/drupal/DrupalSelect';
import DrupalTextArea from '@/components/form/components/drupal/DrupalTextArea';
import DrupalToggle from '@/components/form/components/drupal/DrupalToggle';
import DrupalVerticalTabs from '@/components/form/components/drupal/DrupalVerticalTabs';
import InputDescription from '@/components/form/components/drupal/InputDescription.js';
import LinkedFieldBox from '@/components/form/components/drupal/LinkedFieldBox.js';
import PropLinker from '@/components/form/components/drupal/PropLinker.js';
import DrupalMediaLibraryWidgetContainer from '@/components/form/components/MediaLibraryWidgetContainer';
import SerpPreview from '@/components/form/components/SerpPreview';

// This is where we map the <drupal- tags to the corresponding JSX component.
const twigToJSXComponentMap = {
  'drupal-canvas-container--text-format-filter-guidelines':
    DrupalContainerTextFormatFilterGuidelines,
  'drupal-canvas-container--text-format-filter-help':
    DrupalContainerTextFormatFilterHelp,
  'drupal-canvas-details': DrupalDetails,
  'drupal-canvas-form': DrupalForm,
  'drupal-canvas-form-element': DrupalFormElement,
  'drupal-canvas-form-element-label': DrupalFormElementLabel,
  'drupal-canvas-input': DrupalInput,
  'drupal-canvas-input--checkbox--inwidget-boolean-checkbox': DrupalToggle,
  'drupal-canvas-input--url': DrupalInput,
  'drupal-canvas-input--textfield--inwidget-path': DrupalPathWidget,
  'drupal-canvas-input--multivalue-form': DrupalInputMultivalueForm,
  'drupal-canvas-datetime-wrapper--multivalue-form':
    DrupalDatetimeMultivalueForm,
  'drupal-canvas-radios': DrupalRadioGroup,
  'drupal-canvas-select': DrupalSelect,
  'drupal-canvas-textarea': DrupalTextArea,
  'drupal-canvas-vertical-tabs': DrupalVerticalTabs,
  'drupal-canvas-container--media-library-widget':
    DrupalMediaLibraryWidgetContainer,
  'canvas-text': CanvasText,
  'canvas-box': CanvasBox,
  'canvas-description': InputDescription,
  'canvas-drupal-label': DrupalFormElementLabel,
  'drupal-canvas-linked-field-box': LinkedFieldBox,
  'drupal-canvas-prop-linker': PropLinker,
  'drupal-canvas-serp-preview': SerpPreview,
  'canvas-default-image-preview': DefaultImagePreview,
  'drupal-canvas-media-list-container': DrupalMediaListContainer,
  'drupal-canvas-media-library-item': DrupalMediaLibraryItem,
  'drupal-canvas-media-library-fieldset': DrupalMediaLibraryFieldset,
};

export default twigToJSXComponentMap;
