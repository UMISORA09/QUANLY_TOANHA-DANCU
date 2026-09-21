export type A3DShadingMode = 'realistic' | 'clay' | 'wireframe' | 'depth';

export type A3DAspectRatio = 'free' | '16:9' | '4:3' | '1:1' | '9:16';

export interface A3DOutlinerItem {
  id: string;
  name: string;
  category: 'tower' | 'podium' | 'amenity' | 'basement' | 'landscape' | 'lighting';
  iconName: string;
  visible: boolean;
  description?: string;
  cameraTarget?: {
    theta: number;
    phi: number;
    radius: number;
    lookAt: [number, number, number];
  };
}

export interface A3DEnvironmentSettings {
  sunElevation: number; // 5 to 85 deg
  sunAzimuth: number; // -180 to 180 deg
  ambientIntensity: number; // 0.2 to 2.5
  sunIntensity: number; // 0.5 to 4.0
  shadowQuality: 'soft' | 'sharp' | 'off';
  showWorldGrid: boolean;
  fogDensity: number; // 0 to 0.02
}

export interface A3DAiPromptPreset {
  id: string;
  title: string;
  tag: string;
  promptEn: string;
  promptVi: string;
  negativePrompt: string;
  recommendedModel: string;
  comfyNodes: string;
}
