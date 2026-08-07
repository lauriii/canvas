# Canvas Colors — Implementation Reference

Work-in-progress note for LLM context. Captures what was built, how it works, and what remains.

---

## Data Model

`Color` is a standalone config entity (`canvas.color.<uuid>`). It is **not** embedded in BrandKit.

canvas.color.<uuid>  →  fields: name, cssVariable, value, weight
canvas.brand_kit.<id>  →  colors: <uuid>, <uuid>, ...   (list of Color UUIDs)

`Color::entity_keys['id'] => 'uuid'` — the entity's `id()` returns its UUID. Config file name is therefore `canvas.color.<uuid>`. The `id` field is **not** in `config_export`; Drupal handles it automatically.

### Color Value Format

The `value` field stores color data in **W3C Design Token format**:

```yaml
value:
  colorSpace: srgb    # or 'hsl', with more spaces planned
  components:         # [R, G, B] for sRGB (0-1), [H, S, L] for HSL (H: 0-360, S/L: 0-100)
    - 0.8
    - 0.0
    - 0.0
  alpha: null         # optional; null = fully opaque
  hex: '#cc0000'      # optional 6-digit hex fallback
Color spaces supported:
- srgb — sRGB color space (most common, web standard)
- hsl — HSL cylindrical color space
  Other color spaces can be added in the future as it adheres to W3c Design Token specification.
BrandKit::$colors is list<string>|null — a list of Color entity UUIDs, not embedded objects. Each UUID is validated by BetterConfigExists: prefix: 'canvas.color.' in canvas.schema.yml.
BrandKit::calculateDependencies() declares a config dependency on canvas.color.<uuid> for each color. BrandKit::onDependencyRemoval() self-repairs by removing the UUID when a Color entity is deleted.
API Layer
Routes added (canvas.routing.yml)
color was added to the allowlist regex of five routes:
- canvas.api.config.post — POST /canvas/api/v0/config/{type}
- canvas.api.config.patch — PATCH /canvas/api/v0/config/{type}/{entity}
- canvas.api.config.get — GET /canvas/api/v0/config/{type}/{entity}
- canvas.api.config.delete — DELETE /canvas/api/v0/config/{type}/{entity}
- canvas.api.config.list — GET /canvas/api/v0/config/{type}
The _canvas_http_eligible_config_entity: TRUE requirement checks that the entity class implements CanvasHttpApiEligibleConfigEntityInterface. Color implements this.
Color creation flow
POST /canvas/api/v0/config/color → ApiConfigControllers::post():
1. Decodes body; calls Color::createFromClientSide($data) which strips id and calls Color::create($data) — Drupal assigns a fresh UUID.
2. Validates via typed data (config schema constraints).
3. Saves — triggers Color::postSave() (see below).
4. Returns HTTP 201 with normalized color: { id, name, cssVariable, value, weight }.
PATCH /canvas/api/v0/config/color/{uuid} → ApiConfigControllers::patch() → Color::updateFromClientSide($data) — strips id, sets remaining fields.
OpenAPI schema (openapi.yml)
Color schema and /canvas/api/v0/config/color + /canvas/api/v0/config/color/{configEntityId} endpoints are defined in openapi.yml. NewColor schema omits id.
The value object schema:
value:
  type: object
  properties:
    colorSpace:
      type: string
      enum: [srgb, hsl]
    components:
      type: array
      items:
        type: number
      minItems: 3
      maxItems: 3
    alpha:
      type: ['number', 'null']
    hex:
      type: ['string', 'null']
      pattern: '^#[0-9a-fA-F]{6}$'
BrandKit ↔ Color Registration
Color::postSave() runs on create only ($update === false). It loads all BrandKit entities and appends the new Color UUID to any BrandKit that does not already contain it, then saves.
This means the UI needs only one request to create a color — the BrandKit is updated server-side automatically. The check for existing membership keeps this idempotent and safe if a Color is ever created outside BrandKit context.
Deletion is handled by BrandKit::onDependencyRemoval() — already existed, no changes needed.
BrandKit Normalization Fix
Bug: BrandKit::normalizeForClientSide() called self::normalizeColors($this->getColors()) where getColors() returns list<string> (UUIDs). normalizeColors() expects list<array> (full color objects). Result: all color fields were empty strings in the API response.
Fix (line ~150 of BrandKit.php): Load Color entities via loadMultiple() before normalizing, and add the Color entity-type list cache tag so that creating or deleting a Color also invalidates the cached BrandKit response (not just updates to existing colors):
'colors' => (static function (array $color_ids): array {
    if ($color_ids === []) {
      return [];
    }
    $color_entities = \Drupal::entityTypeManager()
      ->getStorage('color')
      ->loadMultiple($color_ids);
    return self::normalizeColors(\array_map(
      static fn (Color $c): array => [
        'id' => $c->id(),
        'name' => $c->getName(),
        'cssVariable' => $c->getCssVariable(),
        'value' => $c->getValue(),
        'weight' => $c->getWeight(),
      ],
      $color_entities,
    ));
  })($this->getColors()),
The list cache tag is added after building the representation:
// color_list is invalidated by Drupal whenever any Color entity is
// created or deleted — this ensures the BrandKit response cache is
// busted when new colors appear or disappear.
$representation->addCacheTags(
    \Drupal::entityTypeManager()->getDefinition(Color::ENTITY_TYPE_ID)->getListCacheTags()
);
Why this matters: the per-entity cache tags added for existing colors only cover updates to those colors. The list tag covers the create/delete case. Without it, creating a new Color leaves the cached BrandKit API response stale.
Multi-kit note: When per-BrandKit color palettes are added (colors gain a brand_kit field and getColors() gains a per-kit condition), replace the global color_list tag with a more-specific list tag (e.g. color_list:brand_kit:<id>) so only the affected kit's response is invalidated.
Validation
Constraints for Color live in config/schema/canvas.schema.yml under canvas.color.*, not in the PHP entity class. This is the correct location for config entity validation — constraints in the #[ConfigEntityType] attribute's constraints array apply at the entity level, not field level.
Current constraints on canvas.color.*:
Field
name
cssVariable
value.colorSpace
value.alpha
value.hex
UniqueColorCssVariableConstraint and its validator are in src/Plugin/Validation/Constraint/. The constraint's id in the #[Constraint] attribute must match the key used in canvas.schema.yml.
Frontend Wiring
Services (ui/src/services/brandKit.ts)
Three RTK Query mutations added: createColor, updateColor, deleteColor. All invalidate:
[{ type: 'BrandKits', id: 'LIST' }, { type: 'BrandKits', id: 'global' }]
Both tags are required: 'LIST' covers useGetBrandKitsQuery; 'global' covers useGetBrandKitQuery(BRAND_KIT_ID) which is what useBrandKitColors reads from.
TypeScript Types
The BrandKitColor interface now uses W3C Design Token format:
interface BrandKitColorValue {
  colorSpace: 'srgb' | 'hsl';
  components: [number, number, number];
  alpha: number | null;
  hex: string | null;
}

interface BrandKitColor {
  id: string;
  name: string;
  cssVariable: string;
  value: BrandKitColorValue;
  weight: number;
}
Color data flow in UI
useGetBrandKitQuery('global')  →  BrandKit.colors: BrandKitColor[]
    ↓
useBrandKitColors()  →  reads canonicalBrandKit?.colors or autoSaveBrandKit?.data.colors
    ↓
BrandKitColorsSection  →  renders ColorRow per color
Colors are not fetched from /canvas/api/v0/config/color in the UI. They come embedded in the BrandKit response. The BrandKit normalizer loads and embeds full Color entity data (after the fix above).
Color Picker
The color picker (ui/src/components/ColorPicker.tsx) now emits BrandKitColorValue objects instead of (hex, opacity) tuples. It supports:
- Color space detection: When editing a color saved as HSL, the picker opens in HSLA mode; when saved as sRGB, it opens in RGBA mode
- Round-trip fidelity: HSL values are preserved exactly without conversion to sRGB
- Input modes: RGBA, HSLA, and HEX modes, with mode switching updating the stored colorSpace
- Eyedropper: Always produces sRGB colors
Components modified
- ui/src/features/brandKit/components/ColorFormPopover.tsx — add/edit popover; emits BrandKitColorValue to API
- ui/src/features/brandKit/components/ColorRow.tsx — per-row component with swatch display from value
- ui/src/features/brandKit/components/DeleteColorPopover.tsx — delete confirmation popover
- ui/src/features/brandKit/components/FindColorInstancesPopover.tsx — find instances placeholder popover
- ui/src/features/brandKit/colorCss.ts — generates CSS from BrandKitColorValue (supports sRGB and HSL)
RGBA/HSLA/HEX Inputs
The RGBA/HSLA/HEX inputs in the color picker (ui/src/components/ColorInputs.tsx) use native <input type="number"> or <input type="text"> elements rather than Radix UI TextField.Root. This avoids Radix UI's suppression of native browser spinners and its conflicting focus ring styles.
The inputs are styled via .rgbaNativeInput in ui/src/components/ColorPicker.module.css:
- Spinners are hidden (-webkit-appearance: none, appearance: textfield) to prevent overlap with 3-digit RGB values (0–255) in the narrow input width
- Focus ring uses outline: 2px solid var(--blue-9) with outline-offset: -2px so the ring renders at the actual border edge rather than floating above it
- Text is centered; border and background match the surrounding Radix UI aesthetic
Popover-based UI
The Brand Kit color administration UI uses @radix-ui/react-popover primitives for add/edit/delete/find operations. This was changed from Dialogs to Popovers to provide a more contextual experience anchored to trigger elements (the "New" button for add, the dots menu for per-row operations).
Positioning:
- Add color: anchored to the "New" button, side="bottom" align="end" sideOffset={4}
- Edit/Delete/Find: anchored to the dots button, side="bottom" align="end" sideOffset={4}
The popovers use zero-padding on the container with per-section padding, matching the approach in CanvasColorPickerField (see CanvasColorPickerField.module.css).
Remaining Work
1. Color picker UI — The popover now includes a full color picker widget with saturation box, hue/alpha sliders, and RGBA/HSLA/HEX inputs.
2. Usage data — A "Find instances" flow (FindColorInstancesPopover.tsx exists but is unimplemented). Needs a backend endpoint or query to find component instances that reference a given Color by CSS variable.
3. Prop type — A new prop shape/type (in the JSON Schema / SDC sense) that allows a component prop to accept a Color entity, resolved to its CSS variable value at render time.
4. Field widget — A Drupal field widget for the color prop type that renders as a color picker in the component instance form, listing available Color entities from the BrandKit.
Key Files
File
src/Entity/Color.php
src/Entity/BrandKit.php
src/Plugin/Validation/Constraint/UniqueColorCssVariableConstraint.php
src/Plugin/Validation/Constraint/UniqueColorCssVariableConstraintValidator.php
config/schema/canvas.schema.yml
canvas.routing.yml
openapi.yml
ui/src/services/brandKit.ts
ui/src/features/brandKit/hooks/useBrandKitColors.ts
ui/src/types/CodeComponent.ts
ui/src/components/ColorPicker.tsx
ui/src/features/brandKit/components/ColorFormPopover.tsx
ui/src/features/brandKit/components/ColorRow.tsx
ui/src/features/brandKit/components/DeleteColorPopover.tsx
ui/src/features/brandKit/components/FindColorInstancesPopover.tsx
ui/src/features/brandKit/colorCss.ts
ui/src/features/brandKit/BrandKitColorsSection.tsx
ui/src/features/brandKit/constants.ts
W3C Design Token Format
This implementation uses the W3C Design Tokens Format Module for color storage:
- Spec: https://www.designtokens.org/TR/2025.10/format/
- Color Module: https://www.designtokens.org/TR/2025.10/color/
The value object mirrors the W3C $value structure:
- colorSpace: String identifier (e.g., srgb, hsl)
- components: Array of numbers representing the color in the given space
- alpha: Optional number 0-1 for opacity; omitted/null = fully opaque
- hex: Optional 6-digit hex string as a fallback
This format is forward-compatible with additional color spaces (Display P3, OKLCH, etc.) and can be exported/imported by design tools that support the W3C standard.
