import React, { useState, useEffect, useRef } from 'react';
import {
  MessageSquare,
  Send,
  Upload,
  Shield,
  ShieldAlert,
  CheckCircle2,
  Clock,
  AlertCircle,
  X,
  Copy,
  Check,
  Search,
  FileText,
  Lock,
  ChevronRight,
  Info,
  RefreshCw,
  ImageIcon,
} from 'lucide-react';
import api, {
  SuggestionBoxItem,
  SuggestionItem,
  AnonymousSuggestionDetail,
} from '@/Services/api';

const TOPIC_OPTIONS = [
  { value: 'AN_NINH', label: 'An ninh & Trật tự', icon: Shield, color: 'text-indigo-600 bg-indigo-50 border-indigo-200' },
  { value: 'VE_SINH', label: 'Vệ sinh môi trường', icon: SparklesIcon, color: 'text-emerald-600 bg-emerald-50 border-emerald-200' },
  { value: 'BAI_XE', label: 'Bãi đỗ xe & Phương tiện', icon: CarIcon, color: 'text-sky-600 bg-sky-50 border-sky-200' },
  { value: 'TIEN_ICH', label: 'Tiện ích & Dịch vụ chung', icon: BuildingIcon, color: 'text-purple-600 bg-purple-50 border-purple-200' },
  { value: 'THAI_DO_NHAN_VIEN', label: 'Thái độ nhân viên & Lễ tân', icon: UserCheckIcon, color: 'text-amber-600 bg-amber-50 border-amber-200' },
  { value: 'KHAC', label: 'Ý kiến đóng góp khác', icon: FileText, color: 'text-neutral-600 bg-neutral-50 border-neutral-200' },
];

function SparklesIcon(props: React.SVGProps<SVGSVGElement>) {
  return (
    <svg fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-5 h-5" {...props}>
      <path strokeLinecap="round" strokeLinejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z" />
    </svg>
  );
}

function CarIcon(props: React.SVGProps<SVGSVGElement>) {
  return (
    <svg fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-5 h-5" {...props}>
      <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.25V3.75m0 3.75H9.75m0 0V3.75m0 3.75H4.875a1.875 1.875 0 0 0-1.875 1.875v5.25m14.25-7.125h.375" />
    </svg>
  );
}

function BuildingIcon(props: React.SVGProps<SVGSVGElement>) {
  return (
    <svg fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-5 h-5" {...props}>
      <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" />
    </svg>
  );
}

function UserCheckIcon(props: React.SVGProps<SVGSVGElement>) {
  return (
    <svg fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-5 h-5" {...props}>
      <path strokeLinecap="round" strokeLinejoin="round" d="M19 7.5 14.5 12 12.25 9.75m-6 9.75h9.75a2.25 2.25 0 0 0 2.25-2.25V9.75a2.25 2.25 0 0 0-2.25-2.25H6.25a2.25 2.25 0 0 0-2.25 2.25v7.5A2.25 2.25 0 0 0 6.25 19.5Z" />
    </svg>
  );
}

export const ResidentSuggestionPanel: React.FC = () => {
  const [activeTab, setActiveTab] = useState<'submit' | 'history' | 'anonymous_lookup'>('submit');

  // Suggestion Boxes
  const [boxes, setBoxes] = useState<SuggestionBoxItem[]>([]);
  const [isLoadingBoxes, setIsLoadingBoxes] = useState(false);

  // Form State
  const [selectedBoxId, setSelectedBoxId] = useState<string>('');
  const [topicCategory, setTopicCategory] = useState<string>('VE_SINH');
  const [title, setTitle] = useState<string>('');
  const [contentText, setContentText] = useState<string>('');
  const [isAnonymous, setIsAnonymous] = useState<boolean>(false);
  const [selectedFiles, setSelectedFiles] = useState<File[]>([]);
  const [previewUrls, setPreviewUrls] = useState<string[]>([]);
  const [isSubmitting, setIsSubmitting] = useState<boolean>(false);
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});
  const fileInputRef = useRef<HTMLInputElement | null>(null);

  // Anonymous Success Modal
  const [showAnonTokenModal, setShowAnonTokenModal] = useState<boolean>(false);
  const [generatedAnonToken, setGeneratedAnonToken] = useState<string>('');
  const [isCopiedToken, setIsCopiedToken] = useState<boolean>(false);

  // Resident History
  const [mySuggestions, setMySuggestions] = useState<SuggestionItem[]>([]);
  const [isLoadingHistory, setIsLoadingHistory] = useState<boolean>(false);
  const [historyPage, setHistoryPage] = useState<number>(1);
  const [historyTotal, setHistoryTotal] = useState<number>(0);
  const [selectedHistoryItem, setSelectedHistoryItem] = useState<SuggestionItem | null>(null);

  // Anonymous Lookup State
  const [lookupTokenInput, setLookupTokenInput] = useState<string>('');
  const [isLookingUp, setIsLookingUp] = useState<boolean>(false);
  const [lookupResult, setLookupResult] = useState<AnonymousSuggestionDetail | null>(null);
  const [lookupError, setLookupError] = useState<string | null>(null);

  // Load Suggestion Boxes on Mount
  useEffect(() => {
    loadBoxes();
  }, []);

  // Load Resident History when tab is switched
  useEffect(() => {
    if (activeTab === 'history') {
      loadHistory(historyPage);
    }
  }, [activeTab, historyPage]);

  // Clean up object URLs
  useEffect(() => {
    return () => {
      previewUrls.forEach((url) => URL.revokeObjectURL(url));
    };
  }, [previewUrls]);

  const loadBoxes = async () => {
    try {
      setIsLoadingBoxes(true);
      const res = await api.getResidentSuggestionBoxes();
      if (res?.data) {
        setBoxes(res.data);
        if (res.data.length > 0 && !selectedBoxId) {
          setSelectedBoxId(res.data[0].id);
        }
      }
    } catch (err) {
      console.warn('Lỗi tải danh sách hòm thư:', err);
    } finally {
      setIsLoadingBoxes(false);
    }
  };

  const loadHistory = async (page: number = 1) => {
    try {
      setIsLoadingHistory(true);
      const res = await api.getResidentSuggestions({ page, per_page: 10 });
      if (res?.data) {
        setMySuggestions(res.data);
        setHistoryTotal(res.pagination?.total || 0);
      }
    } catch (err) {
      console.warn('Lỗi tải lịch sử góp ý:', err);
    } finally {
      setIsLoadingHistory(false);
    }
  };

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (!e.target.files) return;
    const files = Array.from(e.target.files);
    addFiles(files);
  };

  const addFiles = (files: File[]) => {
    const validFiles: File[] = [];
    const newErrors: Record<string, string> = { ...formErrors };
    delete newErrors.attached_files;

    if (selectedFiles.length + files.length > 5) {
      newErrors.attached_files = 'Chỉ được đính kèm tối đa 5 hình ảnh.';
      setFormErrors(newErrors);
      return;
    }

    for (const file of files) {
      if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
        newErrors.attached_files = 'Chỉ chấp nhận ảnh định dạng JPG, PNG hoặc WebP.';
        break;
      }
      if (file.size > 5 * 1024 * 1024) {
        newErrors.attached_files = `Ảnh "${file.name}" vượt quá giới hạn 5MB.`;
        break;
      }
      validFiles.push(file);
    }

    if (newErrors.attached_files) {
      setFormErrors(newErrors);
      return;
    }

    const updatedFiles = [...selectedFiles, ...validFiles];
    setSelectedFiles(updatedFiles);

    // Create preview URLs
    const newPreviews = validFiles.map((f) => URL.createObjectURL(f));
    setPreviewUrls((prev) => [...prev, ...newPreviews]);
  };

  const removeFile = (index: number) => {
    URL.revokeObjectURL(previewUrls[index]);
    setSelectedFiles((prev) => prev.filter((_, i) => i !== index));
    setPreviewUrls((prev) => prev.filter((_, i) => i !== index));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    const errors: Record<string, string> = {};

    if (!selectedBoxId) errors.box_id = 'Vui lòng chọn hòm thư nhận góp ý.';
    if (!title.trim()) errors.title = 'Vui lòng nhập tiêu đề góp ý.';
    else if (title.trim().length < 5) errors.title = 'Tiêu đề cần tối thiểu 5 ký tự.';
    if (!contentText.trim()) errors.content_text = 'Vui lòng nhập nội dung chi tiết.';
    else if (contentText.trim().length < 10) errors.content_text = 'Nội dung cần tối thiểu 10 ký tự.';

    if (Object.keys(errors).length > 0) {
      setFormErrors(errors);
      return;
    }

    setFormErrors({});
    setIsSubmitting(true);

    try {
      const formData = new FormData();
      formData.append('box_id', selectedBoxId);
      formData.append('topic_category', topicCategory);
      formData.append('title', title.trim());
      formData.append('content_text', contentText.trim());
      formData.append('is_anonymous', isAnonymous ? '1' : '0');
      // Idempotency key to avoid double submission
      formData.append('idempotency_key', `resident-sug-${Date.now()}-${Math.random().toString(36).substring(2, 9)}`);

      selectedFiles.forEach((file) => {
        formData.append('attached_files[]', file);
      });

      const response = await api.submitResidentSuggestion(formData);

      if (response?.success) {
        // Reset form
        setTitle('');
        setContentText('');
        setSelectedFiles([]);
        setPreviewUrls([]);

        if (response.anonymous_token) {
          setGeneratedAnonToken(response.anonymous_token);
          setShowAnonTokenModal(true);
        } else {
          setActiveTab('history');
          loadHistory(1);
        }
      }
    } catch (err: any) {
      const msg = err?.response?.data?.message || err?.message || 'Có lỗi xảy ra khi gửi góp ý.';
      setFormErrors({ submit: msg });
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleAnonymousLookup = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!lookupTokenInput.trim()) {
      setLookupError('Vui lòng nhập mã tra cứu.');
      return;
    }

    setLookupError(null);
    setIsLookingUp(true);
    setLookupResult(null);

    try {
      const res = await api.lookupAnonymousSuggestion(lookupTokenInput.trim());
      if (res?.data) {
        setLookupResult(res.data);
      }
    } catch (err: any) {
      setLookupError(err?.response?.data?.message || 'Không tìm thấy góp ý tương ứng với mã tra cứu này.');
    } finally {
      setIsLookingUp(false);
    }
  };

  const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
    setIsCopiedToken(true);
    setTimeout(() => setIsCopiedToken(false), 2000);
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'RECEIVED':
        return <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-200"><Clock className="w-3 h-3" /> Chờ tiếp nhận</span>;
      case 'REVIEWING':
        return <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-50 text-blue-700 border border-blue-200"><RefreshCw className="w-3 h-3 animate-spin" /> Đang xem xét</span>;
      case 'ACCEPTED':
        return <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-indigo-50 text-indigo-700 border border-indigo-200"><CheckCircle2 className="w-3 h-3" /> Đã tiếp nhận xử lý</span>;
      case 'RESOLVED':
        return <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200"><CheckCircle2 className="w-3 h-3" /> Đã giải quyết</span>;
      case 'REJECTED':
        return <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rose-50 text-rose-700 border border-rose-200"><X className="w-3 h-3" /> Từ chối</span>;
      default:
        return <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-neutral-100 text-neutral-700">{status}</span>;
    }
  };

  const getTopicLabel = (topic: string) => {
    const found = TOPIC_OPTIONS.find((t) => t.value === topic);
    return found ? found.label : topic;
  };

  return (
    <div className="space-y-6">
      {/* Top Banner */}
      <div className="relative overflow-hidden rounded-2xl bg-gradient-to-r from-teal-900 via-emerald-800 to-cyan-900 p-6 sm:p-8 text-white shadow-xl">
        <div className="relative z-10 max-w-3xl">
          <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/10 backdrop-blur-md text-xs font-semibold tracking-wide uppercase text-teal-200 mb-4 border border-white/10">
            <MessageSquare className="w-3.5 h-3.5 text-teal-300" />
            Cổng Thông Tin Lắng Nghe Cư Dân
          </div>
          <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight">
            Hòm Thư Góp Ý & Ý Kiến Đóng Góp
          </h1>
          <p className="mt-2 text-sm sm:text-base text-teal-100 leading-relaxed">
            Mọi ý kiến của Quý Cư dân đều là nguồn động lực quý giá để Ban Quản lý SMART CASSAVAS không ngừng nâng cao chất lượng dịch vụ, bảo đảm an ninh và hoàn thiện không gian sống văn minh.
          </p>
        </div>
        <div className="absolute -right-12 -bottom-12 w-64 h-64 rounded-full bg-emerald-500/10 blur-3xl pointer-events-none" />
      </div>

      {/* Tab Navigation */}
      <div className="flex border-b border-neutral-200">
        <button
          onClick={() => setActiveTab('submit')}
          className={`flex items-center gap-2 px-5 py-3 text-sm font-semibold border-b-2 transition-all duration-200 ${
            activeTab === 'submit'
              ? 'border-emerald-600 text-emerald-700'
              : 'border-transparent text-neutral-600 hover:text-neutral-900 hover:border-neutral-300'
          }`}
        >
          <Send className="w-4 h-4" />
          Gửi góp ý mới
        </button>
        <button
          onClick={() => setActiveTab('history')}
          className={`flex items-center gap-2 px-5 py-3 text-sm font-semibold border-b-2 transition-all duration-200 ${
            activeTab === 'history'
              ? 'border-emerald-600 text-emerald-700'
              : 'border-transparent text-neutral-600 hover:text-neutral-900 hover:border-neutral-300'
          }`}
        >
          <FileText className="w-4 h-4" />
          Lịch sử góp ý của tôi
          {historyTotal > 0 && (
            <span className="ml-1 px-2 py-0.5 rounded-full text-xs bg-emerald-100 text-emerald-800">
              {historyTotal}
            </span>
          )}
        </button>
        <button
          onClick={() => setActiveTab('anonymous_lookup')}
          className={`flex items-center gap-2 px-5 py-3 text-sm font-semibold border-b-2 transition-all duration-200 ${
            activeTab === 'anonymous_lookup'
              ? 'border-emerald-600 text-emerald-700'
              : 'border-transparent text-neutral-600 hover:text-neutral-900 hover:border-neutral-300'
          }`}
        >
          <Search className="w-4 h-4" />
          Tra cứu góp ý ẩn danh
        </button>
      </div>

      {/* TAB 1: FORM GỬI GÓP Ý */}
      {activeTab === 'submit' && (
        <form onSubmit={handleSubmit} className="space-y-6">
          <div className="bg-white rounded-2xl border border-neutral-200/80 p-6 shadow-sm space-y-6">
            <h2 className="text-lg font-bold text-neutral-900 flex items-center gap-2 pb-3 border-b border-neutral-100">
              <MessageSquare className="w-5 h-5 text-emerald-600" />
              Thông tin góp ý
            </h2>

            {formErrors.submit && (
              <div className="p-4 rounded-xl bg-rose-50 border border-rose-200 flex items-start gap-3 text-rose-800 text-sm">
                <AlertCircle className="w-5 h-5 text-rose-500 shrink-0 mt-0.5" />
                <span>{formErrors.submit}</span>
              </div>
            )}

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              {/* Hòm thư nhận */}
              <div>
                <label className="block text-xs font-semibold text-neutral-700 uppercase tracking-wider mb-2">
                  Hòm thư tiếp nhận <span className="text-rose-500">*</span>
                </label>
                <select
                  value={selectedBoxId}
                  onChange={(e) => setSelectedBoxId(e.target.value)}
                  className="w-full px-4 py-2.5 rounded-xl border border-neutral-300 bg-white text-sm text-neutral-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition-colors"
                >
                  {boxes.map((box) => (
                    <option key={box.id} value={box.id}>
                      {box.box_name} {box.block ? `(${box.block.block_name})` : '(Toàn khu)'}
                    </option>
                  ))}
                </select>
                {formErrors.box_id && <p className="mt-1.5 text-xs text-rose-600">{formErrors.box_id}</p>}
                {boxes.find((b) => b.id === selectedBoxId)?.description && (
                  <p className="mt-1.5 text-xs text-neutral-500 italic">
                    {boxes.find((b) => b.id === selectedBoxId)?.description}
                  </p>
                )}
              </div>

              {/* Chủ đề */}
              <div>
                <label className="block text-xs font-semibold text-neutral-700 uppercase tracking-wider mb-2">
                  Chủ đề góp ý <span className="text-rose-500">*</span>
                </label>
                <select
                  value={topicCategory}
                  onChange={(e) => setTopicCategory(e.target.value)}
                  className="w-full px-4 py-2.5 rounded-xl border border-neutral-300 bg-white text-sm text-neutral-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition-colors"
                >
                  {TOPIC_OPTIONS.map((opt) => (
                    <option key={opt.value} value={opt.value}>
                      {opt.label}
                    </option>
                  ))}
                </select>
                {formErrors.topic_category && <p className="mt-1.5 text-xs text-rose-600">{formErrors.topic_category}</p>}
              </div>
            </div>

            {/* Tiêu đề */}
            <div>
              <label className="block text-xs font-semibold text-neutral-700 uppercase tracking-wider mb-2">
                Tiêu đề góp ý <span className="text-rose-500">*</span>
              </label>
              <input
                type="text"
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                placeholder="VD: Đề xuất bổ sung thêm thùng rác phân loại tại sảnh thang máy Block A"
                maxLength={255}
                className="w-full px-4 py-2.5 rounded-xl border border-neutral-300 bg-white text-sm text-neutral-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition-colors"
              />
              <div className="flex justify-between items-center mt-1.5">
                {formErrors.title ? (
                  <p className="text-xs text-rose-600">{formErrors.title}</p>
                ) : (
                  <span />
                )}
                <span className="text-xs text-neutral-400">{title.length}/255</span>
              </div>
            </div>

            {/* Nội dung */}
            <div>
              <label className="block text-xs font-semibold text-neutral-700 uppercase tracking-wider mb-2">
                Nội dung chi tiết <span className="text-rose-500">*</span>
              </label>
              <textarea
                rows={5}
                value={contentText}
                onChange={(e) => setContentText(e.target.value)}
                placeholder="Mô tả cụ thể vấn đề hoặc ý kiến đề xuất của bạn để Ban Quản lý có thể xử lý hiệu quả nhất..."
                maxLength={5000}
                className="w-full px-4 py-3 rounded-xl border border-neutral-300 bg-white text-sm text-neutral-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition-colors"
              />
              <div className="flex justify-between items-center mt-1.5">
                {formErrors.content_text ? (
                  <p className="text-xs text-rose-600">{formErrors.content_text}</p>
                ) : (
                  <span />
                )}
                <span className="text-xs text-neutral-400">{contentText.length}/5000</span>
              </div>
            </div>

            {/* Upload Zone */}
            <div>
              <label className="block text-xs font-semibold text-neutral-700 uppercase tracking-wider mb-2">
                Hình ảnh đính kèm (Tối đa 5 ảnh, định dạng JPG/PNG/WebP, tối đa 5MB/ảnh)
              </label>
              <div
                onClick={() => fileInputRef.current?.click()}
                className="border-2 border-dashed border-neutral-300 hover:border-emerald-500 rounded-2xl p-6 text-center cursor-pointer transition-colors bg-neutral-50/50 hover:bg-emerald-50/20 group"
              >
                <input
                  type="file"
                  ref={fileInputRef}
                  onChange={handleFileChange}
                  multiple
                  accept="image/jpeg,image/png,image/webp"
                  className="hidden"
                />
                <div className="flex flex-col items-center justify-center gap-2">
                  <div className="w-12 h-12 rounded-full bg-emerald-100 flex items-center justify-center text-emerald-600 group-hover:scale-110 transition-transform">
                    <Upload className="w-6 h-6" />
                  </div>
                  <p className="text-sm font-semibold text-neutral-800">
                    Kéo thả hình ảnh vào đây hoặc click để tải lên
                  </p>
                  <p className="text-xs text-neutral-500">
                    JPG, PNG hoặc WebP tối đa 5MB mỗi tệp
                  </p>
                </div>
              </div>
              {formErrors.attached_files && (
                <p className="mt-1.5 text-xs text-rose-600">{formErrors.attached_files}</p>
              )}

              {/* Thumbnails preview */}
              {previewUrls.length > 0 && (
                <div className="mt-4 grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3">
                  {previewUrls.map((url, idx) => (
                    <div key={idx} className="relative group rounded-xl overflow-hidden border border-neutral-200 aspect-video bg-neutral-100">
                      <img src={url} alt={`Ảnh ${idx + 1}`} className="w-full h-full object-cover" />
                      <button
                        type="button"
                        onClick={() => removeFile(idx)}
                        className="absolute top-1 right-1 p-1 rounded-full bg-neutral-900/70 hover:bg-rose-600 text-white transition-colors"
                        title="Xóa ảnh"
                      >
                        <X className="w-3.5 h-3.5" />
                      </button>
                    </div>
                  ))}
                </div>
              )}
            </div>

            {/* Toggle Gửi ẩn danh */}
            <div className="p-4 rounded-xl border border-neutral-200 bg-neutral-50/60 flex items-start gap-4">
              <div className="pt-0.5">
                <input
                  id="anonymous-toggle"
                  type="checkbox"
                  checked={isAnonymous}
                  onChange={(e) => setIsAnonymous(e.target.checked)}
                  className="w-5 h-5 rounded border-neutral-300 text-emerald-600 focus:ring-emerald-500 cursor-pointer"
                />
              </div>
              <label htmlFor="anonymous-toggle" className="cursor-pointer space-y-1">
                <div className="text-sm font-bold text-neutral-900 flex items-center gap-1.5">
                  <Shield className="w-4 h-4 text-emerald-600" />
                  Gửi ẩn danh (Bảo mật danh tính 100%)
                </div>
                <p className="text-xs text-neutral-600 leading-relaxed">
                  Khi bật chế độ này, hệ thống sẽ <strong>hoàn toàn không lưu tên, tài khoản hay số căn hộ</strong> của bạn vào dữ liệu góp ý. Bạn sẽ được cấp một <strong>mã bảo mật duy nhất</strong> để tự tra cứu phản hồi từ Ban Quản lý.
                </p>
                {isAnonymous && (
                  <p className="text-xs text-amber-700 bg-amber-50 p-2 rounded-lg border border-amber-200 mt-2 font-medium">
                    Lưu ý: Để bảo đảm vô danh tuyệt đối, Quý cư dân không nên tự nêu tên riêng, số phòng hoặc thông tin nhận diện cá nhân trong nội dung tiêu đề và hình ảnh đính kèm.
                  </p>
                )}
              </label>
            </div>
          </div>

          <div className="flex justify-end gap-3">
            <button
              type="submit"
              disabled={isSubmitting}
              className="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-sm shadow-md hover:shadow-lg transition-all disabled:opacity-50 cursor-pointer"
            >
              {isSubmitting ? (
                <>
                  <RefreshCw className="w-4 h-4 animate-spin" />
                  Đang gửi góp ý...
                </>
              ) : (
                <>
                  <Send className="w-4 h-4" />
                  Gửi ý kiến góp ý
                </>
              )}
            </button>
          </div>
        </form>
      )}

      {/* TAB 2: LỊCH SỬ GÓP Ý CỦA TÔI */}
      {activeTab === 'history' && (
        <div className="space-y-4">
          <div className="bg-white rounded-2xl border border-neutral-200 p-6 shadow-sm">
            <h2 className="text-lg font-bold text-neutral-900 flex items-center justify-between pb-4 border-b border-neutral-100">
              <span className="flex items-center gap-2">
                <FileText className="w-5 h-5 text-emerald-600" />
                Góp ý có danh tính của bạn
              </span>
              <button
                onClick={() => loadHistory(historyPage)}
                className="text-xs font-semibold text-neutral-600 hover:text-emerald-700 flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-neutral-200 hover:bg-neutral-50 transition-colors"
              >
                <RefreshCw className="w-3.5 h-3.5" />
                Làm mới
              </button>
            </h2>

            {isLoadingHistory ? (
              <div className="py-12 text-center text-neutral-500 flex flex-col items-center gap-3">
                <RefreshCw className="w-8 h-8 animate-spin text-emerald-600" />
                <p className="text-sm">Đang tải lịch sử góp ý...</p>
              </div>
            ) : mySuggestions.length === 0 ? (
              <div className="py-12 text-center text-neutral-500">
                <div className="w-12 h-12 rounded-full bg-neutral-100 flex items-center justify-center mx-auto text-neutral-400 mb-3">
                  <MessageSquare className="w-6 h-6" />
                </div>
                <p className="text-sm font-semibold text-neutral-700">Chưa có ý kiến góp ý nào</p>
                <p className="text-xs text-neutral-400 mt-1">
                  Các ý kiến đóng góp có danh tính của bạn sẽ hiển thị tại đây để bạn tiện theo dõi phản hồi.
                </p>
                <button
                  onClick={() => setActiveTab('submit')}
                  className="mt-4 inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-emerald-600 text-white text-xs font-semibold hover:bg-emerald-700 transition-colors"
                >
                  <Send className="w-3.5 h-3.5" />
                  Gửi ý kiến ngay
                </button>
              </div>
            ) : (
              <div className="divide-y divide-neutral-100 mt-2">
                {mySuggestions.map((item) => (
                  <div key={item.id} className="py-4 hover:bg-neutral-50/50 p-3 rounded-xl transition-colors">
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                      <div className="space-y-1">
                        <div className="flex items-center gap-2">
                          <span className="px-2 py-0.5 rounded text-[11px] font-semibold bg-neutral-100 text-neutral-700">
                            {getTopicLabel(item.topic_category)}
                          </span>
                          <span className="text-xs text-neutral-400">
                            {new Date(item.created_at).toLocaleString('vi-VN')}
                          </span>
                          {item.box && (
                            <span className="text-xs text-neutral-400">· {item.box.box_name}</span>
                          )}
                        </div>
                        <h3 className="text-sm font-bold text-neutral-900 hover:text-emerald-700 cursor-pointer" onClick={() => setSelectedHistoryItem(item)}>
                          {item.title}
                        </h3>
                      </div>
                      <div className="flex items-center gap-3">
                        {getStatusBadge(item.status)}
                        <button
                          onClick={() => setSelectedHistoryItem(item)}
                          className="px-3 py-1.5 rounded-lg text-xs font-semibold text-neutral-700 hover:bg-neutral-100 border border-neutral-200 transition-colors"
                        >
                          Chi tiết
                        </button>
                      </div>
                    </div>

                    {/* Official Response Preview if resolved */}
                    {item.official_response_text && (
                      <div className="mt-3 p-3 rounded-xl bg-emerald-50/60 border border-emerald-100 text-xs text-emerald-950 space-y-1">
                        <div className="font-semibold text-emerald-800 flex items-center gap-1.5">
                          <CheckCircle2 className="w-3.5 h-3.5 text-emerald-600" />
                          Phản hồi từ Ban Quản lý {item.responded_by_staff ? `(${item.responded_by_staff.full_name})` : ''}:
                        </div>
                        <p className="line-clamp-2 text-neutral-700">{item.official_response_text}</p>
                      </div>
                    )}
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      )}

      {/* TAB 3: TRA CỨU GÓP Ý ẨN DANH */}
      {activeTab === 'anonymous_lookup' && (
        <div className="space-y-6">
          <div className="bg-white rounded-2xl border border-neutral-200 p-6 shadow-sm space-y-6">
            <div>
              <h2 className="text-lg font-bold text-neutral-900 flex items-center gap-2">
                <Search className="w-5 h-5 text-emerald-600" />
                Tra cứu tiến độ & Phản hồi góp ý ẩn danh
              </h2>
              <p className="text-xs text-neutral-500 mt-1">
                Nhập mã bảo mật (dạng <code className="font-mono text-emerald-600 bg-emerald-50 px-1 py-0.5 rounded">SC-XXXX-XXXX</code>) được cấp khi bạn gửi góp ý ẩn danh để xem tiến độ tiếp nhận và văn bản phản hồi chính thức từ Ban Quản lý.
              </p>
            </div>

            <form onSubmit={handleAnonymousLookup} className="flex flex-col sm:flex-row gap-3">
              <div className="relative flex-1">
                <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-neutral-400">
                  <Lock className="w-4 h-4" />
                </div>
                <input
                  type="text"
                  value={lookupTokenInput}
                  onChange={(e) => setLookupTokenInput(e.target.value)}
                  placeholder="Nhập mã tra cứu (VD: SC-A8F9B2C1-D3E4F5G6)..."
                  className="w-full pl-10 pr-4 py-3 rounded-xl border border-neutral-300 text-sm font-mono tracking-wide text-neutral-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition-colors uppercase"
                />
              </div>
              <button
                type="submit"
                disabled={isLookingUp}
                className="px-6 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold transition-all disabled:opacity-50 flex items-center justify-center gap-2 shrink-0 cursor-pointer shadow-sm"
              >
                {isLookingUp ? <RefreshCw className="w-4 h-4 animate-spin" /> : <Search className="w-4 h-4" />}
                Tra cứu ngay
              </button>
            </form>

            {lookupError && (
              <div className="p-4 rounded-xl bg-rose-50 border border-rose-200 flex items-start gap-3 text-rose-800 text-sm">
                <AlertCircle className="w-5 h-5 text-rose-500 shrink-0 mt-0.5" />
                <span>{lookupError}</span>
              </div>
            )}

            {lookupResult && (
              <div className="border border-neutral-200 rounded-2xl p-6 bg-neutral-50/50 space-y-4">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-4 border-b border-neutral-200">
                  <div>
                    <div className="flex items-center gap-2 mb-1">
                      <span className="px-2 py-0.5 rounded text-[11px] font-semibold bg-neutral-200 text-neutral-800">
                        {getTopicLabel(lookupResult.topic_category)}
                      </span>
                      <span className="text-xs text-neutral-500">
                        Gửi lúc: {new Date(lookupResult.created_at).toLocaleString('vi-VN')}
                      </span>
                      {lookupResult.box_name && (
                        <span className="text-xs text-neutral-500">· {lookupResult.box_name}</span>
                      )}
                    </div>
                    <h3 className="text-base font-bold text-neutral-900">{lookupResult.title}</h3>
                  </div>
                  <div>{getStatusBadge(lookupResult.status)}</div>
                </div>

                {/* Content */}
                <div>
                  <h4 className="text-xs font-semibold text-neutral-500 uppercase tracking-wider mb-1">Nội dung góp ý</h4>
                  <p className="text-sm text-neutral-800 whitespace-pre-line leading-relaxed bg-white p-4 rounded-xl border border-neutral-200">
                    {lookupResult.content_text}
                  </p>
                </div>

                {/* Attached files */}
                {lookupResult.attached_files && lookupResult.attached_files.length > 0 && (
                  <div>
                    <h4 className="text-xs font-semibold text-neutral-500 uppercase tracking-wider mb-2">Ảnh đính kèm</h4>
                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                      {lookupResult.attached_files.map((file) => (
                        <a
                          key={file.id}
                          href={`${file.url}?anon_token=${encodeURIComponent(lookupTokenInput.trim())}`}
                          target="_blank"
                          rel="noreferrer"
                          className="rounded-xl overflow-hidden border border-neutral-200 aspect-video block bg-neutral-100 hover:opacity-90 transition-opacity"
                        >
                          <img
                            src={`${file.url}?anon_token=${encodeURIComponent(lookupTokenInput.trim())}`}
                            alt={file.file_name}
                            className="w-full h-full object-cover"
                          />
                        </a>
                      ))}
                    </div>
                  </div>
                )}

                {/* Official Response */}
                {lookupResult.official_response_text ? (
                  <div className="p-4 rounded-xl bg-emerald-50 border border-emerald-200 space-y-2">
                    <div className="flex items-center justify-between">
                      <h4 className="text-sm font-bold text-emerald-900 flex items-center gap-2">
                        <CheckCircle2 className="w-4 h-4 text-emerald-600" />
                        Phản hồi chính thức từ Ban Quản lý Tòa nhà
                      </h4>
                      {lookupResult.responded_at && (
                        <span className="text-xs text-emerald-700">
                          {new Date(lookupResult.responded_at).toLocaleString('vi-VN')}
                        </span>
                      )}
                    </div>
                    <p className="text-sm text-neutral-800 whitespace-pre-line leading-relaxed">
                      {lookupResult.official_response_text}
                    </p>
                  </div>
                ) : (
                  <div className="p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-900 text-xs flex items-center gap-2">
                    <Clock className="w-4 h-4 text-amber-600 shrink-0" />
                    <span>Ban Quản lý đang tiếp nhận và xem xét ý kiến của Quý Cư dân. Văn bản phản hồi chính thức sẽ được cập nhật tại đây ngay sau khi hoàn tất.</span>
                  </div>
                )}
              </div>
            )}
          </div>
        </div>
      )}

      {/* DETAIL MODAL FOR RESIDENT HISTORY ITEM */}
      {selectedHistoryItem && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
          <div className="bg-white rounded-2xl max-w-2xl w-full p-6 space-y-5 shadow-2xl max-h-[90vh] overflow-y-auto">
            <div className="flex items-start justify-between gap-4 pb-4 border-b border-neutral-200">
              <div>
                <div className="flex items-center gap-2 mb-1">
                  <span className="px-2 py-0.5 rounded text-[11px] font-semibold bg-neutral-100 text-neutral-700">
                    {getTopicLabel(selectedHistoryItem.topic_category)}
                  </span>
                  <span className="text-xs text-neutral-400">
                    {new Date(selectedHistoryItem.created_at).toLocaleString('vi-VN')}
                  </span>
                </div>
                <h3 className="text-lg font-bold text-neutral-900">{selectedHistoryItem.title}</h3>
              </div>
              <button
                onClick={() => setSelectedHistoryItem(null)}
                className="p-1 rounded-lg text-neutral-400 hover:text-neutral-700 hover:bg-neutral-100 transition-colors"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="space-y-4">
              <div>
                <h4 className="text-xs font-semibold text-neutral-500 uppercase tracking-wider mb-1">Nội dung đã gửi</h4>
                <div className="p-4 rounded-xl bg-neutral-50 text-sm text-neutral-800 whitespace-pre-line leading-relaxed border border-neutral-200">
                  {selectedHistoryItem.content_text}
                </div>
              </div>

              {selectedHistoryItem.attached_files && selectedHistoryItem.attached_files.length > 0 && (
                <div>
                  <h4 className="text-xs font-semibold text-neutral-500 uppercase tracking-wider mb-2">Ảnh đính kèm</h4>
                  <div className="grid grid-cols-3 gap-3">
                    {selectedHistoryItem.attached_files.map((file) => (
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

              {/* Status and Response */}
              <div>
                <div className="flex items-center justify-between mb-2">
                  <h4 className="text-xs font-semibold text-neutral-500 uppercase tracking-wider">Trạng thái xử lý</h4>
                  {getStatusBadge(selectedHistoryItem.status)}
                </div>

                {selectedHistoryItem.official_response_text ? (
                  <div className="p-4 rounded-xl bg-emerald-50 border border-emerald-200 space-y-2">
                    <div className="flex items-center justify-between">
                      <div className="text-sm font-bold text-emerald-900 flex items-center gap-1.5">
                        <CheckCircle2 className="w-4 h-4 text-emerald-600" />
                        Phản hồi chính thức từ Ban Quản lý
                      </div>
                      {selectedHistoryItem.responded_at && (
                        <span className="text-xs text-emerald-700">
                          {new Date(selectedHistoryItem.responded_at).toLocaleString('vi-VN')}
                        </span>
                      )}
                    </div>
                    <p className="text-sm text-neutral-800 whitespace-pre-line leading-relaxed">
                      {selectedHistoryItem.official_response_text}
                    </p>
                    {selectedHistoryItem.responded_by_staff && (
                      <p className="text-xs text-emerald-800 font-medium pt-1">
                        Người đại diện phản hồi: {selectedHistoryItem.responded_by_staff.full_name}
                      </p>
                    )}
                  </div>
                ) : (
                  <div className="p-3.5 rounded-xl bg-amber-50 border border-amber-200 text-amber-900 text-xs flex items-center gap-2">
                    <Clock className="w-4 h-4 text-amber-600 shrink-0" />
                    <span>Ban Quản lý đang xem xét ý kiến của Quý Cư dân và sẽ phản hồi trong thời gian sớm nhất.</span>
                  </div>
                )}
              </div>
            </div>

            <div className="pt-2 flex justify-end">
              <button
                onClick={() => setSelectedHistoryItem(null)}
                className="px-5 py-2.5 rounded-xl bg-neutral-900 hover:bg-neutral-800 text-white text-xs font-semibold transition-colors"
              >
                Đóng
              </button>
            </div>
          </div>
        </div>
      )}

      {/* POPUP: CẤP MÃ BẢO MẬT GÓP Ý ẨN DANH */}
      {showAnonTokenModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm animate-in fade-in duration-200">
          <div className="bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 space-y-6 shadow-2xl border border-neutral-100">
            <div className="w-16 h-16 rounded-2xl bg-emerald-100 text-emerald-700 flex items-center justify-center mx-auto shadow-inner">
              <Shield className="w-8 h-8" />
            </div>

            <div className="text-center space-y-2">
              <h3 className="text-xl font-extrabold text-neutral-900">
                Gửi Góp Ý Ẩn Danh Thành Công!
              </h3>
              <p className="text-xs text-neutral-600 leading-relaxed">
                Hệ thống đã mã hóa và bảo vệ tính ẩn danh của bạn. Dưới đây là <strong>Mã tra cứu bảo mật duy nhất</strong> được tạo cho góp ý này:
              </p>
            </div>

            <div className="p-4 rounded-2xl bg-neutral-900 text-white flex items-center justify-between gap-3 shadow-inner">
              <code className="text-lg font-mono tracking-wider font-bold text-emerald-400 select-all">
                {generatedAnonToken}
              </code>
              <button
                type="button"
                onClick={() => copyToClipboard(generatedAnonToken)}
                className="px-3.5 py-2 rounded-xl bg-white/10 hover:bg-white/20 text-white text-xs font-semibold flex items-center gap-1.5 transition-colors cursor-pointer shrink-0"
              >
                {isCopiedToken ? (
                  <>
                    <Check className="w-3.5 h-3.5 text-emerald-400" />
                    Đã sao chép
                  </>
                ) : (
                  <>
                    <Copy className="w-3.5 h-3.5" />
                    Sao chép
                  </>
                )}
              </button>
            </div>

            <div className="p-3.5 rounded-xl bg-amber-50 border border-amber-200 text-amber-900 text-xs flex items-start gap-2.5">
              <AlertCircle className="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
              <span>
                <strong>Cảnh báo quan trọng:</strong> Vì lý do bảo mật quyền riêng tư, hệ thống chỉ hiển thị mã này một lần duy nhất. Hãy lưu lại mã để tra cứu phản hồi chính thức từ Ban Quản lý tại tab <em>"Tra cứu góp ý ẩn danh"</em>.
              </span>
            </div>

            <div className="flex justify-center">
              <button
                type="button"
                onClick={() => {
                  setShowAnonTokenModal(false);
                  setLookupTokenInput(generatedAnonToken);
                  setActiveTab('anonymous_lookup');
                }}
                className="w-full py-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold transition-colors shadow-md hover:shadow-lg"
              >
                Tôi đã lưu mã tra cứu
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default ResidentSuggestionPanel;
