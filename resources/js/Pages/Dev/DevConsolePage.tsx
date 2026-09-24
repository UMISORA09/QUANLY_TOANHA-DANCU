import React, { useState } from 'react';
import {
  Terminal,
  Bot,
  Plug,
  Sliders,
  Database,
  Server,
  CheckCircle2,
  Shield,
  FileText,
  RefreshCw,
  Cpu,
  ArrowRight,
  Building2,
  Sparkles,
  Zap,
} from 'lucide-react';
import { AppLayout } from '../../Components/Layout/AppLayout';
import { CicdDashboard } from '../../Components/Cicd/CicdDashboard';

interface DevConsolePageProps {
  onLogout: () => void;
  onNavigateHome: () => void;
  onNavigateManager: () => void;
  userName?: string;
  userEmail?: string;
  initialTab?: string;
}

export const DevConsolePage: React.FC<DevConsolePageProps> = ({
  onLogout,
  onNavigateHome,
  onNavigateManager,
  userName = 'Admin & Dev Team',
  userEmail = 'admin@cassavas.vn',
  initialTab = 'overview',
}) => {
  const [activeTab, setActiveTab] = useState<string>(initialTab);
  const [isExporting, setIsExporting] = useState<boolean>(false);
  const [exportSuccess, setExportSuccess] = useState<boolean>(false);

  // State demo cho Feature Flags
  const [featureFlags, setFeatureFlags] = useState([
    {
      key: 'ENABLE_AI_TICKET_TRIAGE',
      name: 'AI Phân loại sự cố tự động (Triage)',
      enabled: true,
      desc: 'Tự động gán độ ưu tiên và nhãn kỹ thuật viên cho Ticket phản ánh của cư dân',
    },
    {
      key: 'ENABLE_CONCURRENCY_LOCK',
      name: 'Khóa đồng thời đặt tiện ích (Concurrency Lock)',
      enabled: true,
      desc: 'Chống trùng lịch đặt sân tennis/hồ bơi khi 2 cư dân cùng bấm đặt 1 slot',
    },
    {
      key: 'ENABLE_SMART_LOCKER_OTP',
      name: 'Tủ đồ thông minh cấp mã PIN/OTP tự động',
      enabled: false,
      desc: 'Sinh mã OTP 6 số gửi qua thông báo đẩy khi có bưu phẩm mới vào sảnh',
    },
    {
      key: 'ENABLE_VIETQR_DYNAMIC',
      name: 'Cổng thanh toán VietQR động',
      enabled: true,
      desc: 'Tạo mã QR có sẵn số tiền và mã hóa đơn kỳ hiện tại tự động gạch nợ',
    },
    {
      key: 'ENABLE_ANPR_CAMERA_GATE',
      name: 'Camera AI nhận diện biển số (ANPR)',
      enabled: false,
      desc: 'Mô phỏng bốt barie nhận diện biển xe cư dân ra vào tự động mở cổng',
    },
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
    {
      id: 'rag-01',
      doc: 'Quy chế sử dụng Hồ bơi & Sân Tennis',
      chunk: 'Cư dân được đặt tối đa 2 giờ/ngày, phí cọc hủy slot là 50.000 VNĐ nếu báo trễ dưới 2 tiếng.',
      tokens: 48,
    },
    {
      id: 'rag-02',
      doc: 'Nội quy nuôi thú cưng tòa nhà',
      chunk: 'Chó mèo phải đăng ký tại quầy lễ tân, gắn thẻ định danh và đeo rọ mõm khi di chuyển trong thang máy.',
      tokens: 54,
    },
    {
      id: 'rag-03',
      doc: 'Quy trình tiếp nhận hàng hóa bưu phẩm',
      chunk: 'Bưu phẩm lưu kho tại phòng lễ tân tối đa 3 ngày. Sau 3 ngày chuyển sang tủ đồ thông minh.',
      tokens: 62,
    },
  ]);

  const toggleFeatureFlag = (key: string) => {
    setFeatureFlags((prev) =>
      prev.map((f) => (f.key === key ? { ...f, enabled: !f.enabled } : f))
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
    <AppLayout
      role="dev"
      userRole="admin"
      activeItemId={activeTab}
      onItemClick={(id) => {
        if (id === 'manager_portal') {
          onNavigateManager();
          return;
        }
        setActiveTab(id);
      }}
      userName={userName}
      userEmail={userEmail}
      onLogout={onLogout}
      onNavigateHome={onNavigateHome}
      statusText="Hệ thống Developer & Kỹ thuật đang vận hành ổn định"
    >
      <div className="p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto space-y-6 animate-in fade-in duration-200">
        {/* Breadcrumb & Title Bar */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-200/80">
          <div>
            <div className="flex items-center gap-2 text-xs font-mono font-bold tracking-wider text-cyan-600 uppercase mb-1">
              <Terminal className="w-4 h-4 text-cyan-500" />
              <span>DEVELOPER & SYSTEM ADMIN CONSOLE</span>
            </div>
            <h1 className="text-xl sm:text-2xl font-black text-slate-900 tracking-tight flex items-center gap-2">
              {activeTab === 'overview' && 'Bàn Làm Việc Developer Console'}
              {activeTab === 'ai_triage' && 'AI Chatbot & Phân Loại Sự Cố Tự Động (Triage)'}
              {activeTab === 'api_iot' && 'API, Webhooks & Thiết Bị IoT Tòa Nhà'}
              {activeTab === 'audit_flags' && 'Cờ Tính Năng (Feature Flags) & Audit Logs'}
              {activeTab === 'cicd' && 'Hệ Thống Tự Động Hóa CI/CD & DevOps Pipeline'}
            </h1>
            <p className="text-xs text-slate-500 mt-0.5">
              Quản lý hạ tầng kỹ thuật, mô phỏng IoT, tri thức AI RAG và kiểm toán hệ thống Smart Cassavas.
            </p>
          </div>

          <div className="flex items-center gap-2">
            <button
              onClick={handleExportReport}
              disabled={isExporting}
              className="h-9 px-3.5 rounded-xl bg-neutral-900 text-white text-xs font-semibold hover:bg-neutral-800 transition-all flex items-center gap-2 shadow-sm cursor-pointer active:scale-95"
            >
              {isExporting ? (
                <RefreshCw className="w-3.5 h-3.5 animate-spin" />
              ) : (
                <FileText className="w-3.5 h-3.5" />
              )}
              <span>{exportSuccess ? 'Đã xuất file log!' : 'Xuất báo cáo kỹ thuật'}</span>
            </button>
          </div>
        </div>

        {/* TAB 1: OVERVIEW */}
        {activeTab === 'overview' && (
          <div className="space-y-6">
            {/* Main Welcome Card */}
            <div className="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs">
              <div className="flex items-center justify-between">
                <div>
                  <h2 className="text-base font-extrabold text-slate-900 flex items-center gap-2">
                    <span>Khu vực phát triển hệ thống Smart Cassavas</span>
                    <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />
                  </h2>
                  <p className="text-xs text-slate-500 mt-1">
                    Các chức năng kỹ thuật, AI và IoT được quản lý tập trung và dùng thanh Sidebar / Topbar đồng bộ với toàn hệ thống.
                  </p>
                </div>
                <button
                  onClick={onNavigateManager}
                  className="hidden sm:flex items-center gap-2 px-3 py-1.5 rounded-xl text-xs font-semibold text-indigo-700 bg-indigo-50 hover:bg-indigo-100 transition-all border border-indigo-200 cursor-pointer"
                >
                  <Building2 className="w-4 h-4 text-indigo-600" />
                  <span>Sang Cổng Ban Quản Lý</span>
                  <ArrowRight className="w-3.5 h-3.5" />
                </button>
              </div>

              {/* Sub-cards */}
              <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mt-6">
                <div
                  onClick={() => setActiveTab('ai_triage')}
                  className="p-4 rounded-xl border border-slate-200 bg-slate-50/70 hover:border-indigo-300 hover:bg-indigo-50/40 transition-all cursor-pointer group"
                >
                  <div className="w-9 h-9 rounded-lg bg-white border border-slate-200 flex items-center justify-center mb-3 shadow-2xs group-hover:scale-105 transition-transform">
                    <Bot className="w-4 h-4 text-indigo-600" />
                  </div>
                  <div className="text-xs font-bold text-slate-900">AI Chatbot & Triage</div>
                  <div className="text-[11px] text-slate-500 mt-1 leading-relaxed">
                    Cơ sở tri thức RAG, embedding văn bản và mô hình tự động phân loại sự cố cư dân.
                  </div>
                </div>

                <div
                  onClick={() => setActiveTab('api_iot')}
                  className="p-4 rounded-xl border border-slate-200 bg-slate-50/70 hover:border-cyan-300 hover:bg-cyan-50/40 transition-all cursor-pointer group"
                >
                  <div className="w-9 h-9 rounded-lg bg-white border border-slate-200 flex items-center justify-center mb-3 shadow-2xs group-hover:scale-105 transition-transform">
                    <Plug className="w-4 h-4 text-cyan-600" />
                  </div>
                  <div className="text-xs font-bold text-slate-900">API, Webhook & IoT</div>
                  <div className="text-[11px] text-slate-500 mt-1 leading-relaxed">
                    Mô phỏng Webhook cổng thanh toán VietQR/VNPay, đồng hồ nước và Smart Locker.
                  </div>
                </div>

                <div
                  onClick={() => setActiveTab('audit_flags')}
                  className="p-4 rounded-xl border border-slate-200 bg-slate-50/70 hover:border-amber-300 hover:bg-amber-50/40 transition-all cursor-pointer group"
                >
                  <div className="w-9 h-9 rounded-lg bg-white border border-slate-200 flex items-center justify-center mb-3 shadow-2xs group-hover:scale-105 transition-transform">
                    <Sliders className="w-4 h-4 text-amber-600" />
                  </div>
                  <div className="text-xs font-bold text-slate-900">Feature Flags & Audit</div>
                  <div className="text-[11px] text-slate-500 mt-1 leading-relaxed">
                    Bật tắt cờ tính năng tức thì không cần redeploy, tra cứu vết lịch sử kiểm toán.
                  </div>
                </div>

                <div
                  onClick={() => setActiveTab('cicd')}
                  className="p-4 rounded-xl border border-slate-200 bg-slate-50/70 hover:border-emerald-300 hover:bg-emerald-50/40 transition-all cursor-pointer group"
                >
                  <div className="w-9 h-9 rounded-lg bg-white border border-slate-200 flex items-center justify-center mb-3 shadow-2xs group-hover:scale-105 transition-transform">
                    <Terminal className="w-4 h-4 text-emerald-600" />
                  </div>
                  <div className="text-xs font-bold text-slate-900">CI/CD & DevOps</div>
                  <div className="text-[11px] text-slate-500 mt-1 leading-relaxed">
                    Pipeline tự động kiểm thử, độ phủ test, build artifact và theo dõi release.
                  </div>
                </div>
              </div>
            </div>

            {/* Status Indicators */}
            <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
              <div className="p-4 rounded-xl border border-slate-200/80 bg-white shadow-2xs">
                <div className="text-[10px] font-bold text-slate-400 uppercase tracking-wider font-mono">
                  Hệ quản trị CSDL
                </div>
                <div className="text-base font-extrabold text-slate-900 mt-1 flex items-center gap-2">
                  <Database className="w-4 h-4 text-emerald-600" />
                  <span>MySQL 8.0 InnoDB</span>
                </div>
                <div className="text-[10px] text-emerald-600 font-medium mt-1">109 bảng dữ liệu kết nối an toàn</div>
              </div>

              <div className="p-4 rounded-xl border border-slate-200/80 bg-white shadow-2xs">
                <div className="text-[10px] font-bold text-slate-400 uppercase tracking-wider font-mono">
                  Môi trường Thực thi
                </div>
                <div className="text-base font-extrabold text-slate-900 mt-1 flex items-center gap-2">
                  <Server className="w-4 h-4 text-indigo-600" />
                  <span>PHP 8.5 + Docker</span>
                </div>
                <div className="text-[10px] text-slate-500 font-medium mt-1">Nginx + Queue Worker active</div>
              </div>

              <div className="p-4 rounded-xl border border-slate-200/80 bg-white shadow-2xs">
                <div className="text-[10px] font-bold text-slate-400 uppercase tracking-wider font-mono">
                  API Health Status
                </div>
                <div className="text-base font-extrabold text-emerald-600 mt-1 flex items-center gap-2">
                  <CheckCircle2 className="w-4 h-4 text-emerald-600" />
                  <span>200 OK (Healthy)</span>
                </div>
                <div className="text-[10px] text-slate-500 font-medium mt-1">Độ trễ phản hồi: 24ms</div>
              </div>

              <div className="p-4 rounded-xl border border-slate-200/80 bg-white shadow-2xs">
                <div className="text-[10px] font-bold text-slate-400 uppercase tracking-wider font-mono">
                  Phân quyền RBAC
                </div>
                <div className="text-base font-extrabold text-slate-900 mt-1 flex items-center gap-2">
                  <Shield className="w-4 h-4 text-indigo-600" />
                  <span>Bearer Sanctum</span>
                </div>
                <div className="text-[10px] text-indigo-600 font-medium mt-1">Role: Super Admin / Developer</div>
              </div>
            </div>
          </div>
        )}

        {/* TAB 2: AI CHATBOT & TRIAGE TICKET */}
        {activeTab === 'ai_triage' && (
          <div className="space-y-6">
            <div className="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs">
              <div className="flex items-center justify-between mb-4">
                <div>
                  <h3 className="text-sm font-bold text-slate-900">Cơ sở tri thức hỏi đáp RAG (AI Knowledge Chunks)</h3>
                  <p className="text-xs text-slate-500">Các đoạn văn bản đã được vector hóa phục vụ Trợ lý ảo AI cư dân</p>
                </div>
                <span className="px-2.5 py-1 rounded-lg bg-indigo-50 text-indigo-700 font-mono text-xs font-semibold border border-indigo-200">
                  Model: GPT-4o-mini / Embeddings
                </span>
              </div>

              <div className="space-y-3">
                {knowledgeChunks.map((kc, idx) => (
                  <div key={idx} className="p-3.5 rounded-xl border border-slate-200 bg-slate-50/70">
                    <div className="flex items-center justify-between mb-1.5">
                      <span className="text-xs font-bold text-slate-900">{kc.doc}</span>
                      <span className="text-[10px] font-mono text-slate-500">{kc.tokens} tokens</span>
                    </div>
                    <p className="text-xs text-slate-600 font-mono leading-relaxed">{kc.chunk}</p>
                  </div>
                ))}
              </div>
            </div>

            {/* Triage Ticket Simulator */}
            <div className="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs">
              <h3 className="text-sm font-bold text-slate-900 mb-1">Mô phỏng Phân loại Sự cố Tự động (AI Triage Simulator)</h3>
              <p className="text-xs text-slate-500 mb-4">Phân tích mô tả sự cố bằng AI để tự động gán nhãn chuyên môn và mức độ khẩn cấp</p>
              <div className="p-4 rounded-xl bg-slate-900 text-slate-100 font-mono text-xs space-y-2 border border-slate-800">
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
            <div className="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs">
              <div className="flex items-center justify-between mb-4">
                <div>
                  <h3 className="text-sm font-bold text-slate-900">Nhật ký Inbound Webhooks</h3>
                  <p className="text-xs text-slate-500">Các sự kiện nhận được từ đối tác thanh toán và thiết bị phần cứng</p>
                </div>
                <button
                  type="button"
                  className="px-3 py-1.5 rounded-lg text-xs font-semibold border border-slate-200 bg-slate-50 hover:bg-slate-100 text-slate-800 transition-colors"
                >
                  Giả lập Webhook mới
                </button>
              </div>

              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b border-slate-200 text-slate-400 font-bold font-mono uppercase tracking-wider">
                      <th className="py-2.5 px-3">Mã ID</th>
                      <th className="py-2.5 px-3">Nhà cung cấp</th>
                      <th className="py-2.5 px-3">Loại sự kiện</th>
                      <th className="py-2.5 px-3">Mã trạng thái</th>
                      <th className="py-2.5 px-3">Thời gian</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {webhookLogs.map((log) => (
                      <tr key={log.id} className="hover:bg-slate-50/80 transition-colors">
                        <td className="py-2.5 px-3 font-mono font-semibold text-slate-900">{log.id}</td>
                        <td className="py-2.5 px-3 font-semibold text-indigo-700">{log.provider}</td>
                        <td className="py-2.5 px-3 font-mono text-slate-600">{log.event}</td>
                        <td className="py-2.5 px-3 font-mono text-emerald-600 font-bold">{log.status}</td>
                        <td className="py-2.5 px-3 text-slate-400 font-mono">{log.time}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>

            {/* IoT Simulator */}
            <div className="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs">
              <h3 className="text-sm font-bold text-slate-900 mb-1">Mô phỏng Kết nối Thiết bị IoT Tòa nhà</h3>
              <p className="text-xs text-slate-500 mb-4">Mô phỏng luồng telemetry từ đồng hồ nước và cổng an ninh</p>
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div className="p-4 rounded-xl border border-emerald-200 bg-emerald-50/40">
                  <div className="flex items-center justify-between mb-2">
                    <span className="text-xs font-bold text-emerald-900">Đồng hồ Nước Khu A</span>
                    <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />
                  </div>
                  <div className="text-lg font-extrabold text-emerald-700">1,248.5 m³</div>
                  <div className="text-[10px] text-emerald-800 mt-1">Giao thức: MQTT Broker • Online</div>
                </div>

                <div className="p-4 rounded-xl border border-indigo-200 bg-indigo-50/40">
                  <div className="flex items-center justify-between mb-2">
                    <span className="text-xs font-bold text-indigo-900">Tủ đồ Smart Locker sảnh B</span>
                    <span className="w-2 h-2 rounded-full bg-indigo-500 animate-pulse" />
                  </div>
                  <div className="text-lg font-extrabold text-indigo-700">12/16 Ngăn trống</div>
                  <div className="text-[10px] text-indigo-800 mt-1">Cổng điều khiển: TCP Relay • Sẵn sàng</div>
                </div>

                <div className="p-4 rounded-xl border border-amber-200 bg-amber-50/40">
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
            <div className="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs">
              <div className="mb-4">
                <h3 className="text-sm font-bold text-slate-900">Cờ tính năng thời gian thực (Dynamic Feature Flags)</h3>
                <p className="text-xs text-slate-500">Bật tắt các module thử nghiệm tức thời mà không cần can thiệp mã nguồn hay deploy lại</p>
              </div>

              <div className="divide-y divide-slate-100">
                {featureFlags.map((ff) => (
                  <div key={ff.key} className="py-3 flex items-center justify-between">
                    <div>
                      <div className="text-xs font-bold text-slate-900 flex items-center gap-2">
                        <span>{ff.name}</span>
                        <span className="px-1.5 py-0.5 rounded text-[10px] font-mono bg-slate-100 text-slate-700 border border-slate-200">
                          {ff.key}
                        </span>
                      </div>
                      <div className="text-[11px] text-slate-500 mt-0.5">{ff.desc}</div>
                    </div>

                    <button
                      type="button"
                      onClick={() => toggleFeatureFlag(ff.key)}
                      className={`w-11 h-6 flex items-center rounded-full p-1 transition-colors cursor-pointer ${
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
            <div className="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs">
              <h3 className="text-sm font-bold text-slate-900 mb-1">Nhật ký Kiểm toán Hệ thống (System Audit Trail)</h3>
              <p className="text-xs text-slate-500 mb-4">Ghi nhận mọi thao tác thay đổi dữ liệu theo chuẩn Change Data Capture (CDC)</p>
              <div className="p-3 bg-slate-50 border border-slate-200 rounded-xl font-mono text-xs text-slate-700 space-y-1.5">
                <div className="text-emerald-700">[2026-09-24 08:00:01] USER (dev@cassavas.vn) UPDATED feature_flag: ENABLE_AI_TICKET_TRIAGE = true</div>
                <div className="text-indigo-700">[2026-09-24 07:30:15] CRON_JOB executed batch_generate_invoices for period: 2026-09 (Success: 120 invoices)</div>
                <div className="text-slate-600">[2026-09-24 06:15:22] ROLE_PERMISSION modified by admin@cassavas.vn: Granted permission "amenity.approve"</div>
              </div>
            </div>
          </div>
        )}

        {/* TAB 5: CI/CD & DEVOPS */}
        {activeTab === 'cicd' && (
          <div className="space-y-6">
            <CicdDashboard userRole="admin" />
          </div>
        )}
      </div>
    </AppLayout>
  );
};
