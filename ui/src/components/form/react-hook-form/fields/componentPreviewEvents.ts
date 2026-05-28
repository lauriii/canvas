export const COMPONENT_PREVIEW_UPDATE_EVENT = 'canvas:component_preview_update';

// A custom event class for communicating model updates to power client-side
// preview updates.
export class ComponentPreviewUpdateEvent extends Event {
  componentUuid: string;
  propName: string;
  propValue: any;
  private previewBackgroundUpdate: boolean;
  constructor(componentUuid: string, propName: string, propValue: any) {
    super(COMPONENT_PREVIEW_UPDATE_EVENT);
    this.componentUuid = componentUuid;
    this.propName = propName;
    this.propValue = propValue;
    this.previewBackgroundUpdate = false;
  }
  setPreviewBackgroundUpdate(update: boolean) {
    this.previewBackgroundUpdate = update;
  }
  getPreviewBackgroundUpdate(): boolean {
    return this.previewBackgroundUpdate;
  }
}
