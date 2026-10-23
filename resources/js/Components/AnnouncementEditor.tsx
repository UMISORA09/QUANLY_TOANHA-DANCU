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
  const tools = [
    ['Đậm', 'bold', () => editor.chain().focus().toggleBold().run()], ['Nghiêng', 'italic', () => editor.chain().focus().toggleItalic().run()],
    ['Gạch chân', 'underline', () => editor.chain().focus().toggleUnderline().run()], ['Tiêu đề 2', 'heading', () => editor.chain().focus().toggleHeading({ level: 2 }).run()],
    ['Tiêu đề 3', 'heading', () => editor.chain().focus().toggleHeading({ level: 3 }).run()], ['Danh sách dấu đầu dòng', 'bulletList', () => editor.chain().focus().toggleBulletList().run()],
    ['Danh sách đánh số', 'orderedList', () => editor.chain().focus().toggleOrderedList().run()], ['Trích dẫn', 'blockquote', () => editor.chain().focus().toggleBlockquote().run()],
    ['Hoàn tác', '', () => editor.chain().focus().undo().run()], ['Làm lại', '', () => editor.chain().focus().redo().run()],
  ] as const;
  return <div className="announcement-editor">
    <div role="group" aria-label="Định dạng nội dung" className="announcement-editor__toolbar">
      {tools.map(([label, mark, command]) => <button key={label} type="button" disabled={disabled} aria-label={label} aria-pressed={mark ? editor.isActive(mark, label === 'Tiêu đề 2' ? { level: 2 } : label === 'Tiêu đề 3' ? { level: 3 } : undefined) : undefined} onClick={() => command()}>{label}</button>)}
      <button type="button" disabled={disabled} onClick={() => { setLink(editor.getAttributes('link').href || ''); setLinkError(''); }}>Liên kết</button>
      <button type="button" disabled={disabled || !editor.isActive('link')} onClick={() => editor.chain().focus().unsetLink().run()}>Bỏ liên kết</button>
    </div>
    {link !== null && <div className="announcement-editor__link"><label>Địa chỉ liên kết<input type="url" value={link} disabled={disabled} onChange={(event) => setLink(event.target.value)} placeholder="https://…" /></label><button type="button" disabled={disabled} onClick={() => { try { const url = new URL(link); if (!['http:', 'https:'].includes(url.protocol)) throw new Error(); editor.chain().focus().extendMarkRange('link').setLink({ href: url.href }).run(); setLink(null); } catch { setLinkError('Chỉ nhận liên kết HTTP hoặc HTTPS hợp lệ.'); } }}>Áp dụng liên kết</button><button type="button" onClick={() => setLink(null)}>Đóng liên kết</button>{linkError && <p role="alert">{linkError}</p>}</div>}
    <EditorContent editor={editor} />
  </div>;
}
