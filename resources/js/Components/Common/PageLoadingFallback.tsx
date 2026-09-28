import React from 'react';

export const PageLoadingFallback: React.FC<{ message?: string }> = ({
  message = 'Đang tải hệ thống SMART CASSAVAS...',
}) => {
  return (
    <div className="min-h-screen w-full flex flex-col items-center justify-center bg-slate-900 text-white relative overflow-hidden select-none">
      {/* Background glow effects */}
      <div className="absolute top-1/3 left-1/2 -translate-x-1/2 -translate-y-1/2 w-96 h-96 bg-blue-500/10 rounded-full blur-3xl pointer-events-none animate-pulse" />
      <div className="absolute bottom-1/3 left-1/2 -translate-x-1/2 translate-y-1/2 w-80 h-80 bg-emerald-500/10 rounded-full blur-3xl pointer-events-none animate-pulse delay-700" />

      {/* Main container */}
      <div className="relative z-10 flex flex-col items-center max-w-sm px-6 text-center animate-fade-in">
        {/* Animated Brand Emblem */}
        <div className="relative w-16 h-16 mb-6 flex items-center justify-center">
          <div className="absolute inset-0 rounded-2xl bg-gradient-to-tr from-blue-600 via-indigo-600 to-emerald-500 opacity-80 blur-sm animate-pulse" />
          <div className="relative w-full h-full rounded-2xl bg-slate-800/90 border border-slate-700/60 shadow-xl flex items-center justify-center backdrop-blur-md">
            <svg
              className="w-8 h-8 text-blue-400 animate-spin"
              style={{ animationDuration: '2.5s' }}
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83" />
            </svg>
          </div>
        </div>

        {/* Brand Title */}
        <h2 className="text-xl font-bold tracking-tight text-slate-100 flex items-center gap-2">
          <span>SMART</span>
          <span className="text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-emerald-400">
            CASSAVAS
          </span>
        </h2>

        {/* Dynamic Loading Message */}
        <p className="mt-2 text-sm text-slate-400 font-medium">
          {message}
        </p>

        {/* Sleek Progress Indeterminate Bar */}
        <div className="w-48 h-1 bg-slate-800 rounded-full overflow-hidden mt-6 relative">
          <div
            className="h-full bg-gradient-to-r from-blue-500 via-indigo-400 to-emerald-400 rounded-full w-1/3 animate-indeterminate"
            style={{
              animation: 'indeterminate 1.5s infinite ease-in-out',
            }}
          />
        </div>
      </div>

      <style>{`
        @keyframes indeterminate {
          0% { transform: translateX(-100%); }
          50% { transform: translateX(150%); }
          100% { transform: translateX(350%); }
        }
      `}</style>
    </div>
  );
};

export default PageLoadingFallback;
