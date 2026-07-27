# Stable element identifiers in the Canvas editor UI

The Canvas editor UI marks its important interactive elements with a stable,
neutral DOM attribute so that external tooling can find an affordance and keep
finding it across releases. "External tooling" here means anything outside the
React code: browser automation, end-to-end suites in other repositories,
accessibility audits, user research recordings, screenshot diffing, product
instrumentation built by a site owner, and so on. Canvas itself does not read
these attributes and ships no code that consumes them.

The attributes are inert. They add no behavior, no event listeners, and no
network traffic.

## The convention

Two attributes, one convention, used everywhere:

| Attribute                 | Required | Meaning                                                                 |
| ------------------------- | -------- | ----------------------------------------------------------------------- |
| `data-canvas-element`     | Yes      | Names the affordance, for example `publish-confirm` or `library-search`. |
| `data-canvas-element-key` | No       | Distinguishes one instance of a repeated affordance from another.        |

```html
<button data-canvas-element="undo" aria-label="Undo">…</button>

<button data-canvas-element="side-menu-item" data-canvas-element-key="layers">
  …
</button>
```

Rules for `data-canvas-element` values:

1. **Name the affordance, never the measurement.** `publish-confirm`, not
   `publish-conversion-step-3`. The name says what the control is, so it stays
   correct no matter who is counting what.
2. **Lower kebab-case, prefixed by the area of the UI** it belongs to:
   `library-`, `layers-`, `canvas-`, `code-editor-`, `publish-`, `review-`,
   and so on. The prefix is what makes the flat namespace navigable.
3. **Never encode identity in the name.** A DOM `id` must be unique per
   document, which makes it the wrong tool for anything that repeats: every
   component in the library, every layer row, every slot, every prop field.
   That is why these are data attributes, and why a repeating affordance shares
   one name across all of its instances.

Rules for `data-canvas-element-key` values:

1. Only ever a **machine name or an enumerated value**: a panel id, a viewport
   id, a zoom percentage, an extension id, a code editor language.
2. **Never** a title, a label, a prop value, an entity id, a user id, a site
   identifier, or anything else typed by a person or naming a person. Nothing
   in either attribute may carry content or identity.
3. Omit it when the affordance does not repeat, or when the element already
   carries identity in an existing attribute. Library rows already expose
   `data-canvas-component-id` and `data-canvas-type`; layer rows already expose
   `data-canvas-uuid` and `data-canvas-type`. Those are not duplicated here.

## Why not `data-testid`

Canvas already uses `data-testid` in about 150 places, and those values belong
to the Playwright and Cypress suites. This convention is deliberately a
different attribute, and existing test ids were neither renamed nor removed:

- **Different owner, different lifetime.** A test id exists because some test
  needed a handle, and it is fair game to rename or delete when that test
  changes. A `data-canvas-element` value is a published name that outside code
  depends on, so it changes only through the deprecation path below. Sharing one
  attribute would force one of those two policies onto the other.
- **Coverage is incidental, not designed.** Test ids cluster where tests happen
  to have been written. Several important affordances (undo, redo, zoom, the
  preview width menu, the component context menu items, the library Insert
  action) have none at all.
- **Existing values are not a convention.** They mix prefixes (`canvas-`, `xb-`,
  none) and several bake identity straight into the name, for example
  `canvas-empty-slot-drop-zone-${component}:${slot}`. That is exactly the
  pattern this convention avoids.

Where both attributes appear on one element, that is intentional and harmless.

## Stability contract

- A name, once documented here, is not renamed or removed without a deprecation
  period announced in the release notes.
- Moving an identifier to a different element is a breaking change if the new
  element is not the same affordance.
- Adding a new identifier is not a breaking change.
- Adding, removing, or restyling markup around an identified element is not a
  breaking change.
- Component tests in `ui/src` assert the identifiers on the highest traffic
  elements, so a refactor that drops one fails CI rather than silently
  breaking downstream consumers.

## Inventory

Each group below starts with the question an identifier set exists to answer.
Nothing is instrumented that does not answer one of them.

### Entering and leaving the editor

_Do editors reach the editor and leave it deliberately, or bounce out?_

| Identifier    | Element                          | Source                                     |
| ------------- | -------------------------------- | ------------------------------------------ |
| `editor-exit` | "Exit Drupal Canvas" link        | `components/topbar/Topbar.tsx`             |
| `editor-pane` | The editor frame's scrolling pane | `features/editorFrame/EditorFrame.tsx`     |
| `welcome`     | First run welcome callout        | `features/welcome/Welcome.tsx`             |

### Side menu and primary panel

_Which parts of the editor do people actually open?_

| Identifier            | Element                                     | Key                | Source                             |
| --------------------- | ------------------------------------------- | ------------------ | ---------------------------------- |
| `side-menu-item`      | Each side menu button or link               | Panel or route id  | `components/sideMenu/SideMenu.tsx` |
| `primary-panel`       | Body of the open panel                      | Active panel id    | `components/sidePanel/PrimaryPanel.tsx` |
| `primary-panel-close` | Panel close button                          |                    | `components/sidePanel/PrimaryPanel.tsx` |

### Component library: browse, search, filter, insert

_Do editors find components by browsing, by searching, or not at all? Which
insertion path do they use?_

| Identifier                     | Element                                  | Key             | Source                                        |
| ------------------------------ | ---------------------------------------- | --------------- | --------------------------------------------- |
| `library-tab`                  | Components / Patterns tab                | Tab value       | `components/sidePanel/Library.tsx`            |
| `library-search`               | Search field                             | Item type       | `components/sidePanel/LibraryToolbar.tsx`     |
| `library-new-menu`             | "New" dropdown trigger                   | Item type       | `components/sidePanel/LibraryToolbar.tsx`     |
| `library-new-code-component`   | "Code component" menu item               |                 | `features/code-editor/AddCodeComponentButton.tsx` |
| `library-new-folder`           | "Folder" menu item                       |                 | `components/sidePanel/LibraryToolbar.tsx`     |
| `library-folder-name`          | Inline folder name field                 |                 | `components/sidePanel/LibraryToolbar.tsx`     |
| `library-folder-error`         | Folder name validation or API error      |                 | `components/sidePanel/LibraryToolbar.tsx`     |
| `library-folder`               | A folder row (browse, expand, drop into) |                 | `components/sidePanel/SidebarFolder.tsx`      |
| `library-item`                 | A component, pattern, or code component row, and the drag source for it | | `components/list/ListItem.tsx` |
| `library-item-insert`          | "Insert" menu item on a library row      |                 | `components/list/ListItem.tsx`                |

`library-item` deliberately covers every kind of row. Which kind it is, and
which component it is, is already on the same element as `data-canvas-type` and
`data-canvas-component-id`.

### Placement paths

_Which of the seven ways to place a component do editors actually use?_

| Path                | Identifier                       | Source                                            |
| ------------------- | -------------------------------- | ------------------------------------------------- |
| Drag from library   | `library-item` (drag source)     | `components/list/ListItem.tsx`                    |
| Drag within canvas  | `canvas-component` (drag source) | `features/layout/previewOverlay/ComponentOverlay.tsx` |
| Drag within layers  | `layers-item` (drag source)      | `features/layout/layers/ComponentLayer.tsx`       |
| Insert menu         | `library-item-insert`            | `components/list/ListItem.tsx`                    |
| Duplicate           | `component-menu-duplicate`       | `features/layout/preview/ComponentContextMenu.tsx` |
| Move into a slot    | `component-menu-move-into`       | `features/layout/preview/ComponentContextMenuMoveInto.tsx` |
| Paste               | `component-menu-paste`           | `features/layout/preview/ComponentContextMenu.tsx` |
| Pattern insert      | `library-item` with `data-canvas-type="pattern"` | `components/list/ListItem.tsx`    |

The drop targets each path lands on:

| Identifier                       | Element                              | Source                                             |
| -------------------------------- | ------------------------------------ | -------------------------------------------------- |
| `canvas-drop-zone-component`     | Before or after a component          | `features/layout/previewOverlay/ComponentDropZone.tsx` |
| `canvas-drop-zone-slot`          | Before or after a slot               | `features/layout/previewOverlay/SlotDropZone.tsx`   |
| `canvas-drop-zone-region`        | Start or end of a region             | `features/layout/previewOverlay/RegionDropZone.tsx` |
| `canvas-drop-zone-empty-region`  | Empty page or region (also the canvas empty state) | `features/layout/previewOverlay/EmptyRegionDropZone.tsx` |
| `canvas-drop-zone-empty-slot`    | Empty slot                           | `features/layout/previewOverlay/EmptySlotDropZone.tsx` |
| `layers-drop-zone`               | Between rows in the layers panel     | `features/layout/layers/LayersDropZone.tsx`         |

### The component menu

_Which component actions matter, and which are never found?_ One identifier per
action, on both the right-click menu and the row dropdown, because
`UnifiedMenu` renders the same tree as either.

`component-menu` (the menu itself), `component-menu-edit-code`,
`component-menu-duplicate`, `component-menu-copy`, `component-menu-paste`,
`component-menu-create-pattern`, `component-menu-move-up`,
`component-menu-move-down`, `component-menu-move-into`, `component-menu-delete`.

Source: `features/layout/preview/ComponentContextMenu.tsx` and
`features/layout/preview/ComponentContextMenuMoveInto.tsx`.

### Layers panel

_Is the tree used for navigation, for reordering, or ignored?_

| Identifier           | Element                                | Source                                      |
| -------------------- | -------------------------------------- | ------------------------------------------- |
| `layers-region`      | A region row                           | `features/layout/layers/RegionLayer.tsx`    |
| `layers-item`        | A component row, and its drag source   | `features/layout/layers/ComponentLayer.tsx` |
| `layers-item-toggle` | Expand / collapse a component's slots  | `features/layout/layers/ComponentLayer.tsx` |
| `layers-slot`        | A slot row                             | `features/layout/layers/SlotLayer.tsx`      |

### Prop and input forms

_Do editors complete the forms, and where do they hit validation errors?_

| Identifier             | Element                                        | Source                                                    |
| ---------------------- | ---------------------------------------------- | --------------------------------------------------------- |
| `component-props-form` | The component instance form                    | `components/ComponentInstanceForm.tsx`                    |
| `page-data-form`       | The page (entity) form                         | `components/PageDataForm.tsx`                             |
| `form-field-error`     | A field level validation error, both in the React-rendered fields and in Drupal-rendered form elements | `components/form/react-hook-form/fields/FieldErrorDisplay.tsx`, `components/form/components/drupal/DrupalFormElement.tsx` |

Individual fields are not instrumented. There are hundreds of them, they are
generated from component definitions rather than written by hand, and the field
name is already in the DOM. The two form roots plus the shared error element
answer the question without touching generated markup.

### Code editor

_Do people open it, edit, and get working code out the other end?_

| Identifier                       | Element                                       | Key                     | Source                                      |
| -------------------------------- | --------------------------------------------- | ----------------------- | ------------------------------------------- |
| `code-editor`                    | The code editor root (present when open)      |                         | `features/code-editor/CodeEditorUi.tsx`     |
| `code-editor-tab`                | JavaScript / CSS / Global CSS tab             | `js`, `css`, `global-css` | `features/code-editor/CodeEditorUi.tsx`   |
| `code-editor-source`             | The active source editor                      | Active language         | `features/code-editor/CodeEditorUi.tsx`     |
| `code-editor-preview`            | The live preview pane                         |                         | `features/code-editor/CodeEditorUi.tsx`     |
| `code-editor-error`              | Compile failure and the other preview-blocking errors | `compile`, `missing-default-export`, `import` | `features/code-editor/Preview.tsx` |
| `code-editor-add-to-components`  | "Add to components"                           |                         | `features/code-editor/CodeEditorUi.tsx`     |
| `code-editor-data-tab`           | Props / Slots / Data Fetch tab                | Tab value               | `features/code-editor/component-data/ComponentData.tsx` |

There is no save identifier because the code editor has no save control: it
auto-saves, and the saved work reaches the site through the publish flow below.

`code-editor-error` marks the one container that holds every preview-blocking
error, and its key names the first active error rather than one key per card.
In practice a compile failure prevents the other two from being detected, so
they are effectively exclusive.

### Preview controls

_Is preview used, at which viewport, and at what zoom?_

| Identifier             | Element                       | Key             | Source                                     |
| ---------------------- | ----------------------------- | --------------- | ------------------------------------------ |
| `preview-enter`        | "Preview"                     |                 | `components/PreviewControls.tsx`           |
| `preview-exit`         | "Exit Preview"                |                 | `components/PreviewControls.tsx`           |
| `preview-width`        | Preview width menu trigger    |                 | `features/pagePreview/PreviewWidthSelector.tsx` |
| `preview-width-option` | A width option                | Viewport id     | `features/pagePreview/PreviewWidthSelector.tsx` |
| `zoom-level`           | Zoom menu trigger             |                 | `components/zoom/ZoomControl.tsx`          |
| `zoom-level-option`    | A zoom option                 | Zoom percentage | `components/zoom/ZoomControl.tsx`          |

### Undo and redo

_How often is a change reversed?_ `undo` and `redo` in
`components/UndoRedo.tsx`. The keyboard shortcuts dispatch through the same
handlers as these buttons, so the buttons are the affordance worth naming.

### Publish and review changes, including failure states

_How far do editors get through publishing, and what stops them?_

| Identifier                   | Element                                   | Source                                     |
| ---------------------------- | ----------------------------------------- | ------------------------------------------ |
| `publish-review-open`        | "Review N changes" trigger                | `components/review/PublishReview.tsx`      |
| `publish-review-panel`       | The review popover                        | `components/review/PublishReview.tsx`      |
| `publish-review-select-all`  | "Select All"                              | `components/review/PublishReview.tsx`      |
| `publish-confirm`            | "Publish N selected"                      | `components/review/PublishReview.tsx`      |
| `publish-review-selected`    | "Review selected changes"                 | `components/review/PublishReview.tsx`      |
| `publish-errors`             | Server side publish error list            | `components/review/ReviewErrors.tsx`       |
| `publish-error-link`         | Link from an error to the offending component | `components/review/ReviewErrors.tsx`   |
| `publish-conflict-banner`    | Conflict banner                           | `components/review/ConflictBanner.tsx`     |
| `publish-conflict-resolve`   | "Resolve N conflicts"                     | `components/review/ConflictBanner.tsx`     |
| `change-row`                 | One pending change                        | `components/review/changes/ChangeRow.tsx`  |
| `change-row-select`          | Its checkbox                              | `components/review/changes/ChangeRow.tsx`  |
| `change-row-menu`            | Its overflow menu trigger                 | `components/review/changes/ChangeRow.tsx`  |
| `change-row-resolve-conflict` | "Resolve conflict"                       | `components/review/changes/ChangeRow.tsx`  |
| `change-row-view`            | "Review changes"                          | `components/review/changes/ChangeRow.tsx`  |
| `change-row-discard`         | "Discard changes"                         | `components/review/changes/ChangeRow.tsx`  |

The side by side review flow:

| Identifier                            | Element                          | Source                                  |
| ------------------------------------- | -------------------------------- | --------------------------------------- |
| `review-changes-page`                 | The review page                  | `features/review/ReviewChangesView.tsx` |
| `review-changes-close`                | Close                            | `features/review/ReviewChangesView.tsx` |
| `review-changes-previous`             | Previous                         | `features/review/ReviewChangesView.tsx` |
| `review-changes-next`                 | Next                             | `features/review/ReviewChangesView.tsx` |
| `review-changes-select-for-publishing` | "Selected for publishing" switch | `features/review/ReviewChangesView.tsx` |
| `review-changes-apply-version`        | Publish or discard the reviewed version | `features/review/ReviewChangesView.tsx` |

### Patterns and templates

_Are saved patterns and content templates worth their complexity?_

| Identifier          | Element                       | Source                                 |
| ------------------- | ----------------------------- | -------------------------------------- |
| `pattern-save-name` | Pattern name field            | `features/pattern/SavePatternDialog.tsx` |
| `template-new`      | "Add new template"            | `components/sidePanel/Templates.tsx`   |
| `template-item`     | A content template row        | `components/list/TemplateList.tsx`     |

Pattern creation starts at `component-menu-create-pattern` and pattern insert
is `library-item` on the Patterns tab, so neither needs its own name.

### AI page builder

_Is the AI panel opened, and is it kept open?_ `ai-panel-toggle` in
`components/aiExtension/AiToggleButton.tsx` and `ai-panel` on the panel root in
`components/aiExtension/AiWizard.tsx`. The chat transcript is user content and
is not instrumented. The chat input and its submit button live inside the
third-party Deep Chat custom element, which Canvas configures rather than
renders, so they cannot carry an identifier without reaching into that
element's internals.

### Extensions

_Which extensions get used?_ `extension-open`, keyed by extension id, in
`components/extensions/ExtensionButton.tsx`. Extension ids are machine names.
Extensions that render as their own page are reached through
`side-menu-item`, keyed `page-ext-<id>`.

### Empty and error states

_How often does an editor face an empty or broken screen?_

| Identifier     | Element                                       | Source                                    |
| -------------- | --------------------------------------------- | ----------------------------------------- |
| `empty-state`  | The shared empty state callout, which is what a library search with no results renders | `components/EmptyStateCallout.tsx` |
| `error-card`   | The error card used by every error boundary   | `components/error/ErrorCard.tsx`          |
| `error-retry`  | Its retry button                              | `components/error/ErrorCard.tsx`          |
| `error-page`   | Full page error                               | `components/error/ErrorPage.tsx`          |

The canvas empty state is `canvas-drop-zone-empty-region`, which is the same
element an editor drops onto to leave it.

## Deliberately not instrumented

- Individual generated form fields, for the reason given above.
- Purely decorative markup: separators, spacers, icons, tooltips, and layout
  wrappers with no affordance of their own.
- Anything that would need a title, a label, or a value in an attribute.
- Read-only status text, badges, and spinners, which are outcomes of an action
  already identified elsewhere.
- Standalone routes outside the editor (headless frontends, brand kit, page
  list) beyond their side menu entry point. Add them when there is a question
  they answer.
