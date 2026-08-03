export interface ActiveWorkspaceSetting {
  id: string;
  label: string;
  isDefault: boolean;
}

export interface LockedInWorkspaceSetting {
  id: string;
  label: string;
  canSwitch: boolean;
}

// Shape of `drupalSettings.canvas.workspaces`.
export interface WorkspacesSettings {
  activeWorkspace: ActiveWorkspaceSetting | null;
  lockedInWorkspace: LockedInWorkspaceSetting | null;
}
