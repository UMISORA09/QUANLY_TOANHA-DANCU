export type PascalLevelMode = 'stacked' | 'exploded' | 'solo';

export interface PascalMeasurement {
  id: string;
  start: [number, number, number];
  end: [number, number, number];
  distanceMeters: number;
}

export interface PascalBimProperty {
  label: string;
  value: string | number;
  unit?: string;
  icon?: string;
  status?: 'active' | 'warning' | 'info' | 'success';
}

export interface PascalInspectorData {
  id: string;
  code: string;
  name: string;
  category: 'tower' | 'level' | 'podium' | 'amenity' | 'basement' | 'zone';
  elevation?: string;
  areaM2?: number;
  functions?: string[];
  properties: PascalBimProperty[];
  description?: string;
}

export interface PascalAgentCommand {
  command: string;
  syntax: string;
  description: string;
  example: string;
}
