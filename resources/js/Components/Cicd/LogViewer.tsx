import React, { useState, useEffect, useRef } from 'react';
import { Copy, Check, Download, Terminal, ArrowDown, Pause, Play } from 'lucide-react';

interface LogViewerProps {
  logs: string;
  isLoading?: boolean;
  title?: string;
  isStreaming?: boolean;
}

export const LogViewer: React.FC<LogViewerProps> = ({
  logs,
  isLoading = false,
  title = 'Console Output',
  isStreaming = false,
}) => {
  const [copied, setCopied] = useState(false);
  const [autoScroll, setAutoScroll] = useState(true);
  const logContainerRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (autoScroll && logContainerRef.current) {
      logContainerRef.current.scrollTop = logContainerRef.current.scrollHeight;
    }
  }, [logs, autoScroll]);

  const handleCopy = () => {
    navigator.clipboard.writeText(logs);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  const handleDownload = () => {
    const blob = new Blob([logs], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `cicd-log-${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')}.txt`;
    link.click();
    URL.revokeObjectURL(url);
  };

  const lines = logs.split('\n');

  return (
    <div className="rounded-2xl bg-neutral-950 border border-neutral-800 shadow-2xl overflow-hidden flex flex-col font-mono text-xs">
      {/* Terminal Titlebar */}
      <div className="flex items-center justify-between px-4 py-3 bg-neutral-900/90 border-b border-neutral-800 text-neutral-300 select-none">
        <div className="flex items-center gap-2">
          <div className="flex items-center gap-1.5 mr-2">
            <span className="w-2.5 h-2.5 rounded-full bg-rose-500/80 inline-block" />
            <span className="w-2.5 h-2.5 rounded-full bg-amber-500/80 inline-block" />
            <span className="w-2.5 h-2.5 rounded-full bg-emerald-500/80 inline-block" />
          </div>
          <Terminal className="w-3.5 h-3.5 text-neutral-400" />
          <span className="font-semibold text-xs tracking-tight text-neutral-200">{title}</span>
          {isStreaming && (
            <span className="inline-flex items-center gap-1 text-[10px] font-bold text-sky-400 bg-sky-950/60 px-2 py-0.5 rounded border border-sky-800 animate-pulse">
              LIVE
            </span>
          )}
        </div>

        <div className="flex items-center gap-1.5">
          {/* Auto Scroll Toggle */}
          <button
            type="button"
            onClick={() => setAutoScroll(!autoScroll)}
            className={`flex items-center gap-1 px-2 py-1 rounded text-[10px] font-medium transition-colors cursor-pointer ${
              autoScroll
                ? 'bg-neutral-800 text-neutral-200 hover:bg-neutral-700'
                : 'bg-neutral-900 text-neutral-500 hover:text-neutral-300'
            }`}
            title={autoScroll ? 'Tạm dừng tự động cuộn' : 'Bật tự động cuộn'}
          >
            {autoScroll ? <Pause className="w-3 h-3" /> : <Play className="w-3 h-3" />}
            <span className="hidden sm:inline">Auto-scroll</span>
          </button>

          {/* Copy Button */}
          <button
            type="button"
            onClick={handleCopy}
            className="flex items-center gap-1 px-2.5 py-1 rounded bg-neutral-800 hover:bg-neutral-700 text-neutral-200 text-[10px] font-medium transition-colors cursor-pointer"
            title="Sao chép toàn bộ logs"
          >
            {copied ? <Check className="w-3 h-3 text-emerald-400" /> : <Copy className="w-3 h-3" />}
            <span>{copied ? 'Đã chép' : 'Copy'}</span>
          </button>

          {/* Download Button */}
          <button
            type="button"
            onClick={handleDownload}
            className="flex items-center gap-1 px-2.5 py-1 rounded bg-neutral-800 hover:bg-neutral-700 text-neutral-200 text-[10px] font-medium transition-colors cursor-pointer"
            title="Tải log file về máy"
          >
            <Download className="w-3 h-3" />
            <span className="hidden sm:inline">Tải về</span>
          </button>
        </div>
      </div>

      {/* Terminal Body */}
      <div
        ref={logContainerRef}
        className="p-4 overflow-y-auto max-h-[500px] min-h-[260px] text-neutral-300 space-y-1 scrollbar-thin select-text"
      >
        {isLoading ? (
          <div className="text-neutral-500 py-8 text-center animate-pulse">
            Đang tải dữ liệu log từ máy chủ...
          </div>
        ) : lines.length === 0 || (lines.length === 1 && lines[0] === '') ? (
          <div className="text-neutral-500 py-8 text-center">
            Chưa có dòng log nào được ghi lại.
          </div>
        ) : (
          lines.map((line, idx) => {
            const isError = line.includes('FAIL') || line.includes('ERR') || line.includes('LỖI');
            const isSuccess = line.includes('PASS') || line.includes('OK') || line.includes('SUCCESS') || line.includes('THÀNH CÔNG');
            const isStep = line.includes('[STEP') || line.includes('===');
            const isWarning = line.includes('WARN') || line.includes('CẢNH BÁO');

            return (
              <div
                key={idx}
                className={`flex items-start gap-3 leading-relaxed hover:bg-neutral-900/60 rounded px-1.5 transition-colors ${
                  isError
                    ? 'text-rose-400 font-semibold'
                    : isSuccess
                    ? 'text-emerald-400'
                    : isStep
                    ? 'text-sky-400 font-bold'
                    : isWarning
                    ? 'text-amber-400'
                    : 'text-neutral-300'
                }`}
              >
                <span className="text-neutral-600 text-[10px] w-8 text-right select-none shrink-0 font-mono">
                  {idx + 1}
                </span>
                <span className="break-all whitespace-pre-wrap flex-1">{line}</span>
              </div>
            );
          })
        )}
      </div>
    </div>
  );
};
