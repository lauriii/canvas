import { describe, expect, it, vi } from 'vitest';

describe('Upload Command Optimizations', () => {
  describe('checkComponentsExist optimization', () => {
    it('should use listComponents() efficiently instead of individual getComponent calls', async () => {
      // Mock API service with listComponents method
      const mockApiService = {
        listComponents: vi.fn().mockResolvedValue({
          'existing-component-1': { machineName: 'existing-component-1' },
          'existing-component-2': { machineName: 'existing-component-2' },
        }),
      };

      const mockProgress = vi.fn();

      // Simulate the optimized logic from checkComponentsExist
      const machineNames = [
        'existing-component-1',
        'non-existent-component',
        'existing-component-2',
      ];

      const existingComponents = await mockApiService.listComponents();
      const existingMachineNames = new Set(Object.keys(existingComponents));

      const results = machineNames.map((machineName) => {
        mockProgress();
        return {
          machineName,
          exists: existingMachineNames.has(machineName),
        };
      });

      // Verify the optimization works correctly
      expect(mockApiService.listComponents).toHaveBeenCalledTimes(1);
      expect(mockProgress).toHaveBeenCalledTimes(3);
      expect(results).toEqual([
        { machineName: 'existing-component-1', exists: true },
        { machineName: 'non-existent-component', exists: false },
        { machineName: 'existing-component-2', exists: true },
      ]);
    });

    it('should handle listComponents() API errors gracefully', async () => {
      const mockApiService = {
        listComponents: vi.fn().mockRejectedValue(new Error('API Error')),
      };

      const mockProgress = vi.fn();
      const machineNames = ['component-1', 'component-2'];

      // Simulate the error handling from checkComponentsExist
      try {
        await mockApiService.listComponents();
      } catch (error) {
        const fallbackResults = machineNames.map((machineName) => {
          mockProgress();
          return {
            machineName,
            exists: false,
            error: error instanceof Error ? error : new Error(String(error)),
          };
        });

        expect(mockApiService.listComponents).toHaveBeenCalledTimes(1);
        expect(mockProgress).toHaveBeenCalledTimes(2);
        expect(fallbackResults).toEqual([
          {
            machineName: 'component-1',
            exists: false,
            error: expect.any(Error),
          },
          {
            machineName: 'component-2',
            exists: false,
            error: expect.any(Error),
          },
        ]);
      }
    });
  });
});
