import React from 'react';
import { useGetDummyPropsFormQuery } from '@/services/dummyPropsForm';
import hyperscriptify from '@/local_packages/hyperscriptify';
import twigToJSXComponentMap from '@/components/form/twig-to-jsx-component-map.js';
import propsify from '@/local_packages/hyperscriptify/propsify/standard/index.js';
import { useEffect, useRef, useState } from 'react';
import { useAppSelector } from '@/app/hooks';
import { selectModel, selectLayout } from '@/features/layout/layoutModelSlice';
import { selectSelectedComponent } from '@/features/ui/uiSlice';
import { useGetComponentsQuery } from '@/services/components';
import type { LayoutNode } from '@/features/layout/layoutModelSlice';

interface KeyedLayoutList {
  [key: string]: LayoutNode;
}

const { Drupal } = window as any;

interface PropData {
  sourceType?: string;
  sourceTypeSettings?: object;
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
interface DummyPropsEditFormRendererProps {
  dynamicStaticCardQueryString: string;
}
interface DummyPropsEditFormProps {}

const DummyPropsEditFormRenderer: React.FC<DummyPropsEditFormRendererProps> = (
  props,
) => {
  const { dynamicStaticCardQueryString } = props;
  const { data } = useGetDummyPropsFormQuery(dynamicStaticCardQueryString);
  const [jsxFormContent, setJsxFormContent] = useState(null);
  const formRef = useRef(null);

  useEffect(() => {
    if (!data) {
      return;
    }
    const responseAsDocument = new DOMParser().parseFromString(
      data as string,
      'text/html',
    );
    // Get the form we want from the HTML response.
    const xbDemoFieldElement: HTMLTemplateElement | null =
      responseAsDocument.querySelector('template[hyperscriptify]');
    setJsxFormContent(
      xbDemoFieldElement?.content
        ? hyperscriptify(
            xbDemoFieldElement?.content as DocumentFragment,
            React.createElement,
            React.Fragment,
            twigToJSXComponentMap,
            { propsify },
          )
        : null,
    );
  }, [data]);

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

  return <>{jsxFormContent ? jsxFormContent : <h1>Loading...</h1>}</>;
};

const DummyPropsEditForm: React.FC<DummyPropsEditFormProps> = () => {
  const model = useAppSelector(selectModel);
  const layout = useAppSelector(selectLayout);
  const { data: components } = useGetComponentsQuery();
  const selectedComponent = useAppSelector(selectSelectedComponent) || 'noop';

  const [dynamicStaticCardQueryString, setDynamicStaticCardQueryString] =
    useState('');

  const keyedLayoutRef = useRef<KeyedLayoutList>({});

  useEffect(() => {
    if (!components) {
      return;
    }
    keyedLayoutRef.current = {};
    // Create a version of the layout tree as an Object with nodes indexed by
    // UUID. This is used to get information about the SDC being used (type,
    // default values, etc.) as the model only contains prop data.
    Object.values(layout.children).forEach((item) => {
      if (item.type && components[item.type]) {
        keyedLayoutRef.current[item.uuid] = item;
      }
    });
    const preparedModel: PreparedModel = { [selectedComponent]: {} };
    const selectedModel = model[selectedComponent];
    const selectedComponentType =
      keyedLayoutRef.current[selectedComponent].type || 'noop';

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
      // Some sourceTypes may have additional settings (e.g. for indicating valid choices in an SDC's `enum`.)
      if (propData.sourceTypeSettings) {
        preparedModel[selectedComponent as keyof PreparedModel][
          propName
        ].sourceTypeSettings = propData.sourceTypeSettings;
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
    setDynamicStaticCardQueryString(`?${query.toString()}`);
  }, [components, selectedComponent, layout, model]);

  return (
    dynamicStaticCardQueryString && (
      <DummyPropsEditFormRenderer
        dynamicStaticCardQueryString={dynamicStaticCardQueryString}
      />
    )
  );
};

export default DummyPropsEditForm;
