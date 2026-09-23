import React, { useState, useEffect } from 'react';
import {
  Building2,
  Clock,
  Bell,
  ChevronDown,
  LogOut,
  Bot,
  Sparkles,
  Cpu,
  Plug,
  Code2,
  FileText,
  Flag,
  Download,
  Terminal,
  Activity,
  CheckCircle2,
  AlertTriangle,
  Server,
  Layers,
  Search,
  ExternalLink,
  Shield,
  ArrowRight,
  Database,
  RefreshCw,
  Sliders,
  Check,
  X
} from 'lucide-react';

interface DevConsolePageProps {
  onLogout: () => void;
  onNavigateHome: () => void;
  onNavigateManager: () => void;
  userName?: string;
  userEmail?: string;
}

type DevTab = 'overview' | 'ai_triage' | 'api_iot' | 'audit_flags';

export const DevConsolePage: React.FC<DevConsolePageProps> = ({
  onLogout,
  onNavigateHome,
  onNavigateManager,
  userName = 'Dev Team',
  userEmail = 'dev@cassavas.vn',
}) => {
  const [activeTab, setActiveTab] = useState<DevTab>('overview');
  const [selectedBlock, setSelectedBlock] = useState<string>('Khu A - Tất cả tòa nhà');
  const [isExporting, setIsExporting] = useState<boolean>(false);
  const [exportSuccess, setExportSuccess] = useState<boolean>(false);

  // State demo cho Feature Flags
  const [featureFlags, setFeatureFlags] = useState([
    { key: 'ENABLE_AI_TICKET_TRIAGE', name: 'AI Phân loại sự cố tự động (Triage)', enabled: true, desc: 'Tự động gán độ ưu tiên và nhãn kỹ thuật viên cho Ticket' },
    { key: 'ENABLE_CONCURRENCY_LOCK', name: 'Khóa đồng thời đặt tiện ích (Concurrency Lock)', enabled: true, desc: 'Chống trùng lịch đặt sân tennis/hồ bơi khi 2 cư dân cùng đặt 1 slot' },
    { key: 'ENABLE_SMART_LOCKER_OTP', name: 'Tủ đồ thông minh cấp mã PIN/OTP tự động', enabled: false, desc: 'Sinh mã OTP 6 số gửi qua thông báo khi có bưu phẩm mới' },
    { key: 'ENABLE_VIETQR_DYNAMIC', name: 'Cổng thanh toán VietQR động', enabled: true, desc: 'Tạo mã QR có sẵn số tiền và mã hóa đơn kỳ hiện tại' },
    { key: 'ENABLE_ANPR_CAMERA_GATE', name: 'Camera AI nhận diện biển số (ANPR)', enabled: false, desc: 'Mô phỏng bốt barie nhận diện biển xe ra vào tự động' },
  ]);

  // State demo cho Webhooks
  const [webhookLogs, setWebhookLogs] = useState([
    { id: 'wh-01', provider: 'VNPAY_GATEWAY', event: 'payment.success', status: '200 OK', time: '15:20:10' },
    { id: 'wh-02', provider: 'ZALO_ZNS', event: 'otp.delivered', status: '200 OK', time: '14:55:04' },
    { id: 'wh-03', provider: 'IOT_WATER_METER', event: 'reading.telemetry', status: '200 OK', time: '14:30:12' },
    { id: 'wh-04', provider: 'SMART_LOCKER_BOX', event: 'door.opened', status: '200 OK', time: '13:10:45' },
  ]);

  // State demo cho AI RAG Chunks
  const [knowledgeChunks] = useState([
    { id: 'rag-01', doc: 'Quy chế sử dụng Hồ bơi & Sân Tennis', chunk: 'Cư dân được đặt tối đa 2 giờ/ngày, phí cọc hủy slot là 50.000 VNĐ nếu báo trễ dưới 2 tiếng.', tokens: 48 },
    { id: 'rag-02', doc: 'Nội quy nuôi thú cưng tòa nhà', chunk: 'Chó mèo phải đăng ký tại quầy lễ tân, gắn thẻ định danh và đeo rọ mõm khi di chuyển trong thang máy.', tokens: 54 },
    { id: 'rag-03', doc: 'Quy trình tiếp nhận hàng hóa bưu phẩm', chunk: 'Bưu phẩm lưu kho tại phòng lễ tân tối đa 3 ngày. Sau 3 ngày chuyển sang tủ đồ thông minh.', tokens: 62 },
  ]);

  const toggleFeatureFlag = (key: string) => {
    setFeatureFlags(prev =>
      prev.map(f => (f.key === key ? { ...f, enabled: !f.enabled } : f))
    );
  };

  const handleExportReport = () => {
    setIsExporting(true);
    setTimeout(() => {
      setIsExporting(false);
      setExportSuccess(true);
      setTimeout(() => setExportSuccess(false), 3000);
    }, 800);
  };

  return (
    <div className="min-h-screen bg-[#FDFDFD] text-[#1E293B] flex flex-col font-sans">
      {/* 1. TOP HEADER (Khớp 100% ảnh giao diện) */}
      <header className="h-16 border-b border-[#E2E8F0] bg-white px-6 flex items-center justify-between sticky top-0 z-20 shadow-sm">
        {/* Left Logo */}
        <div className="flex items-center gap-3.5 w-64">
          <div className="w-8 h-8 rounded bg-black flex items-center justify-center shadow">
            <Building2 className="w-4 h-4 text-white" />
          </div>
          <div>
            <div className="font-extrabold text-[13px] tracking-wide text-black uppercase">
              SMART CASSAVAS
            </div>
            <div className="text-[9.5px] font-mono tracking-widest text-[#64748B] uppercase">
              DEVELOPER CONSOLE
            </div>
          </div>
        </div>

        {/* Middle Date & Welcome */}
        <div className="hidden md:flex flex-col text-left">
          <span className="text-[11px] text-[#64748B]">Thứ Tư, 23 tháng 09, 2026</span>
          <span className="text-xs font-semibold text-[#0F172A]">Xin chào, {userName}</span>
        </div>

        {/* Right Actions */}
        <div className="flex items-center gap-3">
          <button
            title="Lịch sử nhật ký"
            className="w-8 h-8 rounded-md border border-[#E2E8F0] bg-white flex items-center justify-center text-[#64748B] hover:text-black hover:bg-[#F8FAFC] transition-colors"
          >
            <Clock className="w-4 h-4" />
          </button>

          <button
            title="Thông báo hệ thống"
            className="w-8 h-8 rounded-md border border-[#E2E8F0] bg-white flex items-center justify-center text-[#64748B] hover:text-black hover:bg-[#F8FAFC] transition-colors relative"
          >
            <Bell className="w-4 h-4" />
            <span className="w-2 h-2 rounded-full bg-emerald-500 absolute top-1.5 right-1.5" />
          </button>

          {/* Building Selector */}
          <div className="relative">
            <button className="h-8 px-3 rounded-md border border-[#E2E8F0] bg-white text-xs font-medium text-[#1E293B] flex items-center gap-2 hover:bg-[#F8FAFC] transition-colors">
              <span className="w-2 h-2 rounded-full bg-indigo-500" />
              <span>{selectedBlock}</span>
              <ChevronDown className="w-3.5 h-3.5 text-[#64748B]" />
            </button>
          </div>
        </div>
      </header>

      {/* 2. BODY LAYOUT: SIDEBAR + MAIN CONTENT */}
      <div className="flex-1 flex overflow-hidden">
        {/* Left Sidebar */}
        <aside className="w-64 border-r border-[#E2E8F0] bg-white flex flex-col justify-between shrink-0">
          {/* Menu Items */}
          <div className="p-4">
            <div className="px-3 mb-3 text-[10px] font-bold tracking-wider text-[#64748B] uppercase">
              KHÔNG GIAN PHÁT TRIỂN
            </div>

            <nav className="space-y-1">
              <button
                onClick={() => setActiveTab('overview')}
                className={`w-full flex items-center gap-3 px-3 py-2 rounded-md text-xs font-medium transition-all text-left ${
                  activeTab === 'overview'
                    ? 'bg-slate-100 text-black font-semibold'
                    : 'text-[#475569] hover:bg-[#F8FAFC] hover:text-black'
                }`}
              >
                <Terminal className="w-4 h-4 text-[#475569]" />
                <span>Tổng quan Dev Console</span>
              </button>

              <button
                onClick={() => setActiveTab('ai_triage')}
                className={`w-full flex items-center gap-3 px-3 py-2 rounded-md text-xs font-medium transition-all text-left ${
                  activeTab === 'ai_triage'
                    ? 'bg-slate-100 text-black font-semibold'
                    : 'text-[#475569] hover:bg-[#F8FAFC] hover:text-black'
                }`}
              >
                <Bot className="w-4 h-4 text-[#475569]" />
                <span>AI Chatbot & Triage Ticket</span>
              </button>

              <button
                onClick={() => setActiveTab('api_iot')}
                className={`w-full flex items-center gap-3 px-3 py-2 rounded-md text-xs font-medium transition-all text-left ${
                  activeTab === 'api_iot'
                    ? 'bg-slate-100 text-black font-semibold'
                    : 'text-[#475569] hover:bg-[#F8FAFC] hover:text-black'
                }`}
              >
                <Plug className="w-4 h-4 text-[#475569]" />
                <span>API, Webhook & IoT</span>
              </button>

              <button
                onClick={() => setActiveTab('audit_flags')}
                className={`w-full flex items-center gap-3 px-3 py-2 rounded-md text-xs font-medium transition-all text-left ${
                  activeTab === 'audit_flags'
                    ? 'bg-slate-100 text-black font-semibold'
                    : 'text-[#475569] hover:bg-[#F8FAFC] hover:text-black'
                }`}
              >
                <FileText className="w-4 h-4 text-[#475569]" />
                <span>Audit log & Feature flag</span>
              </button>
            </nav>

            {/* Quick Switch to Building Management */}
            <div className="mt-8 pt-4 border-t border-[#E2E8F0]">
              <div className="px-3 mb-2 text-[10px] font-bold tracking-wider text-[#94A3B8] uppercase">
                NGHIỆP VỤ VẬN HÀNH
              </div>
              <button
                onClick={onNavigateManager}
                className="w-full flex items-center justify-between px-3 py-2 rounded-md text-xs font-medium text-indigo-700 bg-indigo-50/70 hover:bg-indigo-100/70 transition-colors border border-indigo-200/60"
              >
                <div className="flex items-center gap-2">
                  <Building2 className="w-3.5 h-3.5 text-indigo-600" />
                  <span>Cổng Ban Quản Lý</span>
                </div>
                <ArrowRight className="w-3.5 h-3.5 text-indigo-400" />
              </button>
            </div>
          </div>

          {/* Profile & Logout (Khớp ảnh góc dưới trái) */}
          <div className="p-3 border-t border-[#E2E8F0] bg-white">
            <div className="p-2 rounded-md border border-[#E2E8F0] flex items-center justify-between mb-2 hover:bg-[#F8FAFC] cursor-pointer">
              <div className="flex items-center gap-2.5">
                <div className="w-7 h-7 rounded bg-[#E2E8F0] text-[#334155] font-bold text-xs flex items-center justify-center">
                  DV
                </div>
                <div className="text-left">
                  <div className="text-xs font-bold text-[#0F172A] leading-tight">{userName}</div>
                  <div className="text-[10px] text-[#64748B] font-mono leading-tight">{userEmail}</div>
                </div>
              </div>
              <ChevronDown className="w-3.5 h-3.5 text-[#94A3B8]" />
            </div>

            <button
              onClick={onLogout}
              className="w-full flex items-center gap-2 px-3 py-1.5 text-xs text-[#64748B] hover:text-red-600 hover:bg-red-50 rounded transition-colors"
            >
              <LogOut className="w-3.5 h-3.5" />
              <span>Đăng xuất</span>
            </button>
          </div>
        </aside>

        {/* Right Main Content Area (Khớp ảnh) */}
        <main className="flex-1 overflow-y-auto p-8 bg-[#FDFDFD]">
          {/* Breadcrumb & Title Bar */}
          <div className="flex items-start justify-between mb-6">
            <div>
              <div className="flex items-center gap-1.5 text-[11px] font-mono font-bold tracking-wider text-[#64748B] uppercase mb-1">
                <Terminal className="w-3.5 h-3.5" />
                <span>DEVELOPER PORTAL</span>
              </div>
              <h1 className="text-2xl font-extrabold text-[#0F172A] tracking-tight">
                {activeTab === 'overview' && 'Dev Console'}
                {activeTab === 'ai_triage' && 'AI Chatbot & Triage Ticket'}
                {activeTab === 'api_iot' && 'API, Webhook & Thiết Bị IoT'}
                {activeTab === 'audit_flags' && 'Audit Log & Feature Flags'}
              </h1>
              <p className="text-xs text-[#64748B] mt-1">
                Quản lý tích hợp, tự động hóa, cờ tính năng và nhật ký hệ thống.
              </p>
            </div>

            {/* Action Export Button */}
            <div className="flex items-center gap-2">
              <button
                onClick={handleExportReport}
                disabled={isExporting}
                className="h-9 px-4 rounded bg-black text-white text-xs font-semibold hover:bg-slate-800 transition-colors flex items-center gap-2 shadow-sm"
              >
                {isExporting ? (
                  <RefreshCw className="w-3.5 h-3.5 animate-spin" />
                ) : (
                  <FileText className="w-3.5 h-3.5" />
                )}
                <span>{exportSuccess ? 'Đã xuất file!' : 'Xuất báo cáo'}</span>
              </button>
            </div>
          </div>

          {/* TAB 1: OVERVIEW (Khớp ảnh gốc: Thẻ "Khu vực phát triển hệ thống") */}
          {activeTab === 'overview' && (
            <div className="space-y-6">
              {/* Main Card từ ảnh mẫu */}
              <div className="rounded-lg border border-[#E2E8F0] bg-white p-6 shadow-sm">
                <h2 className="text-sm font-bold text-[#0F172A]">
                  Khu vực phát triển hệ thống
                </h2>
                <p className="text-xs text-[#64748B] mt-1">
                  Các chức năng kỹ thuật được tách riêng khỏi nghiệp vụ quản lý tòa nhà.
                </p>

                {/* Sub-cards */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mt-6">
                  <div
                    onClick={() => setActiveTab('ai_triage')}
                    className="p-4 rounded-lg border border-[#E2E8F0] bg-[#F8FAFC] hover:border-indigo-300 hover:bg-indigo-50/30 transition-all cursor-pointer group"
                  >
                    <div className="w-8 h-8 rounded bg-white border border-[#E2E8F0] flex items-center justify-center mb-3 group-hover:border-indigo-400">
                      <Bot className="w-4 h-4 text-indigo-600" />
                    </div>
                    <div className="text-xs font-bold text-[#0F172A]">AI Chatbot & Triage Ticket</div>
                    <div className="text-[11px] text-[#64748B] mt-1">
                      Cấu hình RAG kiến thức, bóc tách OCR CCCD và tự động phân loại sự cố cư dân.
                    </div>
                  </div>

                  <div
                    onClick={() => setActiveTab('api_iot')}
                    className="p-4 rounded-lg border border-[#E2E8F0] bg-[#F8FAFC] hover:border-cyan-300 hover:bg-cyan-50/30 transition-all cursor-pointer group"
                  >
                    <div className="w-8 h-8 rounded bg-white border border-[#E2E8F0] flex items-center justify-center mb-3 group-hover:border-cyan-400">
                      <Plug className="w-4 h-4 text-cyan-600" />
                    </div>
                    <div className="text-xs font-bold text-[#0F172A]">API, Webhook & IoT</div>
                    <div className="text-[11px] text-[#64748B] mt-1">
                      Mô phỏng Webhook cổng thanh toán VietQR/VNPay, đồng hồ nước và Smart Locker.
                    </div>
                  </div>

                  <div
                    onClick={() => setActiveTab('audit_flags')}
                    className="p-4 rounded-lg border border-[#E2E8F0] bg-[#F8FAFC] hover:border-amber-300 hover:bg-amber-50/30 transition-all cursor-pointer group"
                  >
                    <div className="w-8 h-8 rounded bg-white border border-[#E2E8F0] flex items-center justify-center mb-3 group-hover:border-amber-400">
                      <Sliders className="w-4 h-4 text-amber-600" />
                    </div>
                    <div className="text-xs font-bold text-[#0F172A]">Audit Log & Feature Flags</div>
                    <div className="text-[11px] text-[#64748B] mt-1">
                      Bật tắt cờ tính năng tức thì không cần redeploy, tra cứu vết lịch sử thao tác.
                    </div>
                  </div>
                </div>
              </div>

              {/* Status Indicator */}
              <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div className="p-4 rounded-lg border border-[#E2E8F0] bg-white">
                  <div className="text-[10px] font-bold text-[#64748B] uppercase tracking-wider">Hệ quản trị CSDL</div>
                  <div className="text-base font-extrabold text-[#0F172A] mt-1 flex items-center gap-2">
                    <Database className="w-4 h-4 text-emerald-600" />
                    <span>MySQL 8.0 InnoDB</span>
                  </div>
                  <div className="text-[10px] text-emerald-600 mt-1">109 bảng dữ liệu kết nối an toàn</div>
                </div>

                <div className="p-4 rounded-lg border border-[#E2E8F0] bg-white">
                  <div className="text-[10px] font-bold text-[#64748B] uppercase tracking-wider">Môi trường Thực thi</div>
                  <div className="text-base font-extrabold text-[#0F172A] mt-1 flex items-center gap-2">
                    <Server className="w-4 h-4 text-indigo-600" />
                    <span>PHP 8.5 + Docker</span>
                  </div>
                  <div className="text-[10px] text-[#64748B] mt-1">Nginx + Queue Worker active</div>
                </div>

                <div className="p-4 rounded-lg border border-[#E2E8F0] bg-white">
                  <div className="text-[10px] font-bold text-[#64748B] uppercase tracking-wider">API Health Status</div>
                  <div className="text-base font-extrabold text-emerald-600 mt-1 flex items-center gap-2">
                    <CheckCircle2 className="w-4 h-4 text-emerald-600" />
                    <span>200 OK (Healthy)</span>
                  </div>
                  <div className="text-[10px] text-[#64748B] mt-1">Độ trễ phản hồi: 24ms</div>
                </div>

                <div className="p-4 rounded-lg border border-[#E2E8F0] bg-white">
                  <div className="text-[10px] font-bold text-[#64748B] uppercase tracking-wider">Phân quyền RBAC</div>
                  <div className="text-base font-extrabold text-[#0F172A] mt-1 flex items-center gap-2">
                    <Shield className="w-4 h-4 text-indigo-600" />
                    <span>Bearer Sanctum</span>
                  </div>
                  <div className="text-[10px] text-indigo-600 mt-1">Role: Super Admin / Dev</div>
                </div>
              </div>
            </div>
          )}

          {/* TAB 2: AI CHATBOT & TRIAGE TICKET */}
          {activeTab === 'ai_triage' && (
            <div className="space-y-6">
              <div className="rounded-lg border border-[#E2E8F0] bg-white p-6 shadow-sm">
                <div className="flex items-center justify-between mb-4">
                  <div>
                    <h3 className="text-sm font-bold text-[#0F172A]">Cơ sở tri thức hỏi đáp RAG (AI Knowledge Chunks)</h3>
                    <p className="text-xs text-[#64748B]">Các đoạn văn bản đã được vector hóa phục vụ Trợ lý ảo AI cư dân</p>
                  </div>
                  <span className="px-2.5 py-1 rounded bg-indigo-50 text-indigo-700 font-mono text-xs font-semibold border border-indigo-200">
                    Model: GPT-4o-mini / Embeddings
                  </span>
                </div>

                <div className="space-y-3">
                  {knowledgeChunks.map((kc, idx) => (
                    <div key={idx} className="p-3.5 rounded-lg border border-[#E2E8F0] bg-[#F8FAFC]">
                      <div className="flex items-center justify-between mb-1.5">
                        <span className="text-xs font-bold text-[#0F172A]">{kc.doc}</span>
                        <span className="text-[10px] font-mono text-[#64748B]">{kc.tokens} tokens</span>
                      </div>
                      <p className="text-xs text-[#475569] font-mono leading-relaxed">{kc.chunk}</p>
                    </div>
                  ))}
                </div>
              </div>

              {/* Triage Ticket Simulator */}
              <div className="rounded-lg border border-[#E2E8F0] bg-white p-6 shadow-sm">
                <h3 className="text-sm font-bold text-[#0F172A] mb-1">Mô phỏng Phân loại Sự cố Tự động (AI Triage Simulator)</h3>
                <p className="text-xs text-[#64748B] mb-4">Phân tích mô tả sự cố bằng AI để tự động gán nhãn chuyên môn và mức độ khẩn cấp</p>
                <div className="p-4 rounded-lg bg-slate-900 text-slate-100 font-mono text-xs space-y-2">
                  <div className="text-slate-400">// Input mẫu từ Cư dân Căn hộ A-1204:</div>
                  <div className="text-emerald-400">"Nước ở bồn rửa mặt chảy lênh láng ra sàn hành lang, có mùi khét từ ổ điện phía dưới!"</div>
                  <div className="text-slate-400 pt-2">// Kết quả phân tích tự động từ AI Triage Model:</div>
                  <div className="text-amber-400">» Độ khẩn cấp: KHẨN CẤP (URGENT / CRITICAL)</div>
                  <div className="text-cyan-400">» Chuyên môn: Điện nước kết hợp (Plumbing & Electrical Safety)</div>
                  <div className="text-indigo-400">» SLA đề xuất: Xử lý trong vòng 30 phút</div>
                  <div className="text-slate-400">» Đã kích hoạt cảnh báo tới Trưởng ca Kỹ thuật trực nhật.</div>
                </div>
              </div>
            </div>
          )}

          {/* TAB 3: API, WEBHOOK & IOT */}
          {activeTab === 'api_iot' && (
            <div className="space-y-6">
              {/* Webhooks table */}
              <div className="rounded-lg border border-[#E2E8F0] bg-white p-6 shadow-sm">
                <div className="flex items-center justify-between mb-4">
                  <div>
                    <h3 className="text-sm font-bold text-[#0F172A]">Nhật ký Inbound Webhooks</h3>
                    <p className="text-xs text-[#64748B]">Các sự kiện nhận được từ đối tác thanh toán và thiết bị phần cứng</p>
                  </div>
                  <button className="px-3 py-1.5 rounded text-xs font-medium border border-[#E2E8F0] bg-[#F8FAFC] hover:bg-slate-100 text-[#0F172A]">
                    Giả lập Webhook mới
                  </button>
                </div>

                <div className="overflow-x-auto">
                  <table className="w-full text-left text-xs">
                    <thead>
                      <tr className="border-b border-[#E2E8F0] text-[#64748B] font-bold">
                        <th className="py-2.5 px-3">Mã ID</th>
                        <th className="py-2.5 px-3">Nhà cung cấp</th>
                        <th className="py-2.5 px-3">Loại sự kiện</th>
                        <th className="py-2.5 px-3">Mã trạng thái</th>
                        <th className="py-2.5 px-3">Thời gian</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-[#E2E8F0]">
                      {webhookLogs.map(log => (
                        <tr key={log.id} className="hover:bg-[#F8FAFC]">
                          <td className="py-2.5 px-3 font-mono font-semibold">{log.id}</td>
                          <td className="py-2.5 px-3 font-medium text-indigo-700">{log.provider}</td>
                          <td className="py-2.5 px-3 font-mono">{log.event}</td>
                          <td className="py-2.5 px-3 font-mono text-emerald-600 font-bold">{log.status}</td>
                          <td className="py-2.5 px-3 text-[#64748B] font-mono">{log.time}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>

              {/* IoT Simulator */}
              <div className="rounded-lg border border-[#E2E8F0] bg-white p-6 shadow-sm">
                <h3 className="text-sm font-bold text-[#0F172A] mb-1">Mô phỏng Kết nối Thiết bị IoT Tòa nhà</h3>
                <p className="text-xs text-[#64748B] mb-4">Mô phỏng luồng telemetry từ đồng hồ nước và cổng an ninh</p>
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                  <div className="p-4 rounded-lg border border-emerald-200 bg-emerald-50/40">
                    <div className="flex items-center justify-between mb-2">
                      <span className="text-xs font-bold text-emerald-900">Đồng hồ Nước Khu A</span>
                      <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />
                    </div>
                    <div className="text-lg font-extrabold text-emerald-700">1,248.5 m³</div>
                    <div className="text-[10px] text-emerald-800 mt-1">Giao thức: MQTT Broker • Online</div>
                  </div>

                  <div className="p-4 rounded-lg border border-indigo-200 bg-indigo-50/40">
                    <div className="flex items-center justify-between mb-2">
                      <span className="text-xs font-bold text-indigo-900">Tủ đồ Smart Locker sảnh B</span>
                      <span className="w-2 h-2 rounded-full bg-indigo-500 animate-pulse" />
                    </div>
                    <div className="text-lg font-extrabold text-indigo-700">12/16 Ngăn trống</div>
                    <div className="text-[10px] text-indigo-800 mt-1">Cổng điều khiển: TCP Relay • Sẵn sàng</div>
                  </div>

                  <div className="p-4 rounded-lg border border-amber-200 bg-amber-50/40">
                    <div className="flex items-center justify-between mb-2">
                      <span className="text-xs font-bold text-amber-900">Camera ANPR Bãi xe</span>
                      <span className="w-2 h-2 rounded-full bg-amber-500 animate-pulse" />
                    </div>
                    <div className="text-lg font-extrabold text-amber-700">98.4% Accuracy</div>
                    <div className="text-[10px] text-amber-800 mt-1">Độ phân giải: 1080p RTSP Stream</div>
                  </div>
                </div>
              </div>
            </div>
          )}

          {/* TAB 4: AUDIT LOGS & FEATURE FLAGS */}
          {activeTab === 'audit_flags' && (
            <div className="space-y-6">
              {/* Feature Flags Card */}
              <div className="rounded-lg border border-[#E2E8F0] bg-white p-6 shadow-sm">
                <div className="mb-4">
                  <h3 className="text-sm font-bold text-[#0F172A]">Cờ tính năng thời gian thực (Dynamic Feature Flags)</h3>
                  <p className="text-xs text-[#64748B]">Bật tắt các module thử nghiệm tức thời mà không cần can thiệp mã nguồn hay deploy lại</p>
                </div>

                <div className="divide-y divide-[#E2E8F0]">
                  {featureFlags.map(ff => (
                    <div key={ff.key} className="py-3 flex items-center justify-between">
                      <div>
                        <div className="text-xs font-bold text-[#0F172A] flex items-center gap-2">
                          <span>{ff.name}</span>
                          <span className="px-1.5 py-0.5 rounded text-[10px] font-mono bg-slate-100 text-slate-700 border border-slate-200">
                            {ff.key}
                          </span>
                        </div>
                        <div className="text-[11px] text-[#64748B] mt-0.5">{ff.desc}</div>
                      </div>

                      <button
                        onClick={() => toggleFeatureFlag(ff.key)}
                        className={`w-11 h-6 flex items-center rounded-full p-1 transition-colors ${
                          ff.enabled ? 'bg-indigo-600 justify-end' : 'bg-slate-300 justify-start'
                        }`}
                      >
                        <div className="bg-white w-4 h-4 rounded-full shadow-md" />
                      </button>
                    </div>
                  ))}
                </div>
              </div>

              {/* Audit Trail Sample */}
              <div className="rounded-lg border border-[#E2E8F0] bg-white p-6 shadow-sm">
                <h3 className="text-sm font-bold text-[#0F172A] mb-1">Nhật ký Kiểm toán Hệ thống (System Audit Trail)</h3>
                <p className="text-xs text-[#64748B] mb-4">Ghi nhận mọi thao tác thay đổi dữ liệu theo chuẩn Change Data Capture (CDC)</p>
                <div className="p-3 bg-[#F8FAFC] border border-[#E2E8F0] rounded-lg font-mono text-xs text-[#334155] space-y-1.5">
                  <div className="text-emerald-700">[2026-09-23 15:42:01] USER (dev@cassavas.vn) UPDATED feature_flag: ENABLE_AI_TICKET_TRIAGE = true</div>
                  <div className="text-indigo-700">[2026-09-23 14:30:15] CRON_JOB executed batch_generate_invoices for period: 2026-09 (Success: 120 invoices)</div>
                  <div className="text-slate-600">[2026-09-23 13:15:22] ROLE_PERMISSION modified by admin@cassavas.vn: Granted permission "amenity.approve"</div>
                </div>
              </div>
            </div>
          )}
        </main>
      </div>
    </div>
  );
};
