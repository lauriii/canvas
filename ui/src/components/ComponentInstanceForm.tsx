import React, {
  createContext,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { Spinner, Text } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { getPropsValues } from '@/components/form/react-hook-form/fields/componentFormData';
import twigToJSXComponentMap from '@/components/form/twig-to-jsx-component-map';
import NativePropSlot from '@/components/form/widgets/NativePropSlot';
import {
  compilePropStates,
  DEFAULT_SLOT_STATE,
  evaluatePropStates,
} from '@/components/form/widgets/propStates';
import { registerDefaultWidgets } from '@/components/form/widgets/registerDefaultWidgets';
import {
  buildClientWidgetContext,
  resolveNativeWidgetForProp,
} from '@/components/form/widgets/registry';
import { clearPendingWrites } from '@/components/form/widgets/useNativePropWrite';
import { FORM_TYPES } from '@/features/form/constants';
import {
  clearFieldValues,
  selectFormValues,
} from '@/features/form/formStateSlice';
import {
  isEvaluatedComponentModel,
  selectLayout,
  selectModel,
  syncPropSourcesToResolvedValues,
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
  usePatchComponent,
} from '@/services/preview';
import { AJAX_UPDATE_FORM_STATE_EVENT } from '@/types/Ajax';
import { isPropSourceComponent } from '@/types/Component';
import { getPropFormsSettings } from '@/utils/drupal-globals';
import {
  markSelectionStart,
  measureFormInteractive,
} from '@/utils/formInteractiveMetrics';
import parseHyperscriptifyTemplate from '@/utils/parse-hyperscriptify-template';

import type {
  ClientWidgetContext,
  ClientWidgetDefinition,
} from '@/components/form/widgets/types';
import type {
  ComponentModel,
  EvaluatedComponentModel,
  RegionNode,
} from '@/features/layout/layoutModelSlice';
import type { AjaxUpdateFormStateEvent } from '@/types/Ajax';
import type {
  CanvasComponent,
  FieldData,
  PropSourceComponent,
} from '@/types/Component';
import type { InputUIData } from '@/types/Form';
import type { TransformConfig } from '@/utils/transforms';

// Client widgets must be registered before the first form render; render-time
// resolution is a synchronous map lookup.
registerDefaultWidgets();

const TransformsContext = createContext<TransformConfig | undefined>(undefined);

export const useComponentTransforms = () => {
  return useContext(TransformsContext);
};

interface ComponentInstanceFormRendererProps {
  queryString: string;
  // Set when this renderer is an escape-hatch island for a run of props
  // composed among native widgets, rather than the whole component form.
  islandPropName?: string;
  // Called once the island's form content has rendered; used to mount the
  // next island (islands mount sequentially to avoid asset races).
  onFormRendered?: () => void;
}
interface ComponentInstanceFormProps {}

// Builds the query string for the escape-hatch island: the whole-form query
// plus the props filter that scopes the server build to the listed props.
const islandQueryString = (queryString: string, propNames: string[]): string =>
  `${queryString}&form_canvas_props_filter=${encodeURIComponent(
    JSON.stringify(propNames),
  )}`;

// The server-rendered form marks each prop's wrapper with a
// field--name-<prop> class; prop states use it to hide or disable individual
// hatch props inside the island.
const hatchPropClass = (propName: string): string =>
  `field--name-${propName.toLowerCase().replace(/_/g, '-')}`;

// One escape-hatch island: a scoped server-built form for a contiguous run of
// hatch props, composed in prop order among the native widgets.
const HatchIsland: React.FC<{
  runProps: string[];
  disabledProps: string[];
  queryString: string;
  onFormRendered: () => void;
}> = ({ runProps, disabledProps, queryString, onFormRendered }) => {
  const wrapperRef = useRef<HTMLDivElement>(null);

  // A disabled (`enabled: false`) state rule must also block keyboard
  // interaction, not just pointer input; `inert` covers focus and assistive
  // technology. Applied imperatively because the wrappers live inside
  // hyperscriptified server markup. Re-applied whenever the disabled set or
  // the island content changes; AJAX rebuilds inside the island re-trigger
  // this via the parent's state-driven re-renders.
  useEffect(() => {
    const wrapper = wrapperRef.current;
    if (!wrapper) {
      return;
    }
    runProps.forEach((propName) => {
      wrapper
        .querySelectorAll(`.${hatchPropClass(propName)}`)
        .forEach((element) =>
          element.toggleAttribute('inert', disabledProps.includes(propName)),
        );
    });
  });

  return (
    <div
      ref={wrapperRef}
      data-canvas-hatch-island
      data-testid={`canvas-hatch-island-${runProps.join('-')}`}
    >
      <ComponentInstanceFormRenderer
        queryString={queryString}
        islandPropName={runProps.join(',')}
        onFormRendered={onFormRendered}
      />
    </div>
  );
};

const ComponentInstanceFormRenderer: React.FC<
  ComponentInstanceFormRendererProps
> = (props) => {
  const formState = useAppSelector((state) =>
    selectFormValues(state, FORM_TYPES.COMPONENT_INSTANCE_FORM),
  );
  const { queryString, islandPropName, onFormRendered } = props;
  const { showBoundary } = useErrorBoundary();
  const inputAndUiData: InputUIData = useInputUIData();
  const {
    selectedComponentType,
    selectedComponent,
    editorFrameContext,
    components,
  } = inputAndUiData;

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

  const patchComponent = usePatchComponent();

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
        data-testid={
          islandPropName
            ? `canvas-component-form-island-${componentId}-${islandPropName}`
            : `canvas-component-form-${componentId}`
        }
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
    // Escape-hatch islands do not conclude the selection-to-form-interactive
    // measure: the native widgets around them are already interactive.
    if (componentId && !islandPropName) {
      measureFormInteractive(componentId, 'server-form');
    }
    onFormRendered?.();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [html, originalArgs, islandPropName]);

  // Listen for updates to form state from ajax.
  useEffect(() => {
    const ajaxUpdateFormStateListener: (
      e: AjaxUpdateFormStateEvent,
    ) => void = ({ detail }) => {
      const { updates, formId } = detail;
      // We only care about the component instance form, not the entity form.
      if (formId === 'component_instance_form') {
        // Apply transforms for form state.
        const transforms = persistentTransforms.current ?? {};
        const { propsValues: values, selectedModel } = getPropsValues(
          updates,
          inputAndUiData,
          transforms,
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
          transforms,
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
          patchComponent(inputAndUiData, {
            source: syncPropSourcesToResolvedValues(
              selectedModel.source,
              component,
              resolved,
            ),
            resolved,
          });
          return;
        }
        patchComponent(inputAndUiData, {
          ...selectedModel,
          resolved,
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
  useDrupalBehaviors(formRef, jsxFormContent, isFetching);

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

// One prop slot in the native composition: a native client widget when the
// prop's configured widget id resolved to one, otherwise an escape-hatch
// island.
interface CompositionSlot {
  propName: string;
  context: ClientWidgetContext;
  definition: ClientWidgetDefinition | undefined;
}

const ComponentInstanceForm: React.FC<ComponentInstanceFormProps> = () => {
  const dispatch = useAppDispatch();
  const model = useAppSelector(selectModel);
  const layout = useAppSelector(selectLayout);
  const { data: components, error } = useGetComponentsQuery();
  const { showBoundary } = useErrorBoundary();
  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  const latestUndoRedoActionId = useAppSelector(selectLatestUndoRedoActionId);
  const editorFrameContext = useAppSelector(selectEditorFrameContext);

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

  // Start the selection-to-form-interactive measure during the render phase
  // of the first panel render for a new selection, not in a passive effect
  // (which would only run after the form already committed and make the
  // measure meaningless). The measured span still excludes the selection
  // dispatch itself; see formInteractiveMetrics.ts for the boundary.
  const lastMarkedSelection = useRef<string | null>(null);
  if (selectedComponent && lastMarkedSelection.current !== selectedComponent) {
    lastMarkedSelection.current = selectedComponent;
    markSelectionStart(selectedComponent);
  }

  // Native widget edits that have not yet round-tripped through the debounced
  // PATCH, mirrored here so prop-state rules react in the same render cycle
  // instead of lagging by the debounce and network round trip.
  const [pendingStateValues, setPendingStateValues] = useState<
    Record<string, unknown>
  >({});
  // Escape-hatch islands mount sequentially (island N+1 only after island N
  // has rendered) so concurrent form fetches cannot race on shared form
  // assets such as the rich text editor's libraries.
  const [readyIslands, setReadyIslands] = useState(1);

  // Selection changes and undo/redo make the store model authoritative again:
  // drop any in-flight native widget edits so they cannot shadow it.
  useEffect(() => {
    if (selectedComponent) {
      clearPendingWrites(selectedComponent);
    }
    setPendingStateValues({});
    setReadyIslands(1);
  }, [selectedComponent, latestUndoRedoActionId]);

  const selectedNode = selectedComponent
    ? findComponentByUuid(layout, selectedComponent)
    : null;
  const [selectedTypeId, selectedTypeVersion] = (
    selectedNode ? (selectedNode.type as string) : ''
  ).split('@');
  const selectedTypeComponent = selectedTypeId
    ? components?.[selectedTypeId]
    : undefined;

  // Decide how each prop renders: a native client widget resolved from the
  // registry, or an escape-hatch island. The whole-form server path remains
  // for form-API-dependent sources (Blocks, Personalization, Fallback),
  // content templates (prop linking UX), fully hatch-rendered components, and
  // when the kill switch is on.
  const propFormsSettings = useMemo(() => getPropFormsSettings(), []);
  const composition: CompositionSlot[] | null = useMemo(() => {
    if (
      !propFormsSettings.native ||
      editorFrameContext !== 'entity' ||
      !selectedTypeComponent ||
      selectedTypeComponent.broken ||
      !isPropSourceComponent(selectedTypeComponent)
    ) {
      return null;
    }
    const propSources = selectedTypeComponent.propSources;
    const slots = Object.entries(propSources).map(
      ([propName, fieldData]): CompositionSlot => {
        const context = buildClientWidgetContext(
          propName,
          selectedTypeId,
          selectedTypeVersion ?? '',
          fieldData,
        );
        return {
          propName,
          context,
          definition: resolveNativeWidgetForProp(context, propFormsSettings),
        };
      },
    );
    // When no prop is natively renderable, the existing whole-form request is
    // both simpler and cheaper than per-prop islands.
    return slots.some((slot) => slot.definition) ? slots : null;
  }, [
    propFormsSettings,
    editorFrameContext,
    selectedTypeComponent,
    selectedTypeId,
    selectedTypeVersion,
  ]);

  // Declarative prop states: compile once per component version, evaluate
  // synchronously against the current resolved values on every model change.
  const compiledPropStates = useMemo(
    () =>
      isPropSourceComponent(selectedTypeComponent)
        ? compilePropStates(
            (selectedTypeComponent as PropSourceComponent).propSources,
          )
        : {},
    [selectedTypeComponent],
  );
  const propStates = useMemo(
    () =>
      evaluatePropStates(compiledPropStates, {
        ...((model?.[selectedComponent ?? '']?.resolved ?? {}) as Record<
          string,
          unknown
        >),
        // In-flight native edits win over the (older) store model so
        // dependent slots react immediately.
        ...pendingStateValues,
      }),
    [compiledPropStates, model, selectedComponent, pendingStateValues],
  );

  const isNativeComposition = composition !== null;
  // Conclude the measure once the native slots have rendered: this is the
  // interactive moment on the native path (no network involved).
  useEffect(() => {
    if (isNativeComposition && selectedComponent) {
      measureFormInteractive(selectedComponent, 'native');
    }
  }, [isNativeComposition, selectedComponent]);

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
  if (composition && selectedComponent) {
    return (
      // A <form> with the same data-form-id and data-drupal-selector as the
      // server-rendered form, so existing tests and styling hooks keep
      // addressing the prop form the same way on both paths. It never
      // submits; native widgets write straight to the model.
      <form
        data-testid={`canvas-component-form-${selectedComponent}`}
        data-drupal-selector="component-instance-form"
        data-form-id="component_instance_form"
        onSubmit={(e) => e.preventDefault()}
      >
        {(() => {
          // Escape-hatch props are grouped into islands per CONTIGUOUS run of
          // hatch props, so all props render in their defined order. Islands
          // mount sequentially (see readyIslands) because concurrent island
          // fetches race on shared form assets (e.g. the rich text editor's
          // libraries); each island is still a single scoped form fetch.
          const islandRuns: string[][] = [];
          composition.forEach((slot, index) => {
            if (slot.definition) {
              return;
            }
            const previous = composition[index - 1];
            if (previous && !previous.definition) {
              islandRuns[islandRuns.length - 1].push(slot.propName);
            } else {
              islandRuns.push([slot.propName]);
            }
          });
          const islandReady =
            formQueryString && renderComponentId === selectedComponent;

          // Prop states for hatch props target the server-rendered per-prop
          // wrappers inside the islands, so a hidden hatch prop keeps its
          // value without any form request.
          const hatchStateCss = islandRuns
            .flat()
            .map((propName) => {
              const slotState = propStates[propName] ?? DEFAULT_SLOT_STATE;
              if (!slotState.visible) {
                return `[data-canvas-hatch-island] .${hatchPropClass(propName)} { display: none; }`;
              }
              if (!slotState.enabled) {
                return `[data-canvas-hatch-island] .${hatchPropClass(propName)} { pointer-events: none; opacity: 0.5; }`;
              }
              return '';
            })
            .filter(Boolean)
            .join('\n');
          const disabledHatchProps = islandRuns
            .flat()
            .filter(
              (propName) =>
                !(propStates[propName] ?? DEFAULT_SLOT_STATE).enabled,
            );

          let islandIndex = -1;
          const rendered = composition.map(
            ({ propName, context, definition }, index) => {
              const slotState = propStates[propName] ?? DEFAULT_SLOT_STATE;
              if (definition) {
                return (
                  <NativePropSlot
                    key={`${selectedComponent}-${propName}`}
                    context={context}
                    definition={definition}
                    slotState={slotState}
                    onResolvedValueChange={(resolvedValue) =>
                      setPendingStateValues((current) => ({
                        ...current,
                        [propName]: resolvedValue,
                      }))
                    }
                  />
                );
              }
              // Only the first prop of a run renders the run's island.
              const previous = composition[index - 1];
              if (previous && !previous.definition) {
                return null;
              }
              islandIndex += 1;
              // Capture per-island values: islandIndex keeps mutating across
              // the loop, but the callback below runs much later.
              const currentIslandIndex = islandIndex;
              const runProps = islandRuns[currentIslandIndex];
              // Sequential mounting: this island waits until all earlier
              // islands have rendered their form.
              if (!islandReady || currentIslandIndex >= readyIslands) {
                return null;
              }
              return (
                <HatchIsland
                  key={`${selectedComponent}-hatch-island-${runProps[0]}`}
                  runProps={runProps}
                  disabledProps={disabledHatchProps}
                  queryString={islandQueryString(formQueryString, runProps)}
                  onFormRendered={() =>
                    setReadyIslands((current) =>
                      current > currentIslandIndex + 1
                        ? current
                        : currentIslandIndex + 2,
                    )
                  }
                />
              );
            },
          );
          return (
            <>
              {hatchStateCss && <style>{hatchStateCss}</style>}
              {rendered}
            </>
          );
        })()}
      </form>
    );
  }

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
