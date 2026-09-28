import React, { useEffect, useState } from 'react';
import {
  CheckCircle,
  AlertTriangle,
  XCircle,
  Clock,
  ArrowLeft,
  RefreshCw,
  ShieldCheck,
  Server,
  Activity,
  Calendar,
  ChevronRight
} from 'lucide-react';

interface ServiceItem {
  name: string;
  status: 'operational' | 'degraded' | 'outage';
  description: string;
}

interface PublicStatusData {
  title: string;
  overall_status: 'operational' | 'degraded' | 'outage';
  uptime_percentage: string;
  services: ServiceItem[];
  last_incident: {
    date: string;
    title: string;
    status: string;
    downtime: string;
  };
  updated_at: string;
}

interface PublicStatusPageProps {
  onBackHome?: () => void;
  onNavigateIncidents?: () => void;
}

const PublicStatusPage: React.FC<PublicStatusPageProps> = ({
  onBackHome = () => (window.location.href = '/home'),
  onNavigateIncidents = () => (window.location.href = '/status/incidents')
}) => {
  const [data, setData] = useState<PublicStatusData | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [refreshing, setRefreshing] = useState<boolean>(false);

  const fetchStatus = async () => {
    try {
      const res = await fetch('/api/public/status');
      if (res.ok) {
        const json = await res.json();
        setData(json);
      }
    } catch (err) {
      console.error('Không thể tải trạng thái hệ thống công khai:', err);
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  };

  useEffect(() => {
    fetchStatus();
    const interval = setInterval(fetchStatus, 30000);
    return () => clearInterval(interval);
  }, []);

  const handleRefresh = () => {
    setRefreshing(true);
    fetchStatus();
  };

  const getStatusBadge = (status: 'operational' | 'degraded' | 'outage') => {
    switch (status) {
      case 'operational':
        return (
          <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
            <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
            Hoạt động bình thường
          </span>
        );
      case 'degraded':
        return (
          <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-200">
            <span className="w-2 h-2 rounded-full bg-amber-500"></span>
            Hiệu năng giảm nhẹ
          </span>
        );
      case 'outage':
        return (
          <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-rose-50 text-rose-700 border border-rose-200">
            <span className="w-2 h-2 rounded-full bg-rose-500"></span>
            Tạm thời gián đoạn
          </span>
        );
    }
  };

  return (
    <div className="min-h-screen bg-[#F8FAFC] text-slate-900 font-sans pb-16">
      {/* Top Header */}
      <header className="bg-white border-b border-slate-200 sticky top-0 z-30">
        <div className="max-w-5xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <button
              onClick={onBackHome}
              className="inline-flex items-center gap-1.5 text-sm text-slate-600 hover:text-slate-900 transition-colors py-1.5 px-3 rounded-lg hover:bg-slate-100"
            >
              <ArrowLeft className="w-4 h-4" />
              <span>Trang chủ</span>
            </button>
            <div className="h-5 w-px bg-slate-200 hidden sm:block"></div>
            <div className="flex items-center gap-2">
              <div className="w-7 h-7 rounded-lg bg-emerald-600 flex items-center justify-center text-white font-bold text-xs shadow-sm">
                SC
              </div>
              <span className="font-bold text-slate-900 text-sm tracking-tight">
                SMART CASSAVAS STATUS
              </span>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <button
              onClick={handleRefresh}
              disabled={refreshing}
              className="inline-flex items-center gap-1.5 text-xs text-slate-600 hover:text-slate-900 py-1.5 px-3 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 transition-all disabled:opacity-50"
              title="Làm mới trạng thái"
            >
              <RefreshCw className={`w-3.5 h-3.5 ${refreshing ? 'animate-spin text-emerald-600' : ''}`} />
              <span className="hidden sm:inline">Cập nhật</span>
            </button>

            <button
              onClick={onNavigateIncidents}
              className="inline-flex items-center gap-1 text-xs font-semibold text-emerald-700 bg-emerald-50 hover:bg-emerald-100 py-1.5 px-3 rounded-lg border border-emerald-200 transition-colors"
            >
              <Calendar className="w-3.5 h-3.5" />
              <span>Lịch sử sự cố</span>
              <ChevronRight className="w-3 h-3 ml-0.5" />
            </button>
          </div>
        </div>
      </header>

      {/* Main Content Area */}
      <main className="max-w-5xl mx-auto px-4 sm:px-6 pt-8">
        {/* Big Status Banner */}
        <div className="mb-8">
          {loading ? (
            <div className="bg-white rounded-2xl p-8 border border-slate-200 shadow-sm animate-pulse flex items-center gap-4">
              <div className="w-12 h-12 rounded-full bg-slate-200"></div>
              <div className="space-y-2 flex-1">
                <div className="h-5 bg-slate-200 rounded w-1/3"></div>
                <div className="h-4 bg-slate-100 rounded w-1/2"></div>
              </div>
            </div>
          ) : data?.overall_status === 'operational' ? (
            <div className="bg-gradient-to-r from-emerald-600 to-teal-600 rounded-2xl p-6 sm:p-8 text-white shadow-lg shadow-emerald-600/10 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-6">
              <div className="flex items-center gap-4">
                <div className="w-12 h-12 rounded-xl bg-white/20 backdrop-blur-sm flex items-center justify-center flex-shrink-0">
                  <ShieldCheck className="w-7 h-7 text-white" />
                </div>
                <div>
                  <h1 className="text-xl sm:text-2xl font-extrabold tracking-tight">
                    Tất cả hệ thống hoạt động bình thường
                  </h1>
                  <p className="text-emerald-100 text-sm mt-1">
                    Cổng dịch vụ cư dân, ban quản lý và cơ sở dữ liệu đạt độ sẵn sàng tối ưu.
                  </p>
                </div>
              </div>
              <div className="flex items-center gap-6 bg-white/10 backdrop-blur-md px-5 py-3 rounded-xl border border-white/10 self-stretch sm:self-auto justify-between sm:justify-start">
                <div>
                  <div className="text-xs uppercase tracking-wider text-emerald-200 font-semibold">Uptime 90 ngày</div>
                  <div className="text-2xl font-black text-white">{data?.uptime_percentage || '99.98%'}</div>
                </div>
                <div className="h-8 w-px bg-white/20"></div>
                <div>
                  <div className="text-xs uppercase tracking-wider text-emerald-200 font-semibold">Chu kỳ giám sát</div>
                  <div className="text-sm font-bold text-white">24/7 Tự động</div>
                </div>
              </div>
            </div>
          ) : (
            <div className="bg-gradient-to-r from-amber-600 to-orange-600 rounded-2xl p-6 sm:p-8 text-white shadow-lg shadow-amber-600/10 flex items-center gap-4">
              <AlertTriangle className="w-10 h-10 text-white flex-shrink-0" />
              <div>
                <h1 className="text-xl sm:text-2xl font-extrabold">Hệ thống đang được bảo trì hoặc theo dõi</h1>
                <p className="text-amber-100 text-sm mt-1">Một số dịch vụ có thể phản hồi chậm hơn bình thường.</p>
              </div>
            </div>
          )}
        </div>

        {/* Services Status Card Group */}
        <div className="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden mb-8">
          <div className="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <h2 className="text-base font-bold text-slate-800 flex items-center gap-2">
              <Server className="w-4 h-4 text-emerald-600" />
              Tình Trạng Chi Tiết Dịch Vụ
            </h2>
            <span className="text-xs text-slate-500">
              Cập nhật lúc: {data?.updated_at ? new Date(data.updated_at).toLocaleTimeString('vi-VN') : 'Vừa xong'}
            </span>
          </div>

          <div className="divide-y divide-slate-100">
            {data?.services.map((service, index) => (
              <div
                key={index}
                className="px-6 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 hover:bg-slate-50/70 transition-colors"
              >
                <div>
                  <div className="text-sm font-semibold text-slate-900">{service.name}</div>
                  <div className="text-xs text-slate-500 mt-0.5">{service.description}</div>
                </div>
                <div className="flex-shrink-0 self-start sm:self-auto">
                  {getStatusBadge(service.status)}
                </div>
              </div>
            ))}
          </div>
        </div>

        {/* Recent Incident Summary */}
        {data?.last_incident && (
          <div className="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm">
            <div className="flex items-center gap-2 mb-4">
              <Activity className="w-4 h-4 text-slate-600" />
              <h2 className="text-base font-bold text-slate-800">Hoạt Động Bảo Trì / Sự Cố Gần Nhất</h2>
            </div>

            <div className="bg-slate-50 rounded-xl p-4 border border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
              <div className="space-y-1">
                <div className="flex items-center gap-2">
                  <span className="text-xs font-bold text-slate-500 bg-slate-200 px-2 py-0.5 rounded">
                    {data.last_incident.date}
                  </span>
                  <span className="text-sm font-semibold text-slate-800">{data.last_incident.title}</span>
                </div>
                <div className="text-xs text-slate-500">{data.last_incident.downtime}</div>
              </div>

              <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-md text-xs font-semibold bg-emerald-100 text-emerald-800 self-start sm:self-auto">
                <CheckCircle className="w-3.5 h-3.5" />
                {data.last_incident.status}
              </span>
            </div>
          </div>
        )}

        {/* Footer */}
        <footer className="mt-12 text-center text-xs text-slate-500 space-y-2">
          <p>Hệ thống Quản lý Tòa nhà Thông minh SMART CASSAVAS – DevOps Monitoring & Observability</p>
          <p className="text-slate-400">Trang trạng thái công khai không lưu vết hoặc tiết lộ thông số máy chủ nội bộ.</p>
        </footer>
      </main>
    </div>
  );
};

export default PublicStatusPage;
