import React, { useEffect, useState } from 'react';
import { ArrowLeft, Calendar, ShieldCheck, CheckCircle2 } from 'lucide-react';

interface IncidentRecord {
  date: string;
  service: string;
  issue: string;
  start: string;
  resolved: string;
  duration: string;
  status: string;
}

interface IncidentHistoryPageProps {
  onBackStatus?: () => void;
  onBackHome?: () => void;
}

const IncidentHistoryPage: React.FC<IncidentHistoryPageProps> = ({
  onBackStatus = () => (window.location.href = '/status'),
  onBackHome = () => (window.location.href = '/home')
}) => {
  const [incidents, setIncidents] = useState<IncidentRecord[]>([]);
  const [loading, setLoading] = useState<boolean>(true);

  useEffect(() => {
    fetch('/api/public/incidents')
      .then((res) => res.json())
      .then((json) => {
        if (json.data) {
          setIncidents(json.data);
        }
      })
      .catch((err) => console.error('Lỗi khi tải dữ liệu sự cố:', err))
      .finally(() => setLoading(false));
  }, []);

  return (
    <div className="min-h-screen bg-[#F8FAFC] text-slate-900 font-sans pb-16">
      <header className="bg-white border-b border-slate-200 sticky top-0 z-30">
        <div className="max-w-5xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <button
              onClick={onBackStatus}
              className="inline-flex items-center gap-1.5 text-sm text-slate-600 hover:text-slate-900 transition-colors py-1.5 px-3 rounded-lg hover:bg-slate-100"
            >
              <ArrowLeft className="w-4 h-4" />
              <span>Trạng thái hệ thống</span>
            </button>
            <div className="h-5 w-px bg-slate-200 hidden sm:block"></div>
            <span className="font-bold text-slate-900 text-sm tracking-tight">
              LỊCH SỬ BẢO TRÌ & SỰ CỐ
            </span>
          </div>

          <button
            onClick={onBackHome}
            className="text-xs text-slate-500 hover:text-slate-800 transition-colors"
          >
            Về trang chủ
          </button>
        </div>
      </header>

      <main className="max-w-5xl mx-auto px-4 sm:px-6 pt-8">
        <div className="mb-6">
          <h1 className="text-2xl font-extrabold text-slate-900 flex items-center gap-2.5">
            <Calendar className="w-6 h-6 text-emerald-600" />
            Nhật Ký Bảo Trì & Sự Cố Hệ Thống
          </h1>
          <p className="text-slate-500 text-sm mt-1">
            Bản ghi công khai tình trạng hoạt động, bảo dưỡng định kỳ và khắc phục sự cố của nền tảng Smart Cassavas.
          </p>
        </div>

        <div className="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
          {loading ? (
            <div className="p-8 text-center text-slate-500 animate-pulse">
              Đang tải lịch sử sự cố...
            </div>
          ) : incidents.length === 0 ? (
            <div className="p-12 text-center">
              <ShieldCheck className="w-12 h-12 text-emerald-500 mx-auto mb-3" />
              <h3 className="text-base font-bold text-slate-800">Không có sự cố nào được ghi nhận</h3>
              <p className="text-xs text-slate-500 mt-1">Hệ thống luôn duy trì trạng thái ổn định 100%.</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-left border-collapse">
                <thead>
                  <tr className="bg-slate-50 text-slate-600 text-xs uppercase tracking-wider font-semibold border-b border-slate-200">
                    <th className="py-3 px-6">Ngày</th>
                    <th className="py-3 px-6">Dịch vụ</th>
                    <th className="py-3 px-6">Nội dung</th>
                    <th className="py-3 px-6">Bắt đầu</th>
                    <th className="py-3 px-6">Khắc phục</th>
                    <th className="py-3 px-6">Thời lượng</th>
                    <th className="py-3 px-6 text-right">Trạng thái</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 text-sm text-slate-700">
                  {incidents.map((inc, i) => (
                    <tr key={i} className="hover:bg-slate-50/70 transition-colors">
                      <td className="py-4 px-6 font-medium text-slate-900 whitespace-nowrap">
                        {inc.date}
                      </td>
                      <td className="py-4 px-6 whitespace-nowrap">
                        <span className="px-2 py-0.5 rounded text-xs font-semibold bg-slate-100 text-slate-700">
                          {inc.service}
                        </span>
                      </td>
                      <td className="py-4 px-6 font-medium text-slate-800">
                        {inc.issue}
                      </td>
                      <td className="py-4 px-6 text-slate-500 font-mono text-xs whitespace-nowrap">
                        {inc.start}
                      </td>
                      <td className="py-4 px-6 text-slate-500 font-mono text-xs whitespace-nowrap">
                        {inc.resolved}
                      </td>
                      <td className="py-4 px-6 text-slate-500 text-xs whitespace-nowrap">
                        {inc.duration}
                      </td>
                      <td className="py-4 px-6 text-right whitespace-nowrap">
                        <span className="inline-flex items-center gap-1 text-xs font-semibold text-emerald-700 bg-emerald-50 px-2.5 py-1 rounded-md border border-emerald-200">
                          <CheckCircle2 className="w-3.5 h-3.5" />
                          {inc.status}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </main>
    </div>
  );
};

export default IncidentHistoryPage;
