import { Bold, Italic, Underline, Heading2, Heading3, List, ListOrdered, Quote, Undo2, Redo2, Link2, Unlink2 } from 'lucide-react';
import React, { useEffect, useState } from 'react';
import { EditorContent, useEditor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';

export function AnnouncementEditor({ value, onChange, disabled, invalid, errorId }: { value: string; onChange: (html: string) => void; disabled: boolean; invalid: boolean; errorId: string }) {
  const [link, setLink] = useState<string | null>(null);
  const [linkError, setLinkError] = useState('');
  const editor = useEditor({
    extensions: [StarterKit.configure({ heading: { levels: [2, 3] }, code: false, codeBlock: false, horizontalRule: false, strike: false, link: { openOnClick: false, protocols: ['http', 'https'], HTMLAttributes: { target: '_blank', rel: 'noopener noreferrer' } } })],
    content: value, immediatelyRender: false, shouldRerenderOnTransaction: true,
    onUpdate: ({ editor }) => onChange(editor.getHTML()),
    editorProps: { attributes: { role: 'textbox', 'aria-label': 'Nội dung bảng tin', 'aria-multiline': 'true' } },
  });
  useEffect(() => { if (editor && value !== editor.getHTML()) editor.commands.setContent(value, { emitUpdate: false }); }, [editor, value]);
  useEffect(() => { editor?.setEditable(!disabled); }, [editor, disabled]);
  useEffect(() => { if (editor) { editor.view.dom.setAttribute('aria-invalid', String(invalid)); editor.view.dom.setAttribute('aria-describedby', errorId); } }, [editor, invalid, errorId]);
  if (!editor) return <p role="status">Đang mở trình soạn thảo…</p>;
  const groups = [
    { label: 'Chữ', tools: [
      { label: 'Đậm', icon: Bold, active: editor.isActive('bold'), run: () => editor.chain().focus().toggleBold().run() },
      { label: 'Nghiêng', icon: Italic, active: editor.isActive('italic'), run: () => editor.chain().focus().toggleItalic().run() },
      { label: 'Gạch chân', icon: Underline, active: editor.isActive('underline'), run: () => editor.chain().focus().toggleUnderline().run() },
    ] },
    { label: 'Tiêu đề', tools: [
      { label: 'Tiêu đề 2', icon: Heading2, active: editor.isActive('heading', { level: 2 }), run: () => editor.chain().focus().toggleHeading({ level: 2 }).run() },
      { label: 'Tiêu đề 3', icon: Heading3, active: editor.isActive('heading', { level: 3 }), run: () => editor.chain().focus().toggleHeading({ level: 3 }).run() },
    ] },
    { label: 'Đoạn văn', tools: [
      { label: 'Danh sách dấu đầu dòng', icon: List, active: editor.isActive('bulletList'), run: () => editor.chain().focus().toggleBulletList().run() },
      { label: 'Danh sách đánh số', icon: ListOrdered, active: editor.isActive('orderedList'), run: () => editor.chain().focus().toggleOrderedList().run() },
      { label: 'Trích dẫn', icon: Quote, active: editor.isActive('blockquote'), run: () => editor.chain().focus().toggleBlockquote().run() },
    ] },
    { label: 'Liên kết', tools: [
      { label: 'Liên kết', icon: Link2, active: editor.isActive('link'), run: () => { setLink(editor.getAttributes('link').href || ''); setLinkError(''); } },
      { label: 'Bỏ liên kết', icon: Unlink2, disabled: !editor.isActive('link'), run: () => editor.chain().focus().unsetLink().run() },
    ] },
    { label: 'Hoàn tác', tools: [
      { label: 'Hoàn tác', icon: Undo2, disabled: !editor.can().undo(), run: () => editor.chain().focus().undo().run() },
      { label: 'Làm lại', icon: Redo2, disabled: !editor.can().redo(), run: () => editor.chain().focus().redo().run() },
    ] },
  ];
  return <div className="announcement-editor">
    <div role="group" aria-label="Định dạng nội dung" className="announcement-editor__toolbar">
      {groups.map(group => <div key={group.label} role="group" aria-label={'Nhóm định dạng ' + group.label} className="announcement-editor__tool-group">
        <div className="announcement-editor__tools">{group.tools.map(tool => <button key={tool.label} type="button" title={tool.label} aria-label={tool.label} disabled={disabled || ('disabled' in tool && tool.disabled)} aria-pressed={'active' in tool ? tool.active : undefined} onClick={tool.run}><tool.icon aria-hidden="true" size={20} /></button>)}</div>
        <span aria-hidden="true" className="announcement-editor__group-label">{group.label}</span>
      </div>)}
    </div>
    {link !== null && <div className="announcement-editor__link"><label>Địa chỉ liên kết<input type="url" value={link} disabled={disabled} onChange={(event) => setLink(event.target.value)} placeholder="https://…" /></label><button type="button" disabled={disabled} onClick={() => { try { const url = new URL(link); if (!['http:', 'https:'].includes(url.protocol)) throw new Error(); editor.chain().focus().extendMarkRange('link').setLink({ href: url.href }).run(); setLink(null); } catch { setLinkError('Chỉ nhận liên kết HTTP hoặc HTTPS hợp lệ.'); } }}>Áp dụng liên kết</button><button type="button" onClick={() => setLink(null)}>Đóng liên kết</button>{linkError && <p role="alert">{linkError}</p>}</div>}
    <EditorContent editor={editor} />
  </div>;
}
