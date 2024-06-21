import type {
  BaseQueryApi,
  BaseQueryFn,
  FetchArgs,
  FetchBaseQueryError} from "@reduxjs/toolkit/query/react";
import {
  fetchBaseQuery
} from "@reduxjs/toolkit/query/react";
import type { RootState } from "@/app/store";
import type { AppConfiguration } from "@/features/configuration/configurationSlice";

export const baseQuery: BaseQueryFn<
  string | FetchArgs,
  unknown,
  FetchBaseQueryError
> = async (args, api, extraOptions) => {
  const state = api.getState() as RootState;
  return rawBaseQuery(state.configuration)(args, api, extraOptions);
};

const rawBaseQuery = (appConfiguration: AppConfiguration) => {
  const { baseUrl } = appConfiguration;
  const defaultQuery = fetchBaseQuery({
    baseUrl,
  });
  return async (
    arg: string | FetchArgs,
    api: BaseQueryApi,
    extraOptions: object = {},
  ) => {
    const url = typeof arg == "string" ? arg : arg.url;
    // Here we can do dynamic parameter replacement based on app configuration.
    // const { entity_type, entity_id } = appConfiguration;
    const newUrl = url
      // .replace("{entity_type}", entity_type)
      // .replace("{entity_id}", entity_id);
    const newArg = typeof arg == "string" ? newUrl : { ...arg, url: newUrl };
    return defaultQuery(newArg, api, extraOptions);
  };
};
