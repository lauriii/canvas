import { describe, expect, it, vi } from 'vitest';

import { setLatestError } from '@/features/error-handling/queryErrorSlice';
import { rtkQueryErrorHandler } from '@/utils/rtkQuery-error';

// The shape RTK Query gives rejected endpoint thunks, per
// `isRejectedWithValue`.
const rejectedAction = (endpointName: string) => ({
  type: 'api/executeMutation/rejected',
  payload: { status: 409, data: { errors: ['Field already exists.'] } },
  error: { message: 'Rejected' },
  meta: {
    rejectedWithValue: true,
    requestStatus: 'rejected',
    requestId: 'test-request',
    arg: { endpointName },
  },
});

const runMiddleware = (action: unknown) => {
  const dispatch = vi.fn();
  const next = vi.fn((a) => a);
  rtkQueryErrorHandler({ dispatch, getState: vi.fn() })(next)(action);
  return { dispatch, next };
};

describe('rtkQueryErrorHandler', () => {
  it('stores rejections in the error slice', () => {
    const { dispatch, next } = runMiddleware(rejectedAction('getLayoutById'));
    expect(dispatch).toHaveBeenCalledWith(
      setLatestError(
        expect.objectContaining({
          status: '409',
          message: 'Field already exists.',
        }),
      ),
    );
    expect(next).toHaveBeenCalledOnce();
  });

  it('skips endpoints whose callers handle failures inline', () => {
    // The expose dialog shows its own message for a taken slot-field name;
    // the global error screen must not replace the editor under it.
    const { dispatch, next } = runMiddleware(rejectedAction('createSlotField'));
    expect(dispatch).not.toHaveBeenCalled();
    expect(next).toHaveBeenCalledOnce();
  });
});
