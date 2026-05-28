# Canvas Component Instance and Page Data (Entity) Forms Architecture

## TL;DR

Canvas has two special forms rendered in React that are based on Drupal render arrays: the **Component Instance Form** (for configuring component props) and the **Page Data Form** (the entity edit form within Canvas). Each input is integrated with Redux via React Hook Form (RHF), enabling real-time validation, debounced auto-save back to Drupal, and undo/redo support — all while preserving Drupal behaviors like AJAX and autocomplete.

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  API ENDPOINTS (Drupal Server)                                              │
│                                                                             │
│  Form HTML endpoints:                                                       │
│    PATCH /canvas/api/v0/form/component-instance/{entity_type}/{entity}      │
│    GET /canvas/api/v0/form/content-entity/{entity_type}/{entity}/{mode}     │
│                                                                             │
│  Layout endpoints (receive changes, return preview):                        │
│    PATCH /canvas/api/v0/layout/{entity_type}/{entity}                       │
│       (updates one component)                                               │
│    POST /canvas/api/v0/layout/{entity_type}/{entity}                        │
│       (updates full layout/model/page data)                                 │
└──────────────────────────────────┬──────────────────────────────────────────┘
                                   │ RTK Query
                                   ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  REACT CLIENT                                                               │
│                                                                             │
│  <DrupalForm>  ← FormProvider (React Hook Form + custom FormContext)        │
│    │                                                                        │
│    └─ <DrupalFormElement>                                                   │
│         └─ <DrupalInput> / <DrupalSelect> / <DrupalTextArea> / ...          │
│              │                                                              │
│              └─ withRHF(WrappedComponent)                                   │
│                   │                                                         │
│                   ├─ formId === "component_instance_form"                   │
│                   │    └─ <ComponentFormField>                              │
│                   │         └─ RHF <Controller>                             │
│                   │              └─ JSON Schema validation                  │
│                   │              └─ Transforms → patchComponent()           │
│                   │                                                         │
│                   └─ formId === "page_data_form"                            │
│                        └─ <PageDataFormField>                               │
│                             └─ RHF <Controller>                             │
│                             └─ HTML5 validation                             │
│                             └─ setPageData() → Redux                        │
│                                                                             │
│  On change → Redux state updated → Preview component detects change         │
│            → POST to layout endpoint → new preview HTML returned            │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## API Endpoints

### Form HTML Endpoints

These endpoints return the Drupal-rendered form HTML that gets converted to React components:

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/canvas/api/v0/form/component-instance/{entity_type}/{entity}` | PATCH | Returns component instance form HTML + transforms config |
| `/canvas/api/v0/form/content-entity/{entity_type}/{entity}/{form_mode}` | GET | Returns entity form HTML (title, path, etc.) |

**Response format:**
```json
{
  "html": "<form data-form-id=\"component_instance_form\">...</form>",
  "transforms": { "propName": { "mainProperty": { "name": "value" } } },
  "css": [...],
  "js": [...],
  "settings": {...}
}
```

### Layout Endpoints

These endpoints handle layout/model changes and return updated preview HTML:

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/canvas/api/v0/layout/{entity_type}/{entity}` | GET | Fetch initial layout, model, and preview |
| `/canvas/api/v0/layout/{entity_type}/{entity}` | PATCH | Update a single component's model, returns preview |
| `/canvas/api/v0/layout/{entity_type}/{entity}` | POST | Update full layout/model/page data, returns preview |

The **PATCH** endpoint is used by `ComponentFormField` for uses cases such as AJAX field addition where a value might update without direct interaction with a form field.

The **POST** endpoint is used by the `Preview` component (`ui/src/features/layout/preview/Preview.tsx`) which watches for changes to:
- The layout tree (component arrangement)
- The component model (all component props)
- The page data (entity form fields)

When any of these Redux slices change, `Preview` sends the full state to the POST endpoint to generate an updated preview. Requests to the POST endpoint are queued to prevent parallel requests, which can result in locked entity errors and other race conditions.

---

## Preview Generation

The `Preview` component (`ui/src/features/layout/preview/Preview.tsx`) is responsible for keeping the visual preview in sync with the editor state. It watches three Redux selectors:

- `selectLayout` — The component tree structure
- `selectModel` — All component instance props
- `selectPageData` — Entity form field values (title, path, etc.)

When any of these change, `Preview` triggers a POST request via `useQueuedPostPreviewMutation`:

```typescript
await postPreview({
  layout,
  model,
  entity_form_fields,
  entityId,
  entityType,
});
```

The server renders the full page with the updated data and returns the HTML. A request queue ensures only one request is in flight at a time — if changes arrive while a request is pending, only the most recent state is sent after the current request completes.

**AJAX awareness**: Preview requests wait for any in-progress Drupal AJAX operations to complete before firing, preventing race conditions with AJAX-dependent form widgets.

---

## The Two Form Types

### Component Instance Form
Edits individual component instances within a layout. When you select a component in the Canvas editor, this form appears in the sidebar showing the component's configurable props.

- **Form ID**: `component_instance_form`
- **Endpoint**: `PATCH /canvas/api/v0/form/component-instance/{entity_type}/{entity}`
- **Returns**: The Drupal Form API form markup (which is then converted to React)
- **Input Validation**: JSON Schema validation via AJV against the component's prop schema
- **Data flow**: Form values → Transforms → `patchComponent()` → Layout API

### Page Data Form
The Drupal entity edit form (e.g., node title, path, publishing options) rendered within Canvas rather than as a separate admin page.

- **Form ID**: `page_data_form`
- **Endpoint**: `GET /canvas/api/v0/form/content-entity/{entity_type}/{entity}/{form_mode}`
- **Input Validation**: HTML5 native validation API (`checkValidity()`)
- **Data flow**: Form values → `setPageData()` → Redux pageData slice

---

## `DrupalForm` — The Form Root

`DrupalForm` (`ui/src/components/form/components/drupal/DrupalForm.tsx`) is the root component that wraps all Canvas-managed forms. It provides:

1. **Form Context Setup**: Reads the `data-form-id` attribute to determine which form type is active, then wraps children in a dual-layer context provider:
   - Custom `FormContext` (provides `formId` and RHF `methods`)
   - RHF's `FormProvider` (provides react-hook-form context)

2. **AJAX Content Handling**: Listens for `canvas:renderAjaxContent` custom events and creates React portals for AJAX-injected content. This ensures dynamically added form elements:
   - Are inserted within the expected React component tree location
   - Have access to `FormProvider` context (critical for RHF integration)
   - Have Drupal behaviors re-attached

```tsx
// Simplified structure
<FormContext.Provider value={{ formId, methods }}>
  <RHFFormProvider {...methods}>
    <Form ref={formRef}>
      {children}
      {/* AJAX portals rendered here */}
    </Form>
  </RHFFormProvider>
</FormContext.Provider>
```

If no `formId` is present (e.g., forms outside the editor), `DrupalForm` renders a plain form without the context providers.

---

## `Drupal*` Components — The Bridge Layer

Components in `ui/src/components/form/components/drupal/` bridge Drupal's form structure and Canvas's React UI components. Key examples:

- **`DrupalInput`**: Routes by input type (`checkbox`, `radio`, `hidden`, `number`, autocomplete, etc.) to the appropriate base component
- **`DrupalFormElement`**: Renders the form element wrapper with label, description, prefix/suffix, and error display
- **`DrupalSelect`**, **`DrupalTextArea`**, etc.: Thin wrappers connecting Drupal attributes to base UI components

These components share a common pattern:
1. Receive props/attributes from the Drupal render array
2. Map them to the corresponding base UI component (from `ui/src/components/form/components/`)
3. **Are wrapped with `withRHF()`** to integrate with form state management

The base UI components (e.g., `Checkbox`, `TextField`, `Select`) contain ref-based logic allowing them to respond to DOM and jQuery events, enabling integration with Drupal's vanilla JS behaviors.

---

## `withRHF` — The React Hook Form Integration HOC

`withRHF` (`ui/src/components/form/withRHF.tsx`) is the heart of form state management. It wraps every interactive form input and:

1. **Extracts the field name** from `props.attributes.name` (or `data-canvas-name` for radios)

2. **Checks for context** — if no RHF context exists (forms outside the editor), renders the component without RHF integration.

3. **Routes to the appropriate field component** based on `formContext.formId`:
   - `"component_instance_form"` → `<ComponentFormField>`
   - `"page_data_form"` → `<PageDataFormField>`

4. **Handles AJAX field registration** via `useAjaxFieldRegistration` — fields added by Drupal AJAX after initial render are dynamically registered with RHF

5. **Syncs `form_build_id`** via `useFormBuildIdSync` — intercepts Drupal's AJAX `update_build_id` commands and updates both RHF and Redux state

6. **Optimizes rendering** via `React.memo` with deep prop comparison to prevent unnecessary re-renders

---

## `ComponentFormField` — Component Instance Form Fields

`ComponentFormField` (`ui/src/components/form/withRHF-fields/ComponentFormField.tsx`) handles each field in the component instance form. This is the most complex piece.

### Initialization

- **`useComponentInitialValue`**: Dispatches initial field value to Redux on mount
- **`useUndoRedoSync`**: Watches `latestUndoRedoActionId` in Redux; when undo/redo occurs, syncs RHF state back from Redux
- **`useComponentFormInputInfo`**: Gathers contextual data (component type, transforms, prop name, whether it's a scalar prop, etc.)

### Rendering

Uses RHF's `<Controller>` to create a controlled input:
- **Validation rules**: JSON Schema validation via AJV against the component's prop schema
- **`render` callback**: Applies enhanced `onChange`/`onBlur` handlers and value binding via `applyEnhancedProps()`

### On Change Flow

```
User types in input
  │
  ▼
enhancedOnChange()
  │
  ├─► parseNewValue()
  │     Extracts raw value, applies transforms if applicable
  │
  ├─► field.onChange()
  │     Updates RHF state (for UI consistency)
  │
  ├─► dispatch(setFieldValue())
  │     Updates Redux formState slice
  │
  ├─► validateNewValue()
  │     Validates against JSON Schema
  │
  └─► if valid:
        │
        ▼
      updateLayoutModelStore() [debounced for most inputs]
        │
        ▼
      createComponentFormStateHandler()
        │
        ├─► getPropsValues()
        │     Applies transform pipeline to convert form values → prop values
        │
        ├─► syncPropSourcesToResolvedValues()
        │     Reconciles source and resolved values
        │
        └─► patchComponent()
              │
              ├─► PATCH to /canvas/api/v0/layout/{entity_type}/{entity}
              ├─► Updates model in Redux store
              └─► Triggers preview re-render
```

### Error Handling

`ComponentFormField` displays validation errors from two sources:
- **RHF validation errors**: From JSON Schema validation
- **Blocking errors**: From Redux store (server-side validation failures)

---

## `PageDataFormField` — Page Data Form Fields

`PageDataFormField` (`ui/src/components/form/withRHF-fields/PageDataFormField.tsx`) handles entity form fields with a simpler flow than `ComponentFormField`.

### Key Differences from ComponentFormField

| Aspect | ComponentFormField | PageDataFormField |
|--------|-------------------|-------------------|
| Validation | JSON Schema via AJV | HTML5 native validation |
| Store updates | `patchComponent()` → Layout API | `setPageData()` → Redux slice |
| Transforms | Yes (complex value mapping) | No |
| External sync | Via undo/redo | `useRespondToPageDataStoreUpdates` |

### Hooks

- **`usePageDataFormInputInfo`**: Gathers simpler page data retrieval info
- **`useUndoRedoSync`**: Same undo/redo support as component form
- **`useRespondToPageDataStoreUpdates`**: Watches for external pageData changes and syncs them to RHF

### On Change Flow

```
User types in input
  │
  ▼
enhancedOnChange()
  │
  ├─► field.onChange()
  │     Updates RHF state
  │
  ├─► dispatch(setFieldValue())
  │     Updates Redux formState slice
  │
  └─► createPageDataFormStateHandler() [debounced]
        │
        ├─► Filters internal fields (form_build_id, etc.)
        └─► dispatch(setPageData())
              Updates Redux pageData slice
```

---

## Transforms

Transforms convert between Drupal form widget values and component prop values. For detailed documentation on transforms including how to create custom transforms, see the [Redux-integrated field widgets documentation](redux-integrated-field-widgets.md#34-transforms).

---

## Drupal Behaviors & AJAX Integration

After the form renders as React elements, `useDrupalBehaviors` calls `Drupal.attachBehaviors()` on the form DOM element. This enables Drupal JavaScript behaviors (autocomplete, AJAX, etc.) to work within the React-rendered form.

For AJAX specifically:

1. **Portal rendering**: `DrupalForm` listens for `canvas:renderAjaxContent` events and uses React portals to ensure AJAX-added content is within the parent Form's component tree,  maintaining `FormProvider` context access

2. **Dynamic field registration**: `useAjaxFieldRegistration` registers AJAX-added inputs with RHF

3. **Build ID sync**: `useFormBuildIdSync` intercepts Drupal's `update_build_id` AJAX commands and, when in a React-rendered form, handles the command in a different manner that will functional properly regardless of the number of re-renders.

---

## Real-Time Preview Optimization

For **scalar props** (string, number, boolean, integer) on components, the system fires a `ComponentPreviewUpdateEvent` before sending the PATCH request. Listeners can attempt a **real-time client-side preview update** by directly manipulating the preview DOM:

- **If successful**: The backend PATCH is debounced (1 second) to reduce server load while still persisting the change
- **If not possible**: The PATCH fires immediately and the server returns the updated preview

This optimization provides instant visual feedback for simple text/number changes while ensuring complex prop changes still get server-rendered previews. The full POST-based preview flow (see [Preview Generation](#preview-generation)) handles all other cases including layout rearrangement and undo/redo.
