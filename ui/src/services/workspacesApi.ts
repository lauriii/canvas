// Need to use the React-specific entry point to import createApi
import { createApi } from '@reduxjs/toolkit/query/react';

import { baseQuery } from '@/services/baseQuery';

// A state ID of the workspace's review workflow; sites can define their own
// workflows, so the set of values is open-ended.
export type WorkspaceStatus = string;

// A transition ID of the workspace's review workflow.
export type WorkspaceStatusTransition = string;

export interface WorkspaceTransition {
  id: WorkspaceStatusTransition;
  label: string;
}

export interface WorkspaceAccess {
  delete: boolean;
  publish: boolean;
}

export interface Workspace {
  id: string;
  label: string;
  isDefault: boolean;
  isActive: boolean;
  status: WorkspaceStatus;
  statusLabel: string;
  statusIsApproved: boolean;
  statusIsInitial: boolean;
  requireReview: boolean;
  availableTransitions: WorkspaceTransition[];
  scheduledPublishAt: number | null;
  scheduledPublishError: string | null;
  pendingChangesCount: number;
  access: WorkspaceAccess;
}

export interface WorkspacesListResponse {
  data: Workspace[];
  activeWorkspaceId: string | null;
}

export interface CreateWorkspaceArg {
  label: string;
  requireReview?: boolean;
}

export const workspacesApi = createApi({
  reducerPath: 'workspacesApi',
  baseQuery,
  tagTypes: ['Workspaces'],
  endpoints: (builder) => ({
    getWorkspaces: builder.query<WorkspacesListResponse, void>({
      query: () => '/canvas/api/v0/workspaces',
      providesTags: ['Workspaces'],
    }),
    createWorkspace: builder.mutation<Workspace, CreateWorkspaceArg>({
      query: (body) => ({
        url: '/canvas/api/v0/workspaces',
        method: 'POST',
        body,
      }),
      invalidatesTags: ['Workspaces'],
    }),
    activateWorkspace: builder.mutation<Workspace, string>({
      query: (id) => ({
        url: `/canvas/api/v0/workspaces/${id}/activate`,
        method: 'POST',
        body: {},
      }),
      invalidatesTags: ['Workspaces'],
    }),
    deleteWorkspace: builder.mutation<void, string>({
      query: (id) => ({
        url: `/canvas/api/v0/workspaces/${id}`,
        method: 'DELETE',
      }),
      invalidatesTags: ['Workspaces'],
    }),
    transitionWorkspaceStatus: builder.mutation<
      Workspace,
      { id: string; transition: WorkspaceStatusTransition }
    >({
      query: ({ id, transition }) => ({
        url: `/canvas/api/v0/workspaces/${id}/status`,
        method: 'POST',
        body: { transition },
      }),
      invalidatesTags: ['Workspaces'],
    }),
    scheduleWorkspacePublish: builder.mutation<
      Workspace,
      { id: string; publishAt: number }
    >({
      query: ({ id, publishAt }) => ({
        url: `/canvas/api/v0/workspaces/${id}/schedule`,
        method: 'POST',
        body: { publishAt },
      }),
      invalidatesTags: ['Workspaces'],
    }),
    unscheduleWorkspacePublish: builder.mutation<Workspace, string>({
      query: (id) => ({
        url: `/canvas/api/v0/workspaces/${id}/schedule`,
        method: 'DELETE',
      }),
      invalidatesTags: ['Workspaces'],
    }),
  }),
});

export const {
  useGetWorkspacesQuery,
  useCreateWorkspaceMutation,
  useActivateWorkspaceMutation,
  useDeleteWorkspaceMutation,
  useTransitionWorkspaceStatusMutation,
  useScheduleWorkspacePublishMutation,
  useUnscheduleWorkspacePublishMutation,
} = workspacesApi;
