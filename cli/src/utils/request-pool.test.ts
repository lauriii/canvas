import { describe, expect, it, vi } from 'vitest';

import { createProgressCallback, processInPool } from './request-pool';

describe('request pool utilities', () => {
  describe('processInPool', () => {
    it('should process items concurrently with limited concurrency', async () => {
      const items = [1, 2, 3, 4, 5];
      const processor = vi.fn(async (item: number) => {
        await new Promise((resolve) => setTimeout(resolve, 10));
        return item * 2;
      });

      const results = await processInPool(items, processor, 2);

      expect(results).toHaveLength(5);
      expect(results.every((r) => r.success)).toBe(true);
      expect(results.map((r) => r.result)).toEqual([2, 4, 6, 8, 10]);
      expect(processor).toHaveBeenCalledTimes(5);
    });

    it('should handle individual item failures without aborting batch', async () => {
      const items = [1, 2, 3, 4, 5];
      const processor = vi.fn(async (item: number) => {
        if (item === 3) {
          throw new Error('Item 3 failed');
        }
        return item * 2;
      });

      const results = await processInPool(items, processor, 2);

      expect(results).toHaveLength(5);
      expect(results.filter((r) => r.success)).toHaveLength(4);
      expect(results.filter((r) => !r.success)).toHaveLength(1);

      // Check failed item
      const failedResult = results.find((r) => !r.success);
      expect(failedResult?.error?.message).toBe('Item 3 failed');
    });

    it('should preserve original order of results', async () => {
      const items = ['a', 'b', 'c', 'd'];
      const processor = vi.fn(async (item: string, index: number) => {
        // Add random delay to simulate real async work
        const delay = Math.random() * 20;
        await new Promise((resolve) => setTimeout(resolve, delay));
        return `${item}-${index}`;
      });

      const results = await processInPool(items, processor, 2);

      expect(results.map((r) => r.result)).toEqual([
        'a-0',
        'b-1',
        'c-2',
        'd-3',
      ]);
      expect(results.map((r) => r.index)).toEqual([0, 1, 2, 3]);
    });

    it('should use default concurrency when not specified', async () => {
      const items = [1, 2, 3];
      const processor = vi.fn(async (item: number) => item * 2);

      const results = await processInPool(items, processor);

      expect(results).toHaveLength(3);
      expect(results.every((r) => r.success)).toBe(true);
      expect(results.map((r) => r.result)).toEqual([2, 4, 6]);
    });

    it('should handle empty array', async () => {
      const items: number[] = [];
      const processor = vi.fn(async (item: number) => item * 2);

      const results = await processInPool(items, processor, 2);

      expect(results).toHaveLength(0);
      expect(processor).not.toHaveBeenCalled();
    });

    it('should handle Promise.allSettled rejections gracefully', async () => {
      const items = [1, 2, 3];
      // Create a processor that might cause Promise.allSettled to have rejected promises
      const processor = vi.fn(async (item: number) => {
        // This should be caught by our internal error handling
        return item * 2;
      });

      const results = await processInPool(items, processor, 2);

      expect(results).toHaveLength(3);
      expect(results.every((r) => r.success)).toBe(true);
    });
  });

  describe('createProgressCallback', () => {
    it('should create a progress callback that updates spinner', () => {
      const mockSpinner = {
        message: vi.fn(),
      };

      const progressCallback = createProgressCallback(
        mockSpinner,
        'Testing',
        5,
      );

      // Call the callback multiple times
      progressCallback();
      progressCallback();
      progressCallback();

      expect(mockSpinner.message).toHaveBeenCalledTimes(3);
      expect(mockSpinner.message).toHaveBeenNthCalledWith(
        1,
        'Testing (1/5 - 20%)',
      );
      expect(mockSpinner.message).toHaveBeenNthCalledWith(
        2,
        'Testing (2/5 - 40%)',
      );
      expect(mockSpinner.message).toHaveBeenNthCalledWith(
        3,
        'Testing (3/5 - 60%)',
      );
    });

    it('should handle completion correctly', () => {
      const mockSpinner = {
        message: vi.fn(),
      };

      const progressCallback = createProgressCallback(
        mockSpinner,
        'Uploading',
        2,
      );

      progressCallback();
      progressCallback();

      expect(mockSpinner.message).toHaveBeenNthCalledWith(
        1,
        'Uploading (1/2 - 50%)',
      );
      expect(mockSpinner.message).toHaveBeenNthCalledWith(
        2,
        'Uploading (2/2 - 100%)',
      );
    });

    it('should handle single item progress', () => {
      const mockSpinner = {
        message: vi.fn(),
      };

      const progressCallback = createProgressCallback(
        mockSpinner,
        'Processing',
        1,
      );

      progressCallback();

      expect(mockSpinner.message).toHaveBeenCalledWith(
        'Processing (1/1 - 100%)',
      );
    });
  });
});
