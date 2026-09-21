import React, { useState } from 'react';
import { Terminal, Send, HelpCircle, Check, Sparkles, X, ChevronRight } from 'lucide-react';
import { PascalAgentCommand } from './types';

interface PascalAgentConsoleProps {
  onExecuteCommand: (cmd: string) => { success: boolean; message: string };
  isOpen: boolean;
  onClose: () => void;
}

const PRESET_COMMANDS: PascalAgentCommand[] = [
  {
    command: '/explode',
    syntax: '/explode [0.5-2.8]',
    description: 'Bóc tách bung các tầng theo trục đứng Y',
    example: '/explode 1.8',
  },
  {
    command: '/solo',
    syntax: '/solo [Tầng]',
    description: 'Cách ly hiển thị một tầng duy nhất',
    example: '/solo Tầng 3',
  },
  {
    command: '/stacked',
    syntax: '/stacked',
    description: 'Trở về trạng thái tầng nguyên khối',
    example: '/stacked',
  },
  {
    command: '/shading',
    syntax: '/shading [realistic | clay | wireframe | depth]',
    description: 'Chuyển đổi chế độ vật liệu hiển thị',
    example: '/shading clay',
  },
  {
    command: '/measure',
    syntax: '/measure',
    description: 'Bật/tắt công cụ thước đo khoảng cách kiến trúc 3D',
    example: '/measure',
  },
  {
    command: '/focus',
    syntax: '/focus [hotel | office | podium | pool | basement]',
    description: 'Điều hướng góc nhìn camera tới phân khu',
    example: '/focus pool',
  },
  {
    command: '/sun',
    syntax: '/sun [elevation 5-85]',
    description: 'Thiết lập góc chiếu sáng mặt trời',
    example: '/sun 45',
  },
  {
    command: '/grid',
    syntax: '/grid [on | off]',
    description: 'Bật hoặc tắt lưới tọa độ không gian',
    example: '/grid on',
  },
];

export const PascalAgentConsole: React.FC<PascalAgentConsoleProps> = ({
  onExecuteCommand,
  isOpen,
  onClose,
}) => {
  const [inputVal, setInputVal] = useState('');
  const [logs, setLogs] = useState<{ text: string; type: 'info' | 'success' | 'error' | 'user' }[]>([
    {
      text: 'Pascal Agent Engine ready. Gõ lệnh hoặc chọn gợi ý bên dưới (MCP Directives).',
      type: 'info',
    },
  ]);

  if (!isOpen) return null;

  const handleSubmit = (e?: React.FormEvent) => {
    if (e) e.preventDefault();
    const trimmed = inputVal.trim();
    if (!trimmed) return;

    // Log user input
    setLogs((prev) => [...prev, { text: `> ${trimmed}`, type: 'user' }]);

    const res = onExecuteCommand(trimmed);
    setLogs((prev) => [
      ...prev,
      { text: res.message, type: res.success ? 'success' : 'error' },
    ]);

    setInputVal('');
  };

  const handleApplyPreset = (example: string) => {
    setInputVal(example);
  };

  return (
    <div className="bg-slate-950/95 backdrop-blur-xl border border-sky-500/30 rounded-3xl p-4 text-white shadow-2xl w-full max-w-xl flex flex-col gap-3 font-mono text-xs animate-in fade-in slide-in-from-bottom-4 duration-200">
      {/* Header */}
      <div className="flex items-center justify-between border-b border-white/10 pb-2.5">
        <div className="flex items-center gap-2">
          <div className="w-6 h-6 rounded-lg bg-gradient-to-tr from-sky-500 to-indigo-600 flex items-center justify-center text-white shadow-sm">
            <Terminal className="w-3.5 h-3.5" />
          </div>
          <span className="font-bold text-slate-100 text-xs">
            Pascal Agent Console <span className="text-[10px] text-sky-400 font-normal">(MCP & CLI Interface)</span>
          </span>
        </div>

        <button
          type="button"
          onClick={onClose}
          className="p-1 rounded-lg text-slate-400 hover:text-white hover:bg-white/10 transition-colors cursor-pointer"
        >
          <X className="w-4 h-4" />
        </button>
      </div>

      {/* Terminal Output Logs */}
      <div className="h-36 overflow-y-auto custom-scrollbar bg-slate-900/80 rounded-2xl p-3 flex flex-col gap-1.5 border border-white/5 font-mono text-[11px]">
        {logs.map((log, idx) => (
          <div
            key={idx}
            className={`${
              log.type === 'user'
                ? 'text-sky-300 font-semibold'
                : log.type === 'success'
                ? 'text-emerald-400'
                : log.type === 'error'
                ? 'text-rose-400'
                : 'text-slate-400'
            }`}
          >
            {log.text}
          </div>
        ))}
      </div>

      {/* Quick Command Chips */}
      <div className="flex items-center gap-1.5 overflow-x-auto custom-scrollbar pb-1">
        <span className="text-[10px] text-slate-500 whitespace-nowrap">Gợi ý:</span>
        {PRESET_COMMANDS.slice(0, 5).map((cmd) => (
          <button
            key={cmd.command}
            type="button"
            onClick={() => handleApplyPreset(cmd.example)}
            className="px-2 py-1 rounded-lg bg-white/5 hover:bg-sky-500/20 hover:text-sky-300 border border-white/10 text-[10px] text-slate-300 whitespace-nowrap transition-colors cursor-pointer"
            title={cmd.description}
          >
            {cmd.command}
          </button>
        ))}
      </div>

      {/* Command Input Form */}
      <form onSubmit={handleSubmit} className="flex items-center gap-2">
        <div className="relative flex-1">
          <input
            type="text"
            value={inputVal}
            onChange={(e) => setInputVal(e.target.value)}
            placeholder="Nhập lệnh agent... ví dụ: /explode 1.8 hoặc /shading clay"
            className="w-full bg-slate-900 border border-white/15 rounded-xl px-3.5 py-2 text-xs text-white placeholder-slate-500 focus:outline-hidden focus:border-sky-500 focus:ring-1 focus:ring-sky-500 font-mono"
          />
        </div>
        <button
          type="submit"
          className="px-3.5 py-2 rounded-xl bg-sky-600 hover:bg-sky-500 text-white font-semibold text-xs transition-colors flex items-center gap-1 cursor-pointer"
        >
          <Send className="w-3.5 h-3.5" />
          <span>Chạy</span>
        </button>
      </form>
    </div>
  );
};
