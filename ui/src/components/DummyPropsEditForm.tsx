import React, { useEffect, useState, useRef } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { Spinner } from '@radix-ui/themes';
import { useGetDummyPropsFormQuery } from '@/services/dummyPropsForm';
import hyperscriptify from '@/local_packages/hyperscriptify';
import twigToJSXComponentMap from '@/components/form/twig-to-jsx-component-map.js';
import propsify from '@/local_packages/hyperscriptify/propsify/standard/index.js';
import { useAppSelector } from '@/app/hooks';
import { selectModel, selectLayout } from '@/features/layout/layoutModelSlice';
import { selectSelectedComponent } from '@/features/ui/uiSlice';
import { useGetComponentsQuery } from '@/services/components';
import { findNodeByUuid } from '@/features/layout/layoutUtils';

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
  const { currentData, error, originalArgs, isFetching } =
    useGetDummyPropsFormQuery(dynamicStaticCardQueryString);
  const selectedComponent = useAppSelector(selectSelectedComponent);
  const { showBoundary } = useErrorBoundary();

  const [jsxFormContent, setJsxFormContent] =
    useState<React.ReactElement | null>(null);
  const [currentComponentId, setCurrentComponentId] = useState<string | null>(
    null,
  );
  const formRef = useRef(null);

  useEffect(() => {
    if (error) {
      showBoundary(error);
    }
  }, [error, showBoundary]);

  useEffect(() => {
    if (!currentData) {
      return;
    }
    const responseAsDocument = new DOMParser().parseFromString(
      currentData as string,
      'text/html',
    );
    // Get the form we want from the HTML response.
    const xbDemoFieldElement: HTMLTemplateElement | null =
      responseAsDocument.querySelector('template[hyperscriptify]');
    if (!xbDemoFieldElement?.content) {
      return;
    }
    // While we have `selectedComponent` in the Redux store, we can't rely on it
    // here, because if it's added as a dependency of this `useEffect` hook, it
    // will cause a re-render using stale data from the Redux Toolkit Query hook
    // — the API call. Instead we rely on fresh data from RTK Query to
    // re-render, and we grab the selected component's ID from the arg that was
    // passed to the API call which produced the current data.
    const componentId = new URLSearchParams(originalArgs).get('selected');
    setCurrentComponentId(componentId);

    setJsxFormContent(
      // Wrapping the constructed `ReactElement` for the form so we can add a
      // key which tells React when to re-render this subtree. The component ID
      // is granular enough. Using the entire value of
      // `dynamicStaticCardQueryString` would cause the form to re-render while
      // prop values are being updated by the user in the contextual panel,
      // causing the form to lose focus.
      // A `<div>` is used instead of `React.Fragment` so a test ID can be added.
      <div key={componentId} data-testid={`xb-component-form-${componentId}`}>
        {hyperscriptify(
          xbDemoFieldElement?.content as DocumentFragment,
          React.createElement,
          React.Fragment,
          twigToJSXComponentMap,
          { propsify },
        )}
      </div>,
    );
  }, [currentData, originalArgs]);

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
    <Spinner
      size="3"
      // Display the spinner only when a new component is being fetched.
      loading={isFetching && currentComponentId !== selectedComponent}
    >
      {jsxFormContent}
    </Spinner>
  );
};

const DummyPropsEditForm: React.FC<DummyPropsEditFormProps> = () => {
  const model = useAppSelector(selectModel);
  const layout = useAppSelector(selectLayout);
  const { data: components, error } = useGetComponentsQuery();
  const { showBoundary } = useErrorBoundary();
  const selectedComponent = useAppSelector(selectSelectedComponent);

  const [dynamicStaticCardQueryString, setDynamicStaticCardQueryString] =
    useState('');

  useEffect(() => {
    if (error) {
      showBoundary(error);
    }
    if (!components || !selectedComponent) {
      return;
    }
    const preparedModel: PreparedModel = { [selectedComponent]: {} };
    const selectedModel = model[selectedComponent];
    const node = findNodeByUuid(layout, selectedComponent);
    const selectedComponentType = node ? (node.type as string) : 'noop';

    // This is metadata about the props of the SDC being edited. This is specific
    // to the SDC *type* but unconcerned with this SDC *instance*.
    const selectedComponentFieldData: PropDataCollection =
      components[selectedComponentType]?.['field_data'] || {};

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

    interface TreeNode {
      uuid: string;
      children?: TreeNode[];
    }
    // The "tree" sent to the field widget only contains the selected component.
    function findNodeInObjectTree(tree: TreeNode, uuid: string): object | null {
      if (tree.uuid === uuid) {
        return tree;
      }
      if (tree.children) {
        for (let i = 0; i < tree.children.length; i++) {
          const found = findNodeInObjectTree(tree.children[i], uuid);
          if (found) {
            return found;
          }
        }
      }
      return null; // If no match is found
    }
    const tree = findNodeInObjectTree(layout, selectedComponent);
    const query = new URLSearchParams({
      tree: JSON.stringify(tree),
      props: JSON.stringify(preparedModel),
      selected: selectedComponent,
    });
    setDynamicStaticCardQueryString(`?${query.toString()}`);
  }, [components, error, showBoundary, selectedComponent, layout, model]);

  return (
    dynamicStaticCardQueryString && (
      <DummyPropsEditFormRenderer
        dynamicStaticCardQueryString={dynamicStaticCardQueryString}
      />
    )
  );
};

export default DummyPropsEditForm;
