/**
 * Processes an array of items concurrently with a maximum concurrency limit.
 * Uses Promise.allSettled to ensure individual failures don't abort the entire batch.
 *
 * @param items - Array of items to process
 * @param processor - Function that processes each item and returns a Promise
 * @param concurrency - Maximum number of concurrent operations (default: 10)
 * @returns Promise resolving to array of results matching the input order
 */
export async function processInPool<T, R>(
  items: T[],
  processor: (item: T, index: number) => Promise<R>,
  concurrency: number = 10,
): Promise<
  Array<{ success: boolean; result?: R; error?: Error; index: number }>
> {
  const results: Array<{
    success: boolean;
    result?: R;
    error?: Error;
    index: number;
  }> = [];

  for (let i = 0; i < items.length; i += concurrency) {
    const batch = items.slice(i, i + concurrency);
    const batchStartIndex = i;

    const batchPromises = batch.map(async (item, batchIndex) => {
      const globalIndex = batchStartIndex + batchIndex;
      try {
        const result = await processor(item, globalIndex);
        return { success: true, result, index: globalIndex };
      } catch (error) {
        return {
          success: false,
          error: error instanceof Error ? error : new Error(String(error)),
          index: globalIndex,
        };
      }
    });

    const batchResults = await Promise.allSettled(batchPromises);

    for (const settledResult of batchResults) {
      if (settledResult.status === 'fulfilled') {
        results.push(settledResult.value);
      } else {
        results.push({
          success: false,
          error: new Error(`Batch processing failed: ${settledResult.reason}`),
          index: results.length,
        });
      }
    }
  }

  return results.sort((a, b) => a.index - b.index);
}

/**
 * Helper function to create a progress callback that updates a spinner.
 *
 * @param spinner - Spinner object with message method
 * @param operation - Description of the operation being performed
 * @param total - Total number of items being processed
 * @returns Progress callback function
 */
export function createProgressCallback(
  spinner: { message: (msg?: string) => void },
  operation: string,
  total: number,
) {
  let completed = 0;

  return () => {
    completed++;
    const percentage = Math.round((completed / total) * 100);
    spinner.message(`${operation} (${completed}/${total} - ${percentage}%)`);
  };
}
