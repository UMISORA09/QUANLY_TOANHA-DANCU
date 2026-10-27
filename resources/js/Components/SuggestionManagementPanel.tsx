import React, { useState, useEffect } from 'react';
import {
  MessageSquare,
  Search,
  Filter,
  CheckCircle2,
  Clock,
  AlertCircle,
  X,
  RefreshCw,
  Send,
  Shield,
  User,
  Building,
  Calendar,
  Sparkles,
  Bot,
  AlertTriangle,
  ChevronLeft,
  ChevronRight,
  Eye,
  Check,
  Plus,
} from 'lucide-react';
import api, {
  SuggestionItem,
  SuggestionBoxItem,
  SuggestionKpis,
} from '@/Services/api';

const TOPIC_LABELS: Record<string, string> = {
  AN_NINH: 'An ninh & Trật tự',
  VE_SINH: 'Vệ sinh môi trường',
  BAI_XE: 'Bãi đỗ xe & Phương tiện',
  TIEN_ICH: 'Tiện ích & Dịch vụ chung',
  THAI_DO_NHAN_VIEN: 'Thái độ nhân viên',
  KHAC: 'Ý kiến khác',
};

export const SuggestionManagementPanel: React.FC = () => {
  // State
  const [suggestions, setSuggestions] = useState<SuggestionItem[]>([]);
  const [kpis, setKpis] = useState<SuggestionKpis>({
    total: 0,
    received: 0,
    reviewing: 0,
    accepted: 0,
    resolved: 0,
    rejected: 0,
    negative_sentiment: 0,
  });
  const [boxes, setBoxes] = useState<SuggestionBoxItem[]>([]);
  const [isLoading, setIsLoading] = useState<boolean>(true);

  // Filters
  const [search, setSearch] = useState<string>('');
  const [filterStatus, setFilterStatus] = useState<string>('');
  const [filterTopic, setFilterTopic] = useState<string>('');
  const [filterBoxId, setFilterBoxId] = useState<string>('');
  const [filterSentiment, setFilterSentiment] = useState<string>('');
  const [filterAnonymous, setFilterAnonymous] = useState<string>('');
  const [currentPage, setCurrentPage] = useState<number>(1);
  const [totalPages, setTotalPages] = useState<number>(1);
  const [totalRecords, setTotalRecords] = useState<number>(0);

  // Selected Suggestion for Detail & Actions
  const [selectedSuggestion, setSelectedSuggestion] = useState<SuggestionItem | null>(null);
  const [isLoadingDetail, setIsLoadingDetail] = useState<boolean>(false);

  // Action / Reply State
  const [replyText, setReplyText] = useState<string>('');
  const [newStatusOnReply, setNewStatusOnReply] = useState<string>('RESOLVED');
  const [isSubmittingReply, setIsSubmittingReply] = useState<boolean>(false);
  const [rejectionReason, setRejectionReason] = useState<string>('');
  const [showRejectionInput, setShowRejectionInput] = useState<boolean>(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [actionSuccess, setActionSuccess] = useState<string | null>(null);
  const [occConflict, setOccConflict] = useState<boolean>(false);

  // AI Sentiment State
  const [isAnalyzingAi, setIsAnalyzingAi] = useState<boolean>(false);
  const [aiAnalysisResult, setAiAnalysisResult] = useState<string | null>(null);

  // Fetch list
  useEffect(() => {
    loadSuggestions();
  }, [filterStatus, filterTopic, filterBoxId, filterSentiment, filterAnonymous, currentPage]);

  // Load boxes once
  useEffect(() => {
    api.getResidentSuggestionBoxes().then((res) => {
      if (res?.data) setBoxes(res.data);
    }).catch((err) => console.warn('Lỗi tải danh mục hòm thư:', err));
  }, []);

  const loadSuggestions = async () => {
    try {
      setIsLoading(true);
      const params: Record<string, any> = {
        page: currentPage,
        per_page: 15,
      };

      if (search.trim()) params.search = search.trim();
      if (filterStatus) params.status = filterStatus;
      if (filterTopic) params.topic_category = filterTopic;
      if (filterBoxId) params.box_id = filterBoxId;
      if (filterSentiment) params.ai_sentiment = filterSentiment;
      if (filterAnonymous !== '') params.is_anonymous = filterAnonymous;

      const res = await api.getManagementSuggestions(params);
      if (res?.data) {
        setSuggestions(res.data);
        if (res.kpis) setKpis(res.kpis);
        if (res.pagination) {
          setTotalPages(res.pagination.last_page);
          setTotalRecords(res.pagination.total);
        }
      }
    } catch (err) {
      console.warn('Lỗi khi tải danh sách góp ý:', err);
    } finally {
      setIsLoading(false);
    }
  };

  const handleSearchSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setCurrentPage(1);
    loadSuggestions();
  };

  const openDetailModal = async (id: string) => {
    try {
      setIsLoadingDetail(true);
      setActionError(null);
      setActionSuccess(null);
      setOccConflict(false);
      setShowRejectionInput(false);
      setRejectionReason('');
      setAiAnalysisResult(null);

      const res = await api.getManagementSuggestionDetail(id);
      if (res?.data) {
        setSelectedSuggestion(res.data);
        setReplyText(res.data.official_response_text || '');
        setNewStatusOnReply(res.data.status === 'RESOLVED' ? 'RESOLVED' : 'RESOLVED');
      }
    } catch (err: any) {
      alert(err?.response?.data?.message || 'Không thể tải chi tiết góp ý.');
    } finally {
      setIsLoadingDetail(false);
    }
  };

  // State Transition Action (OCC version protected)
  const handleStatusTransition = async (newStatus: string) => {
    if (!selectedSuggestion) return;

    if (newStatus === 'REJECTED' && !showRejectionInput) {
      setShowRejectionInput(true);
      return;
    }

    if (newStatus === 'REJECTED' && !rejectionReason.trim()) {
      setActionError('Vui lòng nhập lý do từ chối góp ý.');
      return;
    }

    try {
      setActionError(null);
      setActionSuccess(null);
      setOccConflict(false);

      const res = await api.updateSuggestionStatus(selectedSuggestion.id, {
        status: newStatus,
        version: selectedSuggestion.version,
        rejection_reason: newStatus === 'REJECTED' ? rejectionReason.trim() : undefined,
      });

      if (res?.success) {
        setSelectedSuggestion(res.data);
        setActionSuccess(`Đã chuyển trạng thái sang "${getStatusText(newStatus)}".`);
        setShowRejectionInput(false);
        loadSuggestions();
      }
    } catch (err: any) {
      if (err?.response?.status === 409) {
        setOccConflict(true);
      } else {
        setActionError(err?.response?.data?.message || 'Không thể cập nhật trạng thái.');
      }
    }
  };

  // Submit Official Reply (OCC protected)
  const handleReplySubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedSuggestion) return;

    if (!replyText.trim()) {
      setActionError('Vui lòng nhập nội dung phản hồi.');
      return;
    }

    try {
      setIsSubmittingReply(true);
      setActionError(null);
      setActionSuccess(null);
      setOccConflict(false);

      const res = await api.replySuggestion(selectedSuggestion.id, {
        official_response_text: replyText.trim(),
        version: selectedSuggestion.version,
        status: newStatusOnReply,
      });

      if (res?.success) {
        setSelectedSuggestion(res.data);
        setActionSuccess('Gửi phản hồi chính thức thành công!');
        loadSuggestions();
      }
    } catch (err: any) {
      if (err?.response?.status === 409) {
        setOccConflict(true);
      } else {
        setActionError(err?.response?.data?.message || 'Không thể gửi phản hồi.');
      }
    } finally {
      setIsSubmittingReply(false);
    }
  };

  // Analyze Sentiment with AI
  const handleAnalyzeSentiment = async () => {
    if (!selectedSuggestion) return;
    try {
      setIsAnalyzingAi(true);
      setAiAnalysisResult(null);

      const res = await api.analyzeSuggestionSentiment(selectedSuggestion.id);
      if (res?.success && res.data) {
        setSelectedSuggestion(res.data);
        setAiAnalysisResult(`Đã phân tích cảm xúc: ${res.data.ai_sentiment} (Điểm: ${res.data.ai_sentiment_score})`);
        loadSuggestions();
      }
    } catch (err: any) {
      const msg = err?.response?.data?.message || 'Không thể thực hiện phân tích cảm xúc AI.';
      setAiAnalysisResult(msg);
    } finally {
      setIsAnalyzingAi(false);
    }
  };

  const reloadCurrentDetail = () => {
    if (selectedSuggestion) {
      openDetailModal(selectedSuggestion.id);
    }
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'RECEIVED':
        return <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-200"><Clock className="w-3 h-3" /> Chờ tiếp nhận</span>;
      case 'REVIEWING':
        return <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-50 text-blue-700 border border-blue-200"><RefreshCw className="w-3 h-3" /> Đang xem xét</span>;
      case 'ACCEPTED':
        return <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-indigo-50 text-indigo-700 border border-indigo-200"><Check className="w-3 h-3" /> Đã tiếp nhận</span>;
      case 'RESOLVED':
        return <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200"><CheckCircle2 className="w-3 h-3" /> Đã giải quyết</span>;
      case 'REJECTED':
        return <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rose-50 text-rose-700 border border-rose-200"><X className="w-3 h-3" /> Từ chối</span>;
      default:
        return <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-neutral-100 text-neutral-700">{status}</span>;
    }
  };

  const getStatusText = (status: string) => {
    switch (status) {
      case 'RECEIVED': return 'Chờ tiếp nhận';
      case 'REVIEWING': return 'Đang xem xét';
      case 'ACCEPTED': return 'Đã tiếp nhận xử lý';
      case 'RESOLVED': return 'Đã giải quyết';
      case 'REJECTED': return 'Từ chối';
      default: return status;
    }
  };

  const getSentimentBadge = (sentiment: string, score?: number | null) => {
    switch (sentiment) {
      case 'POSITIVE':
        return <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-semibold bg-emerald-100 text-emerald-800">Tích cực ({score ?? '+0.8'})</span>;
      case 'NEUTRAL':
        return <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-semibold bg-neutral-100 text-neutral-700">Trung tính</span>;
      case 'NEGATIVE':
        return <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-semibold bg-amber-100 text-amber-800">Tiêu cực ({score ?? '-0.5'})</span>;
      case 'VERY_ANGRY':
        return <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-semibold bg-rose-100 text-rose-800 font-bold">Bức xúc cao độ ({score ?? '-0.9'})</span>;
      case 'NOT_ANALYZED':
      default:
        return <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-medium bg-neutral-100 text-neutral-500 italic">Chưa phân tích</span>;
    }
  };

  return (
    <div className="space-y-6">
      {/* Top Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <div className="flex items-center gap-2">
            <span className="p-2 rounded-xl bg-emerald-50 text-emerald-700">
              <MessageSquare className="w-5 h-5" />
            </span>
            <h1 className="text-xl sm:text-2xl font-extrabold text-neutral-900 tracking-tight">
              Quản Lý Hòm Thư Góp Ý & Ý Kiến Cư Dân
            </h1>
          </div>
          <p className="text-xs sm:text-sm text-neutral-500 mt-1">
            Tiếp nhận, xử lý, phân loại cảm xúc và phản hồi chính thức ý kiến đóng góp của cư dân.
          </p>
        </div>

        <button
          onClick={loadSuggestions}
          className="inline-flex items-center gap-2 px-4 py-2 rounded-xl border border-neutral-200 text-neutral-700 bg-white hover:bg-neutral-50 text-xs font-semibold shadow-sm transition-colors cursor-pointer"
        >
          <RefreshCw className="w-3.5 h-3.5" />
          Làm mới dữ liệu
        </button>
      </div>

      {/* KPI Cards Grid */}
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 sm:gap-4">
        <div className="p-4 rounded-2xl bg-white border border-neutral-200 shadow-sm space-y-1">
          <p className="text-xs font-medium text-neutral-500">Tổng số góp ý</p>
          <p className="text-2xl font-extrabold text-neutral-900 font-mono">{kpis.total}</p>
        </div>
        <div className="p-4 rounded-2xl bg-white border border-amber-200 shadow-sm space-y-1 bg-amber-50/20">
          <p className="text-xs font-medium text-amber-700 flex items-center gap-1">
            <Clock className="w-3.5 h-3.5" /> Chờ tiếp nhận
          </p>
          <p className="text-2xl font-extrabold text-amber-800 font-mono">{kpis.received}</p>
        </div>
        <div className="p-4 rounded-2xl bg-white border border-blue-200 shadow-sm space-y-1 bg-blue-50/20">
          <p className="text-xs font-medium text-blue-700 flex items-center gap-1">
            <RefreshCw className="w-3.5 h-3.5" /> Đang xem xét
          </p>
          <p className="text-2xl font-extrabold text-blue-800 font-mono">{kpis.reviewing}</p>
        </div>
        <div className="p-4 rounded-2xl bg-white border border-indigo-200 shadow-sm space-y-1 bg-indigo-50/20">
          <p className="text-xs font-medium text-indigo-700 flex items-center gap-1">
            <Check className="w-3.5 h-3.5" /> Đã tiếp nhận
          </p>
          <p className="text-2xl font-extrabold text-indigo-800 font-mono">{kpis.accepted}</p>
        </div>
        <div className="p-4 rounded-2xl bg-white border border-emerald-200 shadow-sm space-y-1 bg-emerald-50/20">
          <p className="text-xs font-medium text-emerald-700 flex items-center gap-1">
            <CheckCircle2 className="w-3.5 h-3.5" /> Đã giải quyết
          </p>
          <p className="text-2xl font-extrabold text-emerald-800 font-mono">{kpis.resolved}</p>
        </div>
        <div className="p-4 rounded-2xl bg-white border border-rose-200 shadow-sm space-y-1 bg-rose-50/20">
          <p className="text-xs font-medium text-rose-700 flex items-center gap-1">
            <AlertTriangle className="w-3.5 h-3.5" /> Bức xúc / Tiêu cực
          </p>
          <p className="text-2xl font-extrabold text-rose-800 font-mono">{kpis.negative_sentiment}</p>
        </div>
      </div>

      {/* Filter Toolbar */}
      <div className="bg-white rounded-2xl border border-neutral-200 p-4 shadow-sm space-y-3">
        <form onSubmit={handleSearchSubmit} className="flex flex-col md:flex-row gap-3">
          <div className="relative flex-1">
            <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-neutral-400">
              <Search className="w-4 h-4" />
            </div>
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Tìm kiếm theo tiêu đề hoặc nội dung góp ý..."
              className="w-full pl-10 pr-4 py-2.5 rounded-xl border border-neutral-300 text-sm focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition-colors"
            />
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <select
              value={filterStatus}
              onChange={(e) => { setFilterStatus(e.target.value); setCurrentPage(1); }}
              className="px-3 py-2.5 rounded-xl border border-neutral-300 text-xs font-medium bg-white text-neutral-700"
            >
              <option value="">Trạng thái: Tất cả</option>
              <option value="RECEIVED">Chờ tiếp nhận</option>
              <option value="REVIEWING">Đang xem xét</option>
              <option value="ACCEPTED">Đã tiếp nhận xử lý</option>
              <option value="RESOLVED">Đã giải quyết</option>
              <option value="REJECTED">Từ chối</option>
            </select>

            <select
              value={filterTopic}
              onChange={(e) => { setFilterTopic(e.target.value); setCurrentPage(1); }}
              className="px-3 py-2.5 rounded-xl border border-neutral-300 text-xs font-medium bg-white text-neutral-700"
            >
              <option value="">Chủ đề: Tất cả</option>
              {Object.entries(TOPIC_LABELS).map(([k, v]) => (
                <option key={k} value={k}>{v}</option>
              ))}
            </select>

            <select
              value={filterBoxId}
              onChange={(e) => { setFilterBoxId(e.target.value); setCurrentPage(1); }}
              className="px-3 py-2.5 rounded-xl border border-neutral-300 text-xs font-medium bg-white text-neutral-700 max-w-[180px] truncate"
            >
              <option value="">Hòm thư: Tất cả</option>
              {boxes.map((b) => (
                <option key={b.id} value={b.id}>{b.box_name}</option>
              ))}
            </select>

            <select
              value={filterSentiment}
              onChange={(e) => { setFilterSentiment(e.target.value); setCurrentPage(1); }}
              className="px-3 py-2.5 rounded-xl border border-neutral-300 text-xs font-medium bg-white text-neutral-700"
            >
              <option value="">Cảm xúc AI: Tất cả</option>
              <option value="POSITIVE">Tích cực</option>
              <option value="NEUTRAL">Trung tính</option>
              <option value="NEGATIVE">Tiêu cực</option>
              <option value="VERY_ANGRY">Bức xúc cao độ</option>
              <option value="NOT_ANALYZED">Chưa phân tích</option>
            </select>

            <select
              value={filterAnonymous}
              onChange={(e) => { setFilterAnonymous(e.target.value); setCurrentPage(1); }}
              className="px-3 py-2.5 rounded-xl border border-neutral-300 text-xs font-medium bg-white text-neutral-700"
            >
              <option value="">Danh tính: Tất cả</option>
              <option value="0">Có danh tính</option>
              <option value="1">Ẩn danh</option>
            </select>

            <button
              type="submit"
              className="px-4 py-2.5 rounded-xl bg-neutral-900 hover:bg-neutral-800 text-white text-xs font-semibold shadow-sm transition-colors cursor-pointer"
            >
              Lọc
            </button>
          </div>
        </form>
      </div>

      {/* Suggestion Table */}
      <div className="bg-white rounded-2xl border border-neutral-200 overflow-hidden shadow-sm">
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="bg-neutral-50/80 text-neutral-600 text-xs uppercase font-semibold border-b border-neutral-200">
              <tr>
                <th className="px-4 py-3.5">Tiêu đề & Nội dung</th>
                <th className="px-4 py-3.5">Chủ đề</th>
                <th className="px-4 py-3.5">Hòm thư</th>
                <th className="px-4 py-3.5">Người gửi</th>
                <th className="px-4 py-3.5">Cảm xúc AI</th>
                <th className="px-4 py-3.5">Trạng thái</th>
                <th className="px-4 py-3.5">Thời gian</th>
                <th className="px-4 py-3.5 text-right">Thao tác</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-neutral-100">
              {isLoading ? (
                <tr>
                  <td colSpan={8} className="py-12 text-center text-neutral-400">
                    <RefreshCw className="w-6 h-6 animate-spin mx-auto text-emerald-600 mb-2" />
                    Đang tải dữ liệu góp ý...
                  </td>
                </tr>
              ) : suggestions.length === 0 ? (
                <tr>
                  <td colSpan={8} className="py-12 text-center text-neutral-400">
                    Không có góp ý nào phù hợp với bộ lọc hiện tại.
                  </td>
                </tr>
              ) : (
                suggestions.map((item) => (
                  <tr key={item.id} className="hover:bg-neutral-50/60 transition-colors">
                    <td className="px-4 py-3.5 max-w-xs">
                      <div className="font-bold text-neutral-900 truncate hover:text-emerald-700 cursor-pointer" onClick={() => openDetailModal(item.id)}>
                        {item.title}
                      </div>
                      <p className="text-xs text-neutral-500 line-clamp-1 mt-0.5">
                        {item.content_text}
                      </p>
                    </td>
                    <td className="px-4 py-3.5 whitespace-nowrap">
                      <span className="px-2 py-0.5 rounded text-[11px] font-semibold bg-neutral-100 text-neutral-700">
                        {TOPIC_LABELS[item.topic_category] || item.topic_category}
                      </span>
                    </td>
                    <td className="px-4 py-3.5 whitespace-nowrap text-xs text-neutral-600">
                      {item.box?.box_name || 'Hòm thư chung'}
                    </td>
                    <td className="px-4 py-3.5 whitespace-nowrap">
                      {item.is_anonymous ? (
                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-semibold bg-neutral-100 text-neutral-600 border border-neutral-200">
                          <Shield className="w-3 h-3 text-neutral-500" />
                          Ẩn danh
                        </span>
                      ) : (
                        <div className="text-xs">
                          <p className="font-semibold text-neutral-800">{item.resident_user?.full_name || 'Cư dân'}</p>
                          <p className="text-neutral-400">Căn hộ: {item.apartment?.apartment_number || 'N/A'}</p>
                        </div>
                      )}
                    </td>
                    <td className="px-4 py-3.5 whitespace-nowrap">
                      {getSentimentBadge(item.ai_sentiment, item.ai_sentiment_score)}
                    </td>
                    <td className="px-4 py-3.5 whitespace-nowrap">
                      {getStatusBadge(item.status)}
                    </td>
                    <td className="px-4 py-3.5 whitespace-nowrap text-xs text-neutral-500">
                      {new Date(item.created_at).toLocaleDateString('vi-VN')}
                    </td>
                    <td className="px-4 py-3.5 text-right whitespace-nowrap">
                      <button
                        onClick={() => openDetailModal(item.id)}
                        className="px-3 py-1.5 rounded-lg bg-emerald-50 hover:bg-emerald-100 text-emerald-800 text-xs font-semibold border border-emerald-200 transition-colors cursor-pointer"
                      >
                        Xử lý
                      </button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {/* Pagination Footer */}
        <div className="px-4 py-3 border-t border-neutral-200 bg-neutral-50/50 flex items-center justify-between text-xs text-neutral-600">
          <span>
            Hiển thị <strong>{suggestions.length}</strong> trên <strong>{totalRecords}</strong> góp ý
          </span>
          <div className="flex items-center gap-2">
            <button
              onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
              disabled={currentPage <= 1}
              className="p-1.5 rounded-lg border border-neutral-300 hover:bg-white disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
            >
              <ChevronLeft className="w-4 h-4" />
            </button>
            <span className="font-semibold">
              Trang {currentPage} / {totalPages || 1}
            </span>
            <button
              onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
              disabled={currentPage >= totalPages}
              className="p-1.5 rounded-lg border border-neutral-300 hover:bg-white disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
            >
              <ChevronRight className="w-4 h-4" />
            </button>
          </div>
        </div>
      </div>

      {/* DETAIL & MANAGEMENT MODAL */}
      {selectedSuggestion && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
          <div className="bg-white rounded-2xl max-w-3xl w-full p-6 space-y-6 shadow-2xl max-h-[92vh] overflow-y-auto">
            {/* Modal Header */}
            <div className="flex items-start justify-between gap-4 pb-4 border-b border-neutral-200">
              <div>
                <div className="flex items-center gap-2 mb-1.5">
                  <span className="px-2.5 py-0.5 rounded text-xs font-semibold bg-neutral-100 text-neutral-700">
                    {TOPIC_LABELS[selectedSuggestion.topic_category] || selectedSuggestion.topic_category}
                  </span>
                  {selectedSuggestion.is_anonymous ? (
                    <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded text-xs font-semibold bg-neutral-100 text-neutral-700 border border-neutral-200">
                      <Shield className="w-3 h-3 text-neutral-500" /> Ẩn danh
                    </span>
                  ) : (
                    <span className="text-xs text-neutral-500">
                      Gửi bởi: <strong>{selectedSuggestion.resident_user?.full_name}</strong> (Căn hộ {selectedSuggestion.apartment?.apartment_number})
                    </span>
                  )}
                  <span className="text-xs text-neutral-400">
                    · Version OCC: <code className="font-mono">{selectedSuggestion.version}</code>
                  </span>
                </div>
                <h3 className="text-lg font-bold text-neutral-900">{selectedSuggestion.title}</h3>
              </div>
              <button
                onClick={() => setSelectedSuggestion(null)}
                className="p-1.5 rounded-lg text-neutral-400 hover:text-neutral-700 hover:bg-neutral-100 transition-colors"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* OCC Conflict Warning */}
            {occConflict && (
              <div className="p-4 rounded-xl bg-rose-50 border border-rose-300 text-rose-900 text-sm flex items-start justify-between gap-3 animate-in fade-in">
                <div className="flex items-start gap-2.5">
                  <AlertCircle className="w-5 h-5 text-rose-600 shrink-0 mt-0.5" />
                  <div>
                    <p className="font-bold">Tranh chấp phiên bản dữ liệu (HTTP 409 Conflict)</p>
                    <p className="text-xs text-rose-700 mt-0.5">
                      Góp ý này vừa được một người quản lý khác cập nhật. Để tránh ghi đè dữ liệu sai lệch, vui lòng tải lại phiên bản mới nhất trước khi tiếp tục thao tác.
                    </p>
                  </div>
                </div>
                <button
                  type="button"
                  onClick={reloadCurrentDetail}
                  className="px-3 py-1.5 rounded-lg bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold shrink-0 cursor-pointer shadow-sm"
                >
                  Tải lại dữ liệu
                </button>
              </div>
            )}

            {actionError && (
              <div className="p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs flex items-center gap-2">
                <AlertCircle className="w-4 h-4 text-rose-600 shrink-0" />
                <span>{actionError}</span>
              </div>
            )}

            {actionSuccess && (
              <div className="p-3.5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs flex items-center gap-2">
                <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0" />
                <span>{actionSuccess}</span>
              </div>
            )}

            {/* Body Info */}
            <div className="space-y-4">
              <div>
                <h4 className="text-xs font-semibold text-neutral-500 uppercase tracking-wider mb-1">Nội dung góp ý của cư dân</h4>
                <div className="p-4 rounded-xl bg-neutral-50 text-sm text-neutral-800 whitespace-pre-line leading-relaxed border border-neutral-200">
                  {selectedSuggestion.content_text}
                </div>
              </div>

              {/* Images */}
              {selectedSuggestion.attached_files && selectedSuggestion.attached_files.length > 0 && (
                <div>
                  <h4 className="text-xs font-semibold text-neutral-500 uppercase tracking-wider mb-2">Hình ảnh đính kèm ({selectedSuggestion.attached_files.length})</h4>
                  <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    {selectedSuggestion.attached_files.map((file) => (
                      <a
                        key={file.id}
                        href={file.url}
                        target="_blank"
                        rel="noreferrer"
                        className="rounded-xl overflow-hidden border border-neutral-200 aspect-video block bg-neutral-100 hover:opacity-90 transition-opacity"
                      >
                        <img src={file.url} alt={file.file_name} className="w-full h-full object-cover" />
                      </a>
                    ))}
                  </div>
                </div>
              )}

              {/* AI Sentiment Analysis Box */}
              <div className="p-4 rounded-xl border border-neutral-200 bg-neutral-50/50 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                  <div className="text-xs font-semibold text-neutral-500 uppercase tracking-wider mb-1 flex items-center gap-1.5">
                    <Bot className="w-4 h-4 text-indigo-600" />
                    Phân tích cảm xúc nội dung (AI Sentiment)
                  </div>
                  <div className="flex items-center gap-2 mt-1">
                    {getSentimentBadge(selectedSuggestion.ai_sentiment, selectedSuggestion.ai_sentiment_score)}
                    {selectedSuggestion.ai_sentiment_score !== null && selectedSuggestion.ai_sentiment_score !== undefined && (
                      <span className="text-xs text-neutral-500">
                        (Điểm số: <strong className="font-mono">{selectedSuggestion.ai_sentiment_score}</strong>)
                      </span>
                    )}
                  </div>
                  {aiAnalysisResult && (
                    <p className="text-xs text-indigo-700 mt-1 font-medium">{aiAnalysisResult}</p>
                  )}
                </div>

                <button
                  type="button"
                  onClick={handleAnalyzeSentiment}
                  disabled={isAnalyzingAi}
                  className="px-3.5 py-2 rounded-xl bg-white hover:bg-neutral-50 border border-neutral-300 text-neutral-700 text-xs font-semibold shadow-sm flex items-center gap-1.5 transition-colors cursor-pointer shrink-0"
                >
                  {isAnalyzingAi ? <RefreshCw className="w-3.5 h-3.5 animate-spin" /> : <Sparkles className="w-3.5 h-3.5 text-indigo-600" />}
                  Yêu cầu AI phân tích
                </button>
              </div>

              {/* State Machine Transition Actions */}
              <div className="p-4 rounded-xl border border-neutral-200 bg-white space-y-3">
                <div className="flex items-center justify-between">
                  <span className="text-xs font-semibold text-neutral-500 uppercase tracking-wider">
                    Trạng thái hiện tại: {getStatusBadge(selectedSuggestion.status)}
                  </span>
                </div>

                <div className="flex flex-wrap items-center gap-2 pt-1">
                  {selectedSuggestion.status === 'RECEIVED' && (
                    <>
                      <button
                        type="button"
                        onClick={() => handleStatusTransition('REVIEWING')}
                        className="px-3.5 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold transition-colors cursor-pointer"
                      >
                        Tiếp nhận xem xét (REVIEWING)
                      </button>
                      <button
                        type="button"
                        onClick={() => handleStatusTransition('REJECTED')}
                        className="px-3.5 py-2 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 text-xs font-semibold transition-colors cursor-pointer"
                      >
                        Từ chối góp ý (REJECTED)
                      </button>
                    </>
                  )}

                  {selectedSuggestion.status === 'REVIEWING' && (
                    <>
                      <button
                        type="button"
                        onClick={() => handleStatusTransition('ACCEPTED')}
                        className="px-3.5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold transition-colors cursor-pointer"
                      >
                        Chấp nhận xử lý (ACCEPTED)
                      </button>
                      <button
                        type="button"
                        onClick={() => handleStatusTransition('RESOLVED')}
                        className="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors cursor-pointer"
                      >
                        Đánh dấu đã giải quyết (RESOLVED)
                      </button>
                      <button
                        type="button"
                        onClick={() => handleStatusTransition('REJECTED')}
                        className="px-3.5 py-2 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 text-xs font-semibold transition-colors cursor-pointer"
                      >
                        Từ chối (REJECTED)
                      </button>
                    </>
                  )}

                  {selectedSuggestion.status === 'ACCEPTED' && (
                    <>
                      <button
                        type="button"
                        onClick={() => handleStatusTransition('RESOLVED')}
                        className="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors cursor-pointer"
                      >
                        Đánh dấu đã giải quyết (RESOLVED)
                      </button>
                      <button
                        type="button"
                        onClick={() => handleStatusTransition('REJECTED')}
                        className="px-3.5 py-2 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 text-xs font-semibold transition-colors cursor-pointer"
                      >
                        Từ chối (REJECTED)
                      </button>
                    </>
                  )}

                  {(selectedSuggestion.status === 'RESOLVED' || selectedSuggestion.status === 'REJECTED') && (
                    <span className="text-xs text-neutral-400 italic">
                      Góp ý đã ở trạng thái kết thúc ({selectedSuggestion.status}). Bạn có thể bổ sung phản hồi chính thức bên dưới nếu cần.
                    </span>
                  )}
                </div>

                {/* Rejection reason input */}
                {showRejectionInput && (
                  <div className="pt-2 space-y-2">
                    <label className="block text-xs font-semibold text-rose-800">
                      Lý do từ chối góp ý <span className="text-rose-500">*</span>
                    </label>
                    <textarea
                      rows={2}
                      value={rejectionReason}
                      onChange={(e) => setRejectionReason(e.target.value)}
                      placeholder="Nêu rõ lý do không tiếp nhận hoặc từ chối để thông tin minh bạch..."
                      className="w-full px-3 py-2 rounded-xl border border-rose-300 text-sm focus:ring-2 focus:ring-rose-500/20 focus:border-rose-500"
                    />
                    <div className="flex justify-end gap-2">
                      <button
                        type="button"
                        onClick={() => setShowRejectionInput(false)}
                        className="px-3 py-1.5 rounded-lg text-xs font-medium text-neutral-600 hover:bg-neutral-100"
                      >
                        Hủy
                      </button>
                      <button
                        type="button"
                        onClick={() => handleStatusTransition('REJECTED')}
                        className="px-3.5 py-1.5 rounded-lg bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold"
                      >
                        Xác nhận từ chối
                      </button>
                    </div>
                  </div>
                )}
              </div>

              {/* Official Reply Form */}
              <form onSubmit={handleReplySubmit} className="space-y-3 pt-2 border-t border-neutral-200">
                <div className="flex items-center justify-between">
                  <label className="block text-xs font-semibold text-neutral-700 uppercase tracking-wider">
                    Văn bản phản hồi chính thức của Ban Quản lý <span className="text-rose-500">*</span>
                  </label>
                  <div className="flex items-center gap-2 text-xs">
                    <span className="text-neutral-500">Trạng thái sau phản hồi:</span>
                    <select
                      value={newStatusOnReply}
                      onChange={(e) => setNewStatusOnReply(e.target.value)}
                      className="px-2.5 py-1 rounded-lg border border-neutral-300 text-xs bg-white text-neutral-800 font-semibold"
                    >
                      <option value="RESOLVED">Đã giải quyết (RESOLVED)</option>
                      <option value="ACCEPTED">Đã tiếp nhận (ACCEPTED)</option>
                      <option value="REVIEWING">Đang xem xét (REVIEWING)</option>
                    </select>
                  </div>
                </div>

                <textarea
                  rows={4}
                  value={replyText}
                  onChange={(e) => setReplyText(e.target.value)}
                  placeholder="Nhập nội dung phản hồi chính thức gửi tới cư dân (văn minh, chuẩn mực, nêu rõ giải pháp hoặc tiến độ xử lý)..."
                  maxLength={5000}
                  className="w-full px-4 py-3 rounded-xl border border-neutral-300 text-sm text-neutral-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition-colors"
                />

                {selectedSuggestion.responded_at && (
                  <p className="text-xs text-neutral-400">
                    Phản hồi lần gần nhất lúc: {new Date(selectedSuggestion.responded_at).toLocaleString('vi-VN')}
                    {selectedSuggestion.responded_by_staff && ` bởi ${selectedSuggestion.responded_by_staff.full_name}`}
                  </p>
                )}

                <div className="flex justify-end gap-2 pt-2">
                  <button
                    type="submit"
                    disabled={isSubmittingReply}
                    className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-colors disabled:opacity-50 cursor-pointer shadow-md"
                  >
                    {isSubmittingReply ? (
                      <>
                        <RefreshCw className="w-3.5 h-3.5 animate-spin" />
                        Đang lưu phản hồi...
                      </>
                    ) : (
                      <>
                        <Send className="w-3.5 h-3.5" />
                        Lưu & Gửi phản hồi chính thức
                      </>
                    )}
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default SuggestionManagementPanel;
