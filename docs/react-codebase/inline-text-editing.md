# Inline text editing

Content authors can edit text props directly in the preview: click the text of a selected component (so double-click any component's text), or press Enter on a focused component. This document explains how that works in the React app. The decision is recorded in [ADR 25](../adr/0025-edit-text-props-in-place-in-the-preview.md); what component developers need to know is in [Inline text editing for component developers](../inline-editing.md).

In the rest of this document, `Drupal Canvas` will be written as `Canvas`.

## Finding a prop's text

`findInlineEditableProps()` lists the props of a component instance that can be edited in place, each with a target: an element marked `data-canvas-prop`, the range between the server's `<!-- canvas-prop-start-… -->` markers, or the one element whose text is the stored value. A prop qualifies only if its rendered text equals its stored value. Results are cached per preview document and model, since pointer moves look them up. With `localStorage['canvas.debugInlineEditing'] = '1'`, the reasons props were rejected are logged.

Hit-testing is geometric, against the box of the element that becomes the editing host: themes stack transparent layers over text, such as stretched card links, which hit-testing APIs would return instead.

## Lifecycle of a text-edit session

```
ComponentOverlay (click on text of the selected component, or Enter)
  └─ dispatch(startTextEditSession({componentUuid, propName, point}))
InlineTextEditing (rendered by Viewport)
  ├─ makes the target editable, places the caret
  ├─ while ui.selectIsInlineTextBusy: preview documents are held
  │  (IframeSwapper `holdUpdates`), undo and component shortcuts are off
  └─ on Escape, Enter (single line), Tab past the last text, or focus leaving:
       commitInlineText() sends the same PATCH as the sidebar form, once
```

- **Source of truth.** While a session is active the preview DOM holds the in-progress value. Nothing is written to Redux, so a session is one undo entry.
- **No re-render under the caret.** `IframeSwapper` keeps its current document while a session or its commit is pending, then applies the latest server document.
- **Overlay.** The edited component's overlay, and the overlays of its ancestors, let pointer events through to the text. Other outlines and name tags are hidden. Links in the preview do not navigate while editing.
- **Keyboard.** Tab and Shift+Tab move between the component's texts; past the last one, focus returns to the component overlay, as with Escape. Backspace, Delete and copy or paste shortcuts of the editor frame are off while text is busy, also on the toolbar.
- **Commits** are serialized: prop updates are server-first, so each commit waits for the model the previous one produced. A commit is refused if the route changed since the session started, because request URLs follow the route. After a commit, `refreshComponentForm()` bumps `ui.componentFormRevision`, which re-keys the component instance form so it shows the new value. Values are not written into the sidebar's fields while typing: the form tracks its fields itself, and would send such a value again as a second change. A plain text field instead shows a copy of the typed text over it (`mirrorSidebarText()`), until the form is rebuilt with the saved value.
- **Failures.** A failed commit reverts the preview to the stored value and offers Try again and Copy text. Edits refused because of a conflict or navigation are kept in `sessionStorage` and offered again after a reload.
- **Required props** left empty keep their previous value. Removing an optional prop's text offers an undo.
- **Focus.** Switching windows or tabs does not end a session. Within two seconds after a session ends, Backspace or Delete on its component reopens the text instead of deleting the component.
- **Pending values.** A session opened on a prop whose commit is still in flight starts from that value (`getPendingInlineValue()`), not the older model. A failed commit does not revert text that is being edited again.
- **Form refresh.** While another session is active, the form refresh after a commit waits until that session ends, so the sidebar keeps its "Editing on canvas" state.
- **Empty props.** While a component is selected, its editable props that are empty show an "Add …" placeholder in their element.
- **Onboarding.** The first time a component with editable text is selected, a tip explains double-click and Escape. It is not shown again after it is dismissed or after the first session.

## Rich text

Formatted text is edited by Drupal's own CKEditor 5, the editor the sidebar uses, running as a `DecoupledEditor` on the element in the preview iframe. Its configuration comes from the text format's editor settings in `drupalSettings`, built by the same helpers as the sidebar widget (`ui/src/utils/ckeditor5.ts`), so what authors can type, paste and format inline is what the format allows in the sidebar. The settings arrive with the component instance form; a session waits for them.

- Canvas renders the toolbar in the parent document, from the format's own `toolbar.items`, running CKEditor commands. Only items Canvas can render are shown; dropdowns that need a value stay in the sidebar.
- Plugins whose UI would open balloons in the wrong document are swapped for their editing-only parts (`link.Link` → `link.LinkEditing`), and Canvas provides the link field. Formats using plugins outside a known set, such as media or Linkit, stay sidebar-only.
- When a session ends, the preview keeps showing CKEditor's editing view of the content, which neutralizes unsafe URLs and attributes, until the server's render arrives. CKEditor's data is never written into the preview.
- CKEditor's badge would be positioned for the editor's document, not the preview, so it is hidden during a session.
- The link field searches Canvas pages by title (`useGetContentListQuery()`), and accepts paths, domains and email addresses.
- Keyboard: CKEditor's own shortcuts (⌘/Ctrl+B, I, U, undo inside the text); ⌘/Ctrl+K for links; Alt+F10 moves focus to the toolbar, arrow keys move between buttons, Escape returns to the text.

## Code

| File | Responsibility |
|---|---|
| `packages/preview-geometry/src/markers.ts` | `discoverCanvasPropBoundaries()`: pairs prop marker comments |
| `ui/src/features/layout/previewOverlay/inlineText/inlineTextEditing.ts` | Finding props, hit-testing, editing hosts, plain text values, links |
| `ui/src/features/layout/previewOverlay/inlineText/InlineTextEditing.tsx` | Session controller and `useInlineEditableProps()` |
| `ui/src/features/layout/previewOverlay/inlineText/commitInlineText.ts` | The commit thunk |
| `ui/src/features/layout/previewOverlay/inlineText/richTextEditor.ts` | CKEditor 5 on a rich text host |
| `ui/src/features/layout/previewOverlay/inlineText/InlineTextToolbar.tsx` | Floating toolbar |
| `ui/src/features/layout/previewOverlay/inlineText/InlineTextFeedback.tsx` | Save indicator and notices |
| `ui/src/features/layout/previewOverlay/inlineText/sidebarField.ts` | Labels, focus and state of the prop's sidebar field |
| `ui/src/utils/ckeditor5.ts` | CKEditor 5 configuration from Drupal's format settings |
| `src/Twig/CanvasPropVisitor.php` | Emits the prop markers |
| `tests/src/Playwright/tests/isolatedPerTest/inlineTextEditing.spec.ts` | End-to-end coverage |

See also [page-preview.md](page-preview.md).
