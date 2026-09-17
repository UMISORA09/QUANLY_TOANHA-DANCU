import React from 'react';

export interface AppSidebarTooltipProps {
  label: string;
  badge?: string | number | null;
  top: number;
}

export const AppSidebarTooltip: React.FC<AppSidebarTooltipProps> = ({ label, badge, top }) => {
  return (
    <div
      style={{
        top: `${top}px`,
        left: '86px',
      }}
      className="fixed -translate-y-1/2 z-[80] px-3 py-2 rounded-xl bg-neutral-950/95 text-white text-xs font-semibold shadow-2xl backdrop-blur-xl border border-white/20 pointer-events-none flex items-center gap-2 animate-in fade-in zoom-in-95 duration-150 ring-1 ring-black/40"
    >
      <span className="tracking-wide whitespace-nowrap">{label}</span>
      {badge && (
        <span className="text-[10px] px-1.5 py-0.5 rounded-md bg-sky-500/25 text-sky-300 font-bold border border-sky-400/40 shrink-0">
          {badge}
        </span>
      )}
      {/* Subtle pointer arrow pointing left toward sidebar */}
      <div className="absolute -left-1 top-1/2 -translate-y-1/2 w-2 h-2 bg-neutral-950 rotate-45 border-l border-b border-white/20" />
    </div>
  );
};
