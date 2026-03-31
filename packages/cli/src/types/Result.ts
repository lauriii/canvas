export interface Result {
  itemName: string;
  itemType?: string;
  success: boolean;
  details?: { heading?: string; content: string }[];
  warnings?: string[];
}
