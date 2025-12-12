import React, {
  createContext,
  useContext,
  useEffect,
  useRef,
  useState,
} from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { Spinner, Text } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { getPropsValues } from '@/components/form/formUtil';
import { syncPropSourcesToResolvedValues } from '@/components/form/InputBehaviorsComponentPropsForm';
import twigToJSXComponentMap from '@/components/form/twig-to-jsx-component-map';
import { FORM_TYPES } from '@/features/form/constants';
import {
  clearFieldValues,
  selectFormValues,
} from '@/features/form/formStateSlice';
import {
  isEvaluatedComponentModel,
  selectLayout,
  selectModel,
} from '@/features/layout/layoutModelSlice';
import { findComponentByUuid } from '@/features/layout/layoutUtils';
import {
  selectEditorFrameContext,
  selectLatestUndoRedoActionId,
  selectSelectedComponentUuid,
} from '@/features/ui/uiSlice';
import { useDrupalBehaviors } from '@/hooks/useDrupalBehaviors';
import useInputUIData from '@/hooks/useInputUIData';
import hyperscriptify from '@/local_packages/hyperscriptify';
import propsify from '@/local_packages/hyperscriptify/propsify/standard/index.js';
import { useGetComponentsQuery } from '@/services/componentAndLayout';
import { useGetComponentInstanceFormQuery } from '@/services/componentInstanceForm';
import {
  selectUpdateComponentLoadingState,
  useUpdateComponentMutation,
} from '@/services/preview';
import { AJAX_UPDATE_FORM_STATE_EVENT } from '@/types/Ajax';
import { isPropSourceComponent } from '@/types/Component';
import parseHyperscriptifyTemplate from '@/utils/parse-hyperscriptify-template';

import type {
  ComponentModel,
  EvaluatedComponentModel,
  RegionNode,
} from '@/features/layout/layoutModelSlice';
import type { AjaxUpdateFormStateEvent } from '@/types/Ajax';
import type { CanvasComponent, FieldData } from '@/types/Component';
import type { InputUIData } from '@/types/Form';
import type { TransformConfig } from '@/utils/transforms';

const TransformsContext = createContext<TransformConfig | undefined>(undefined);

export const useComponentTransforms = () => {
  return useContext(TransformsContext);
};

interface ComponentInstanceFormRendererProps {
  queryString: string;
}
interface ComponentInstanceFormProps {}

const ComponentInstanceFormRenderer: React.FC<
  ComponentInstanceFormRendererProps
> = (props) => {
  const formState = useAppSelector((state) =>
    selectFormValues(state, FORM_TYPES.COMPONENT_INSTANCE_FORM),
  );
  const { queryString } = props;
  const { showBoundary } = useErrorBoundary();
  const inputAndUiData: InputUIData = useInputUIData();
  const { selectedComponentType, version, selectedComponent } = inputAndUiData;
  const editorFrameContext = useAppSelector(selectEditorFrameContext);

  const [jsxFormContent, setJsxFormContent] =
    useState<React.ReactElement | null>(null);
  const [currentComponentId, setCurrentComponentId] = useState<string | null>(
    null,
  );
  const formRef = useRef(null);
  const selectedComponentId = selectedComponent || 'noop';
  const skip = useAppSelector((state) =>
    selectUpdateComponentLoadingState(state, selectedComponentId),
  );
  const { currentData, error, originalArgs, isFetching } =
    useGetComponentInstanceFormQuery(
      { queryString: queryString, type: editorFrameContext },
      {
        skip,
      },
    );
  const { data: components } = useGetComponentsQuery();

  const [patchComponent] = useUpdateComponentMutation({
    fixedCacheKey: selectedComponentId,
  });

  useEffect(() => {
    if (error) {
      showBoundary(error);
    }
  }, [error, showBoundary]);

  const { html, transforms } = currentData || {
    html: false,
    transforms: false as const,
  };

  const persistentTransforms = useRef<undefined | TransformConfig>(undefined);

  useEffect(() => {
    if (transforms !== false && !isFetching) {
      persistentTransforms.current = transforms;

      // We also store transforms in the global window object as a fallback.
      // The persistent transforms are typically made available to other
      // components via the TransformsContext, but in some cases such as AJAX
      // rebuilds, the component might be active without access to that
      // context.
      if (!window._canvasTransforms) {
        window._canvasTransforms = {};
      }
      window._canvasTransforms[selectedComponentType] = transforms;
    }
  }, [transforms, isFetching, selectedComponentType]);

  useEffect(() => {
    if (!html) {
      return;
    }
    const template = parseHyperscriptifyTemplate(html as string);
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
    const originalUrlSearchParams = new URLSearchParams(
      originalArgs?.queryString,
    );
    const componentId = originalUrlSearchParams.get('form_canvas_selected');
    const latestUndoRedoActionId = originalUrlSearchParams.get(
      'latestUndoRedoActionId',
    );
    setCurrentComponentId(componentId);

    setJsxFormContent(
      // Wrapping the constructed `ReactElement` for the form so we can add a
      // key which tells React when to re-render this subtree. The component ID
      // is granular enough. Using the entire value of
      // `queryString` would cause the form to re-render while
      // prop values are being updated by the user in the contextual panel,
      // causing the form to lose focus.
      // A `<div>` is used instead of `React.Fragment` so a test ID can be added.
      <div
        key={`${componentId}-${latestUndoRedoActionId}`}
        data-testid={`canvas-component-form-${componentId}`}
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
  }, [html, originalArgs]);

  // Listen for updates to form state from ajax.
  useEffect(() => {
    const ajaxUpdateFormStateListener: (
      e: AjaxUpdateFormStateEvent,
    ) => void = ({ detail }) => {
      const { updates, formId } = detail;
      // We only care about the component instance form, not the entity form.
      if (formId === 'component_instance_form') {
        // Apply transforms for form state.
        const { propsValues: values, selectedModel } = getPropsValues(
          updates,
          inputAndUiData,
          currentData ? currentData.transforms : {},
        );

        if (Object.keys(values).length === 0) {
          // Nothing has changed, no need to patch.
          return;
        }

        // This update will include the entire model, so ensure all existing
        // values are properly transformed.
        const { propsValues: transformedFormState } = getPropsValues(
          formState,
          {
            ...inputAndUiData,
            model: { [selectedComponentId]: selectedModel },
          },
          currentData ? currentData.transforms : {},
        );

        // And then send data to backend - this will:
        // a) Trigger server side validation/transformation
        // b) Update both the preview and the model - see the pessimistic update
        //    in onQueryStarted in preview.ts
        const resolved = {
          ...selectedModel.resolved,
          ...transformedFormState,
          ...values,
        };

        const component = components?.[selectedComponentType];
        if (isEvaluatedComponentModel(selectedModel) && component) {
          patchComponent({
            type: editorFrameContext,
            componentInstanceUuid: selectedComponentId,
            componentType: `${selectedComponentType}@${version}`,
            model: {
              source: syncPropSourcesToResolvedValues(
                selectedModel.source,
                component,
                resolved,
              ),
              resolved,
            },
          });
          return;
        }
        patchComponent({
          type: editorFrameContext,
          componentInstanceUuid: selectedComponentId,
          componentType: `${selectedComponentType}@${version}`,
          model: {
            ...selectedModel,
            resolved,
          },
        });
      }
    };
    document.addEventListener(
      AJAX_UPDATE_FORM_STATE_EVENT,
      ajaxUpdateFormStateListener as unknown as EventListener,
    );
    return () => {
      document.removeEventListener(
        AJAX_UPDATE_FORM_STATE_EVENT,
        ajaxUpdateFormStateListener as unknown as EventListener,
      );
    };
  });

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
      {/* Don't accept pointer events while the component is updating */}
      <div
        style={{
          pointerEvents: skip ? 'none' : 'all',
        }}
        ref={formRef}
      >
        {persistentTransforms.current && (
          <TransformsContext.Provider value={persistentTransforms.current}>
            {jsxFormContent}
          </TransformsContext.Provider>
        )}
      </div>
    </Spinner>
  );
};

const ComponentInstanceForm: React.FC<ComponentInstanceFormProps> = () => {
  const dispatch = useAppDispatch();
  const model = useAppSelector(selectModel);
  const layout = useAppSelector(selectLayout);
  const { data: components, error } = useGetComponentsQuery();
  const { showBoundary } = useErrorBoundary();
  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  const latestUndoRedoActionId = useAppSelector(selectLatestUndoRedoActionId);

  const [formQueryString, setFormQueryString] = useState('');
  const [emptyProp, setEmptyProp] = useState(false);
  const [componentSource, setComponentSource] = useState('');
  const [renderComponentId, setRenderComponentId] = useState<string | null>(
    null,
  );
  const previousModelRef = useRef<EvaluatedComponentModel | null>(null);
  const previousSelectedComponentRef = useRef<string | null>(null);
  const previousLatestUndoRedoActionIdRef = useRef<string | null>(null);

  const buildPreparedModel = (
    model: ComponentModel,
    component: CanvasComponent,
  ): EvaluatedComponentModel => {
    if (!isPropSourceComponent(component)) {
      return model as EvaluatedComponentModel;
    }
    // The prepared model combines prop values from the model and prop metadata
    // from the SDC definition.
    const fieldData = component.propSources;
    const missingProps = Object.keys(fieldData).filter(
      (key) => !(key in model.resolved),
    );

    const preparedModel: EvaluatedComponentModel = {
      ...model,
    } as EvaluatedComponentModel;
    missingProps.forEach((propName: string) => {
      preparedModel.source = {
        ...preparedModel.source,
        [propName]: fieldData[propName],
      };
    });
    return preparedModel;
  };

  useEffect(() => {
    dispatch(clearFieldValues('component_instance_form'));
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
    const selectedModel = model[selectedComponent];
    const node = findComponentByUuid(layout, selectedComponent);
    if (!node) {
      return;
    }
    const [selectedComponentType] = node.type.split('@');

    // This is metadata about the props of the SDC being edited. This is specific
    // to the SDC *type* but unconcerned with this SDC *instance*.
    const component = components[selectedComponentType];
    const selectedComponentFieldData: FieldData = isPropSourceComponent(
      component,
    )
      ? component.propSources
      : {};

    // Check if this component has any props or not.
    if (
      isPropSourceComponent(component) &&
      Object.keys(selectedComponentFieldData).length === 0
    ) {
      setFormQueryString('');
      setEmptyProp(true);
    } else {
      setEmptyProp(false);
    }

    const builtPreparedModel = buildPreparedModel(selectedModel, component);
    const prevModel = previousModelRef.current;
    const prevSelectedComponent = previousSelectedComponentRef.current;
    const prevLatestUndoRedoActionId =
      previousLatestUndoRedoActionIdRef.current;

    // Check if source actually changed (handle components without source like blocks)
    const sourceChanged = (() => {
      const prevSource = prevModel?.source;
      const currentSource = builtPreparedModel.source;

      // If neither has source (e.g., block components), no source change
      if (!prevSource && !currentSource) {
        return false;
      }

      // If one has source and the other doesn't, it changed
      if (!prevSource || !currentSource) return true;

      // Both have source, compare them
      return JSON.stringify(prevSource) !== JSON.stringify(currentSource);
    })();

    // Only build and update formQueryString if:
    // - First render (!prevModel)
    // - Component changed (user selected different component)
    // - Undo/redo occurred (latestUndoRedoActionId changed)
    // - Source changed
    const shouldUpdate =
      !prevModel ||
      prevSelectedComponent !== selectedComponent ||
      prevLatestUndoRedoActionId !== latestUndoRedoActionId ||
      sourceChanged;

    if (shouldUpdate) {
      // Build the query string only when needed
      const tree = findComponentByUuid(layout, selectedComponent);
      const query = new URLSearchParams({
        form_canvas_tree: JSON.stringify(tree),
        form_canvas_props: JSON.stringify(builtPreparedModel),
        form_canvas_selected: selectedComponent,
        latestUndoRedoActionId,
      });
      const queryString = `?${query.toString()}`;
      setFormQueryString(queryString);
      setRenderComponentId(selectedComponent);
    }

    // Always update refs after the shouldUpdate check, so they track what we've processed
    // This allows subsequent runs to detect if the model actually changed
    previousModelRef.current = builtPreparedModel;
    previousSelectedComponentRef.current = selectedComponent;
    previousLatestUndoRedoActionIdRef.current = latestUndoRedoActionId;

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
    formQueryString &&
    renderComponentId === selectedComponent && (
      <>
        <ComponentInstanceFormRenderer queryString={formQueryString} />
        {componentSource === 'Module component' && emptyProp ? (
          <Text size="4">This component has no props.</Text>
        ) : (
          ''
        )}
      </>
    )
  );
};

export default ComponentInstanceForm;
