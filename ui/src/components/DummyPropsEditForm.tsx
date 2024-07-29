import { useGetDummyPropsFormQuery } from '@/services/dummyPropsForm';
import hyperscriptify from '@/local_packages/hyperscriptify';
import * as React from 'react';
import twigToJSXComponentMap from '@/components/form/twig-to-jsx-component-map.js';
import propsify from '@/local_packages/hyperscriptify/propsify/standard/index.js';
import { useEffect, useRef } from 'react';
import { useAppSelector } from '@/app/hooks';
import { selectModel, selectLayout } from '@/features/layout/layoutModelSlice';
import { selectSelectedComponent } from '@/features/ui/uiSlice';
import { useGetComponentsQuery } from '@/services/components';
import type { ComponentsList } from '@/types/Component';
import type { LayoutNode } from '@/features/layout/layoutModelSlice';

interface KeyedLayoutList {
  [key: string]: LayoutNode;
}

const { Drupal } = window as any;

interface PropData {
  sourceType?: string;
  expression?: string;
  value?: any;
}

interface PropDataCollection {
  [key: string]: PropData;
}
interface PreparedModel {
  [x: string]: {
    [x: string]: PropData;
  };
}

const DummyPropsEditForm = () => {
  const model = useAppSelector(selectModel);
  const layout = useAppSelector(selectLayout);
  const componentsResults = useGetComponentsQuery();
  const components: ComponentsList = componentsResults.data
    ? componentsResults.data
    : {};
  const selectedComponent = useAppSelector(selectSelectedComponent) || 'noop';
  const preparedModel: PreparedModel = { [selectedComponent]: {} };
  const keyedLayout: KeyedLayoutList = {};

  // Create a version of the layout tree as an Object with nodes indexed by
  // UUID. This is used to get information about the SDC being used (type,
  // default values, etc.) as the model only contains prop data.
  Object.values(layout.children).map((item) => {
    if (item.type && components[item.type]) {
      keyedLayout[item.uuid] = item;
    }
    return true;
  });

  const selectedModel = model[selectedComponent];
  const selectedComponentType = keyedLayout[selectedComponent].type || 'noop';

  // This is metadata about the props of the SDC being edited. This is specific
  // to the SDC *type* but unconcerned with this SDC *instance*.
  const selectedComponentFieldData: PropDataCollection =
    components[selectedComponentType]['field_data'] || {};

  // The prepared model combines prop values from the model and prop metadata
  // from the SDC definition.
  for (const [thePropName, propData] of Object.entries(
    selectedComponentFieldData,
  )) {
    const propName: string = thePropName;
    preparedModel[selectedComponent][propName] = {};
    // The sourceType of the prop is required by the component edit form. This
    // information is provided to the UI in the components list returned by
    // /xb-components.
    if (propData.sourceType) {
      preparedModel[selectedComponent as keyof PreparedModel][
        propName
      ].sourceType = propData.sourceType;
    }
    // The expression of the prop is required by the component edit form. This
    // information is provided to the UI in the components list returned by
    // /xb-components.
    if (propData.expression) {
      preparedModel[selectedComponent as keyof PreparedModel][
        propName
      ].expression = propData.expression;
    }

    // The current value of the prop, or an empty string so the `value` is at
    // least present.
    preparedModel[selectedComponent as keyof PreparedModel][propName].value =
      selectedModel[propName] || '';
  }

  // The "tree" sent to the field widget only contains the selected component.
  const tree = layout.children.filter(
    (node) => node.uuid === selectedComponent,
  );
  const query = new URLSearchParams({
    tree: JSON.stringify(tree),
    props: JSON.stringify(preparedModel),
    selected: selectedComponent,
  });
  const dynamicStaticCardQueryString = `?${query.toString()}`;

  //👇This query string is known to work and can be used for troubleshooting.
  // cspell:disable-next-line
  // const dynamicStaticCardQueryString = '?tree=%5B%7B%22uuid%22%3A%22dynamic-static-card2df%22%2C%22type%22%3A%22sdc_test%3Amy-cta%22%7D%5D&props=%7B%22dynamic-static-card2df%22%3A%7B%22text%22%3A%7B%22sourceType%22%3A%22dynamic%22%2C%22expression%22%3A%22%5Cu2139%5Cufe0e%5Cu241centity%3Anode%3Aarticle%5Cu241dtitle%5Cu241e%5Cu241fvalue%22%7D%2C%22href%22%3A%7B%22sourceType%22%3A%22static%3Afield_item%3Alink%22%2C%22value%22%3A%7B%22uri%22%3A%22https%3A%5C%2F%5C%2Fdrupal.org%22%2C%22title%22%3Anull%2C%22options%22%3A%5B%5D%7D%2C%22expression%22%3A%22%5Cu2139%5Cufe0elink%5Cu241furi%22%7D%7D%7D'
  const response = useGetDummyPropsFormQuery(dynamicStaticCardQueryString);
  const responseAsDocument = new DOMParser().parseFromString(
    response.data as string,
    'text/html',
  );
  // Get the form we want from the HTML response.
  const xbDemoFieldElement: HTMLTemplateElement | null =
    responseAsDocument.querySelector('template[hyperscriptify]');
  const jsxFormContent = xbDemoFieldElement?.content
    ? hyperscriptify(
        xbDemoFieldElement?.content as DocumentFragment,
        React.createElement,
        React.Fragment,
        twigToJSXComponentMap,
        { propsify },
      )
    : null;
  const formRef = useRef(null);

  // Any time this form changes, process it through Drupal behaviors the same
  // way it would be if it were added to the DOM by Drupal AJAX. This allows
  // Drupal functionality like Autocomplete work in this React-rendered form.
  useEffect(() => {
    let formRefValue = null;
    if (jsxFormContent && formRef.current) {
      Drupal.attachBehaviors(formRef.current);
      formRefValue = formRef.current;
    }
    return () => {
      if (formRefValue) {
        Drupal.detachBehaviors(formRefValue);
      }
    };
  }, [jsxFormContent]);

  return (
    <>
      <h3 style={{ color: 'white', fontFamily: 'monospace' }}>
        Here is DummyPropsEditForm 👇
      </h3>
      <b style={{ color: 'white', fontFamily: 'monospace' }}>
        We know this is not an ideal form. This is a work in progress.
      </b>
      {jsxFormContent ? (
        jsxFormContent
      ) : (
        <h1 style={{ color: 'white' }}>Loading...</h1>
      )}
    </>
  );
};

export default DummyPropsEditForm;
