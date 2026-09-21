import React, { useMemo } from 'react';
import { A3DAspectRatio } from './types';

interface A3DRatioOverlayProps {
  ratio: A3DAspectRatio;
  containerWidth: number;
  containerHeight: number;
}

const RATIO_VALUES: Record<A3DAspectRatio, number | null> = {
  free: null,
  '16:9': 16 / 9,
  '4:3': 4 / 3,
  '1:1': 1,
  '9:16': 9 / 16,
};

export const A3DRatioOverlay: React.FC<A3DRatioOverlayProps> = ({
  ratio,
  containerWidth,
  containerHeight,
}) => {
  const dimensions = useMemo(() => {
    const targetRatio = RATIO_VALUES[ratio];
    if (!targetRatio || containerWidth <= 0 || containerHeight <= 0) {
      return null;
    }

    const currentRatio = containerWidth / containerHeight;
    let frameW = containerWidth;
    let frameH = containerHeight;
    let offsetX = 0;
    let offsetY = 0;

    // Add 4% padding around the frame so it feels framed inside viewer
    const padding = Math.min(containerWidth, containerHeight) * 0.04;
    const availW = containerWidth - padding * 2;
    const availH = containerHeight - padding * 2;

    if (availW / availH > targetRatio) {
      frameH = availH;
      frameW = frameH * targetRatio;
      offsetX = (containerWidth - frameW) / 2;
      offsetY = padding;
    } else {
      frameW = availW;
      frameH = frameW / targetRatio;
      offsetX = padding;
      offsetY = (containerHeight - frameH) / 2;
    }

    return {
      frameW,
      frameH,
      offsetX,
      offsetY,
      topH: offsetY,
      bottomH: containerHeight - (offsetY + frameH),
      leftW: offsetX,
      rightW: containerWidth - (offsetX + frameW),
    };
  }, [ratio, containerWidth, containerHeight]);

  if (!dimensions || ratio === 'free') {
    return null;
  }

  const { frameW, frameH, offsetX, offsetY, topH, bottomH, leftW, rightW } = dimensions;

  return (
    <div className="absolute inset-0 pointer-events-none z-20 overflow-hidden select-none">
      {/* Top bar */}
      <div
        className="absolute top-0 left-0 right-0 bg-slate-950/75 backdrop-blur-[2px] transition-all duration-200"
        style={{ height: `${Math.max(0, topH)}px` }}
      />
      {/* Bottom bar */}
      <div
        className="absolute bottom-0 left-0 right-0 bg-slate-950/75 backdrop-blur-[2px] transition-all duration-200"
        style={{ height: `${Math.max(0, bottomH)}px` }}
      />
      {/* Left bar */}
      <div
        className="absolute bg-slate-950/75 backdrop-blur-[2px] transition-all duration-200"
        style={{
          top: `${topH}px`,
          bottom: `${bottomH}px`,
          left: 0,
          width: `${Math.max(0, leftW)}px`,
        }}
      />
      {/* Right bar */}
      <div
        className="absolute bg-slate-950/75 backdrop-blur-[2px] transition-all duration-200"
        style={{
          top: `${topH}px`,
          bottom: `${bottomH}px`,
          right: 0,
          width: `${Math.max(0, rightW)}px`,
        }}
      />

      {/* Frame boundary box */}
      <div
        className="absolute border border-amber-400/50 shadow-[0_0_15px_rgba(245,158,11,0.15)] transition-all duration-200"
        style={{
          left: `${offsetX}px`,
          top: `${offsetY}px`,
          width: `${frameW}px`,
          height: `${frameH}px`,
        }}
      >
        {/* Corner Guides */}
        <div className="absolute top-0 left-0 w-3 h-3 border-t-2 border-l-2 border-amber-400" />
        <div className="absolute top-0 right-0 w-3 h-3 border-t-2 border-r-2 border-amber-400" />
        <div className="absolute bottom-0 left-0 w-3 h-3 border-b-2 border-l-2 border-amber-400" />
        <div className="absolute bottom-0 right-0 w-3 h-3 border-b-2 border-r-2 border-amber-400" />

        {/* Center Crosshair */}
        <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-4 h-4 pointer-events-none opacity-40">
          <div className="absolute top-1/2 left-0 right-0 h-px bg-amber-400" />
          <div className="absolute left-1/2 top-0 bottom-0 w-px bg-amber-400" />
        </div>

        {/* Ratio Tag */}
        <div className="absolute top-2 left-2 px-2 py-0.5 rounded bg-slate-900/80 backdrop-blur-md border border-amber-400/40 text-[10px] font-mono font-bold text-amber-300">
          {ratio.toUpperCase()} FRAMING
        </div>
      </div>
    </div>
  );
};
