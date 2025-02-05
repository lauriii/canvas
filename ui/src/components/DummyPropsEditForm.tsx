import React, { useEffect, useState, useRef } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { Spinner, Text } from '@radix-ui/themes';
import { useGetDummyPropsFormQuery } from '@/services/dummyPropsForm';
import hyperscriptify from '@/local_packages/hyperscriptify';
import twigToJSXComponentMap from '@/components/form/twig-to-jsx-component-map';
import propsify from '@/local_packages/hyperscriptify/propsify/standard/index.js';
import parseHyperscriptifyTemplate from '@/utils/parse-hyperscriptify-template';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import type { RegionNode } from '@/features/layout/layoutModelSlice';
import { selectModel, selectLayout } from '@/features/layout/layoutModelSlice';
import { selectLatestUndoRedoActionId } from '@/features/ui/uiSlice';
import { useGetComponentsQuery } from '@/services/components';
import { findComponentByUuid } from '@/features/layout/layoutUtils';
import { useDrupalBehaviors } from '@/hooks/useDrupalBehaviors';
import { useParams } from 'react-router-dom';
import { clearFieldValues } from '@/features/form/formStateSlice';
import type { FieldData } from '@/types/Component';

interface PropData {
  sourceType: string;
  sourceTypeSettings?: object;
  expression: string;
  value: any;
}
interface PreparedModel {
  [x: string]: PropData | string;
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
  const { componentId: selectedComponent } = useParams();
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
    const template = parseHyperscriptifyTemplate(currentData as string);
    if (!template) {
      return;
    }
    // While we have `selectedComponent` and `latestUndoRedoActionId` in the
    // Redux store, we can't rely on those values here, because if they are added
    // as a dependency of this `useEffect` hook, they will cause a re-render
    // using stale data from the Redux Toolkit Query hook — the API call.
    // Instead we rely on fresh data from RTK Query to re-render, and we grab
    // the values from the arg that was passed to the API call which produced
    // the current data.
    const componentId = new URLSearchParams(originalArgs).get(
      'form_xb_selected',
    );
    const latestUndoRedoActionId = new URLSearchParams(originalArgs).get(
      'latestUndoRedoActionId',
    );
    setCurrentComponentId(componentId);

    setJsxFormContent(
      // Wrapping the constructed `ReactElement` for the form so we can add a
      // key which tells React when to re-render this subtree. The component ID
      // is granular enough. Using the entire value of
      // `dynamicStaticCardQueryString` would cause the form to re-render while
      // prop values are being updated by the user in the contextual panel,
      // causing the form to lose focus.
      // A `<div>` is used instead of `React.Fragment` so a test ID can be added.
      <div
        key={`${componentId}-${latestUndoRedoActionId}`}
        data-testid={`xb-component-form-${componentId}`}
      >
        {hyperscriptify(
          template,
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
  useDrupalBehaviors(formRef, jsxFormContent);

  return (
    <Spinner
      size="3"
      // Display the spinner only when a new component is being fetched.
      loading={isFetching && currentComponentId !== selectedComponent}
    >
      {/* Wrap the JSX form in a ref, so we can send it as a stable DOM element
          argument to Drupal.attachBehaviors() anytime jsxFormContent changes.
          See the useEffect just above this. */}
      <div ref={formRef}>{jsxFormContent}</div>
    </Spinner>
  );
};

const DummyPropsEditForm: React.FC<DummyPropsEditFormProps> = () => {
  const dispatch = useAppDispatch();
  const model = useAppSelector(selectModel);
  const layout = useAppSelector(selectLayout);
  const { data: components, error } = useGetComponentsQuery();
  const { showBoundary } = useErrorBoundary();
  const { componentId: selectedComponent } = useParams();
  const latestUndoRedoActionId = useAppSelector(selectLatestUndoRedoActionId);

  const [dynamicStaticCardQueryString, setDynamicStaticCardQueryString] =
    useState('');
  const [emptyProp, setEmptyProp] = useState(false);
  const [componentSource, setComponentSource] = useState('');

  useEffect(() => {
    dispatch(clearFieldValues('component_inputs_form'));
  }, [dispatch, selectedComponent]);

  useEffect(() => {
    if (error) {
      showBoundary(error);
    }
    if (
      !components ||
      !selectedComponent ||
      layout.filter(
        (regionNode: RegionNode) => regionNode.components.length > 0,
      ).length === 0
    ) {
      return;
    }
    const node = findComponentByUuid(layout, selectedComponent);
    if (!node) {
      return;
    }
    const selectedComponentType = node.type;

    // This is metadata about the props of the SDC being edited. This is specific
    // to the SDC *type* but unconcerned with this SDC *instance*.
    const selectedComponentFieldData: FieldData =
      components[selectedComponentType]?.['field_data'] || {};

    // Check if this component has any props or not.
    let preparedModel: PreparedModel;
    if (Object.keys(selectedComponentFieldData).length === 0) {
      setDynamicStaticCardQueryString('');
      setEmptyProp(true);
      preparedModel = model[selectedComponent] as PreparedModel;
    } else {
      setEmptyProp(false);
      // The prepared model combines prop values from the model and prop metadata
      // from the SDC definition.
      preparedModel = {};
      for (const [propName, propData] of Object.entries(
        selectedComponentFieldData,
      )) {
        preparedModel[propName] = {
          // The current value of the prop, or an empty string so the `value` is at
          // least present.
          value: model[selectedComponent][propName] || '',
          // The sourceType of the prop is required by the component edit form. This
          // information is provided to the UI in the components list returned by
          // /xb/api/config/component.
          sourceType: propData.sourceType,
          // Some sourceTypes may have additional settings (e.g. for indicating valid choices in an SDC's `enum`.)
          ...(propData.sourceTypeSettings && {
            sourceTypeSettings: propData.sourceTypeSettings,
          }),
          // The expression of the prop is required by the component edit form. This
          // information is provided to the UI in the components list returned by
          // /xb/api/config/component.
          expression: propData.expression,
        };
      }
    }

    const tree = findComponentByUuid(layout, selectedComponent);
    const query = new URLSearchParams({
      form_xb_tree: JSON.stringify(tree),
      form_xb_props: JSON.stringify(preparedModel),
      form_xb_selected: selectedComponent,
      latestUndoRedoActionId,
    });
    setDynamicStaticCardQueryString(`?${query.toString()}`);
    setComponentSource(components?.[selectedComponentType]?.source || '');
  }, [
    components,
    error,
    showBoundary,
    selectedComponent,
    latestUndoRedoActionId,
    layout,
    model,
  ]);

  return (
    dynamicStaticCardQueryString && (
      <>
        <DummyPropsEditFormRenderer
          dynamicStaticCardQueryString={dynamicStaticCardQueryString}
        />
        {componentSource === 'Module component' && emptyProp ? (
          <Text size="4">This component has no props.</Text>
        ) : (
          ''
        )}
      </>
    )
  );
};

export default DummyPropsEditForm;
