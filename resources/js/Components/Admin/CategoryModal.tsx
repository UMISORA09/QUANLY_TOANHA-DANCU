import React, { useState, useEffect } from 'react';
import { X, Plus, Edit2, Trash2, Tag, Check, AlertCircle, Sparkles } from 'lucide-react';
import { api, Category } from '../../Services/api';

interface CategoryModalProps {
  isOpen: boolean;
  onClose: () => void;
  onChanged: () => void;
}

export const CategoryModal: React.FC<CategoryModalProps> = ({ isOpen, onClose, onChanged }) => {
  const [categories, setCategories] = useState<Category[]>([]);
  const [loading, setLoading] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [name, setName] = useState('');
  const [code, setCode] = useState('');
  const [description, setDescription] = useState('');
  const [iconName, setIconName] = useState('Tag');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  const fetchCategories = async () => {
    setLoading(true);
    try {
      const data = await api.getCategories();
      setCategories(data);
    } catch (err: any) {
      setErrorMessage(err.message || 'Không thể tải danh sách danh mục.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (isOpen) {
      fetchCategories();
      resetForm();
    }
  }, [isOpen]);

  const resetForm = () => {
    setEditingId(null);
    setName('');
    setCode('');
    setDescription('');
    setIconName('Tag');
    setErrorMessage(null);
  };

  const handleEdit = (cat: Category) => {
    setEditingId(cat.id);
    setName(cat.category_name);
    setCode(cat.category_code);
    setDescription(cat.description || '');
    setIconName(cat.icon_name || 'Tag');
    setErrorMessage(null);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!name.trim() || !code.trim()) {
      setErrorMessage('Vui lòng điền tên và mã danh mục.');
      return;
    }

    setIsSubmitting(true);
    setErrorMessage(null);

    try {
      if (editingId) {
        await api.updateCategory(editingId, {
          category_name: name.trim(),
          category_code: code.trim().toUpperCase(),
          icon_name: iconName,
          description: description.trim() || null,
        });
        setSuccessMessage('Đã cập nhật danh mục thành công!');
      } else {
        await api.createCategory({
          category_name: name.trim(),
          category_code: code.trim().toUpperCase(),
          icon_name: iconName,
          description: description.trim() || null,
        });
        setSuccessMessage('Đã thêm danh mục mới thành công!');
      }
      resetForm();
      await fetchCategories();
      onChanged();
      setTimeout(() => setSuccessMessage(null), 3000);
    } catch (err: any) {
      setErrorMessage(err.message || 'Đã xảy ra lỗi khi lưu danh mục.');
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleDelete = async (cat: Category) => {
    if (cat.amenities_count > 0) {
      setErrorMessage(`Không thể xóa danh mục '${cat.category_name}' vì đang có ${cat.amenities_count} tiện ích trực thuộc!`);
      return;
    }
    if (!confirm(`Bạn có chắc muốn xóa danh mục '${cat.category_name}'?`)) {
      return;
    }

    setLoading(true);
    try {
      await api.deleteCategory(cat.id);
      setSuccessMessage('Đã xóa danh mục.');
      await fetchCategories();
      onChanged();
      setTimeout(() => setSuccessMessage(null), 3000);
    } catch (err: any) {
      setErrorMessage(err.message || 'Lỗi khi xóa danh mục.');
    } finally {
      setLoading(false);
    }
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4">
      {/* Backdrop */}
      <div onClick={onClose} className="fixed inset-0 bg-neutral-950/45 backdrop-blur-md animate-in fade-in" />

      {/* Modal Content */}
      <div className="relative w-full max-w-2xl bg-white/95 backdrop-blur-2xl border border-white/90 rounded-2xl shadow-2xl p-6 text-neutral-900 z-10 max-h-[90vh] flex flex-col">
        {/* Header */}
        <div className="flex items-center justify-between pb-4 border-b border-neutral-200/80 shrink-0">
          <div className="flex items-center gap-2.5">
            <div className="w-8 h-8 rounded-lg bg-neutral-900 text-white flex items-center justify-center shadow-xs">
              <Tag className="w-4 h-4" />
            </div>
            <div>
              <h2 className="text-base font-bold text-neutral-950 leading-tight">Quản lý Danh mục Tiện ích</h2>
              <p className="text-xs text-neutral-500">Phân loại các tiện ích chung cư & câu lạc bộ</p>
            </div>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="p-1.5 text-neutral-400 hover:text-neutral-900 rounded-lg hover:bg-neutral-100 transition-colors"
          >
            <X className="w-4 h-4" />
          </button>
        </div>

        {/* Alerts */}
        {errorMessage && (
          <div className="mt-3 p-3 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 text-xs flex items-center gap-2">
            <AlertCircle className="w-4 h-4 text-rose-600 shrink-0" />
            <span>{errorMessage}</span>
          </div>
        )}
        {successMessage && (
          <div className="mt-3 p-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs flex items-center gap-2">
            <Sparkles className="w-4 h-4 text-emerald-600 shrink-0" />
            <span>{successMessage}</span>
          </div>
        )}

        <div className="mt-4 flex-1 overflow-y-auto space-y-6 pr-1">
          {/* Form */}
          <form onSubmit={handleSubmit} className="p-4 bg-neutral-50/80 border border-neutral-200/70 rounded-xl space-y-3">
            <div className="text-xs font-bold text-neutral-800 uppercase tracking-wider">
              {editingId ? 'Chỉnh sửa danh mục' : 'Thêm mới danh mục'}
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label className="block text-xs font-semibold text-neutral-700 mb-1">
                  Mã danh mục <span className="text-rose-500">*</span>
                </label>
                <input
                  type="text"
                  required
                  placeholder="VD: BBQ, GYM, POOL"
                  value={code}
                  onChange={(e) => setCode(e.target.value)}
                  className="w-full px-3 py-2 text-xs border border-neutral-300 rounded-lg bg-white uppercase font-mono focus:outline-none focus:ring-1 focus:ring-neutral-900"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-neutral-700 mb-1">
                  Tên danh mục <span className="text-rose-500">*</span>
                </label>
                <input
                  type="text"
                  required
                  placeholder="VD: Vườn nướng BBQ ngoài trời"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  className="w-full px-3 py-2 text-xs border border-neutral-300 rounded-lg bg-white focus:outline-none focus:ring-1 focus:ring-neutral-900"
                />
              </div>
            </div>

            <div>
              <label className="block text-xs font-semibold text-neutral-700 mb-1">Mô tả danh mục</label>
              <input
                type="text"
                placeholder="Mô tả chi tiết danh mục..."
                value={description}
                onChange={(e) => setDescription(e.target.value)}
                className="w-full px-3 py-2 text-xs border border-neutral-300 rounded-lg bg-white focus:outline-none focus:ring-1 focus:ring-neutral-900"
              />
            </div>

            <div className="flex items-center justify-end gap-2 pt-1">
              {editingId && (
                <button
                  type="button"
                  onClick={resetForm}
                  className="px-3 py-1.5 text-xs text-neutral-600 hover:bg-neutral-200/70 rounded-lg transition-colors"
                >
                  Hủy sửa
                </button>
              )}
              <button
                type="submit"
                disabled={isSubmitting}
                className="px-4 py-2 bg-neutral-950 hover:bg-neutral-800 active:scale-95 text-white text-xs font-semibold rounded-lg flex items-center gap-1.5 transition-all shadow-xs cursor-pointer"
              >
                {isSubmitting ? (
                  <span className="w-3.5 h-3.5 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                ) : editingId ? (
                  <Check className="w-3.5 h-3.5" />
                ) : (
                  <Plus className="w-3.5 h-3.5" />
                )}
                <span>{editingId ? 'Cập nhật' : 'Thêm danh mục'}</span>
              </button>
            </div>
          </form>

          {/* Categories List */}
          <div>
            <div className="text-xs font-bold text-neutral-800 uppercase tracking-wider mb-2.5">
              Danh sách hiện có ({categories.length})
            </div>

            {loading ? (
              <div className="py-8 text-center text-neutral-400 text-xs flex items-center justify-center gap-2">
                <span className="w-4 h-4 border-2 border-neutral-400 border-t-neutral-800 rounded-full animate-spin" />
                <span>Đang tải danh mục...</span>
              </div>
            ) : categories.length === 0 ? (
              <div className="py-8 text-center text-neutral-400 text-xs border border-dashed border-neutral-200 rounded-xl">
                Chưa có danh mục nào. Hãy tạo danh mục đầu tiên ở biểu mẫu phía trên!
              </div>
            ) : (
              <div className="space-y-2">
                {categories.map((cat) => (
                  <div
                    key={cat.id}
                    className="p-3 bg-white border border-neutral-200/80 rounded-xl flex items-center justify-between hover:border-neutral-300 transition-colors shadow-2xs"
                  >
                    <div className="min-w-0 pr-3">
                      <div className="flex items-center gap-2">
                        <span className="font-bold text-xs text-neutral-950 truncate">{cat.category_name}</span>
                        <span className="px-1.5 py-0.5 rounded text-[10px] font-mono bg-neutral-100 text-neutral-700 font-semibold border border-neutral-200 whitespace-nowrap">
                          {cat.category_code}
                        </span>
                        <span className="px-1.5 py-0.5 rounded-full text-[10px] bg-sky-50 text-sky-700 border border-sky-200 whitespace-nowrap">
                          {cat.amenities_count} tiện ích
                        </span>
                      </div>
                      {cat.description && (
                        <p className="text-[11px] text-neutral-500 truncate mt-0.5">{cat.description}</p>
                      )}
                    </div>

                    <div className="flex items-center gap-1.5 shrink-0">
                      <button
                        type="button"
                        onClick={() => handleEdit(cat)}
                        className="p-1.5 text-neutral-500 hover:text-neutral-900 hover:bg-neutral-100 rounded-lg transition-colors cursor-pointer"
                        title="Chỉnh sửa"
                      >
                        <Edit2 className="w-3.5 h-3.5" />
                      </button>
                      <button
                        type="button"
                        onClick={() => handleDelete(cat)}
                        disabled={cat.amenities_count > 0}
                        className={`p-1.5 rounded-lg transition-colors cursor-pointer ${
                          cat.amenities_count > 0
                            ? 'text-neutral-300 cursor-not-allowed'
                            : 'text-rose-500 hover:text-rose-700 hover:bg-rose-50'
                        }`}
                        title={
                          cat.amenities_count > 0
                            ? 'Không thể xóa danh mục đang có tiện ích'
                            : 'Xóa danh mục'
                        }
                      >
                        <Trash2 className="w-3.5 h-3.5" />
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>

        {/* Footer */}
        <div className="mt-4 pt-3 border-t border-neutral-200/80 flex justify-end shrink-0">
          <button
            type="button"
            onClick={onClose}
            className="px-4 py-2 text-xs font-semibold text-neutral-700 hover:bg-neutral-100 rounded-lg transition-colors"
          >
            Đóng
          </button>
        </div>
      </div>
    </div>
  );
};
