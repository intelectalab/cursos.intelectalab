import React, { useRef, useState } from 'react';
import { EditorContent, useEditor, type Editor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';
import {
  Bold,
  Heading2,
  Heading3,
  ImagePlus,
  Italic,
  Link2,
  Link2Off,
  List,
  ListOrdered,
  Minus,
  Quote,
  Redo2,
  Underline,
  Undo2,
  type LucideIcon,
} from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, INPUT_CLASS } from '../../../shared/ui';
import { cmsApi, type ImageRules, type PageNode } from '../api';

const Tool: React.FC<{ icon: LucideIcon; label: string; active?: boolean; disabled?: boolean; onClick: () => void }> = ({
  icon: Icon,
  label,
  active = false,
  disabled = false,
  onClick,
}) => (
  <button
    type="button"
    title={label}
    aria-label={label}
    aria-pressed={active}
    disabled={disabled}
    // Evita que el botón le quite la selección al texto
    onMouseDown={(event) => event.preventDefault()}
    onClick={onClick}
    className={`w-8 h-8 inline-flex items-center justify-center rounded-md cursor-pointer transition-colors disabled:opacity-40 disabled:cursor-not-allowed ${
      active ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-200/70 hover:text-slate-900'
    }`}
  >
    <Icon className="w-4 h-4" />
  </button>
);

const Divider = () => <span className="w-px h-5 bg-slate-200 mx-1" aria-hidden="true" />;

// Barra del editor: solo el formato que la página pública sabe mostrar
const Toolbar: React.FC<{ editor: Editor; imageRules: ImageRules; onError: (message: string | null) => void }> = ({ editor, imageRules, onError }) => {
  const fileInput = useRef<HTMLInputElement>(null);
  const [linking, setLinking] = useState(false);
  const [href, setHref] = useState('');
  const [uploading, setUploading] = useState(false);

  const openLink = () => {
    setHref((editor.getAttributes('link').href as string | undefined) ?? '');
    setLinking(true);
  };

  const applyLink = (event: React.FormEvent) => {
    event.preventDefault();
    const value = href.trim();
    const valid = /^(https?:\/\/|mailto:|tel:)/i.test(value) || (value.startsWith('/') && !value.startsWith('//'));

    if (!valid) {
      onError('El enlace debe empezar con https://, mailto:, tel: o con / si es una página de este sitio.');
      return;
    }
    onError(null);
    editor.chain().focus().extendMarkRange('link').setLink({ href: value }).run();
    setLinking(false);
  };

  const uploadImage = async (file: File | undefined) => {
    if (!file) return;
    setUploading(true);
    onError(null);
    try {
      const image = await cmsApi.uploadImage(file);
      editor.chain().focus().setImage({ src: image.url, alt: image.name }).run();
    } catch (err) {
      onError(apiErrorMessage(err, 'No se pudo subir la imagen.'));
    } finally {
      setUploading(false);
      if (fileInput.current) fileInput.current.value = '';
    }
  };

  return (
    <div className="border-b border-slate-200/80 bg-slate-50/80 rounded-t-2xl">
      <div className="flex flex-wrap items-center gap-0.5 px-2.5 py-2" role="toolbar" aria-label="Formato del texto">
        <Tool icon={Heading2} label="Título" active={editor.isActive('heading', { level: 2 })} onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()} />
        <Tool icon={Heading3} label="Subtítulo" active={editor.isActive('heading', { level: 3 })} onClick={() => editor.chain().focus().toggleHeading({ level: 3 }).run()} />
        <Divider />
        <Tool icon={Bold} label="Negrita" active={editor.isActive('bold')} onClick={() => editor.chain().focus().toggleBold().run()} />
        <Tool icon={Italic} label="Cursiva" active={editor.isActive('italic')} onClick={() => editor.chain().focus().toggleItalic().run()} />
        <Tool icon={Underline} label="Subrayado" active={editor.isActive('underline')} onClick={() => editor.chain().focus().toggleUnderline().run()} />
        <Divider />
        <Tool icon={List} label="Lista con viñetas" active={editor.isActive('bulletList')} onClick={() => editor.chain().focus().toggleBulletList().run()} />
        <Tool icon={ListOrdered} label="Lista numerada" active={editor.isActive('orderedList')} onClick={() => editor.chain().focus().toggleOrderedList().run()} />
        <Tool icon={Quote} label="Cita" active={editor.isActive('blockquote')} onClick={() => editor.chain().focus().toggleBlockquote().run()} />
        <Tool icon={Minus} label="Línea divisoria" onClick={() => editor.chain().focus().setHorizontalRule().run()} />
        <Divider />
        <Tool icon={Link2} label="Enlace" active={editor.isActive('link')} onClick={openLink} />
        <Tool icon={Link2Off} label="Quitar enlace" disabled={!editor.isActive('link')} onClick={() => editor.chain().focus().unsetLink().run()} />
        <Tool icon={ImagePlus} label={`Imagen (${imageRules.extensions.join(', ')}; hasta ${Math.round(imageRules.max_kb / 1024)} MB)`} disabled={uploading} onClick={() => fileInput.current?.click()} />
        <input ref={fileInput} type="file" hidden accept={imageRules.extensions.map((extension) => `.${extension}`).join(',')} onChange={(event) => void uploadImage(event.target.files?.[0])} />
        <Divider />
        <Tool icon={Undo2} label="Deshacer" disabled={!editor.can().undo()} onClick={() => editor.chain().focus().undo().run()} />
        <Tool icon={Redo2} label="Rehacer" disabled={!editor.can().redo()} onClick={() => editor.chain().focus().redo().run()} />
      </div>
      {linking && (
        <form onSubmit={applyLink} className="flex flex-wrap items-center gap-2 px-3 pb-2.5">
          <input
            className={`${INPUT_CLASS} flex-1 min-w-[200px]`}
            placeholder="https://… o /catalog"
            aria-label="Dirección del enlace"
            autoFocus
            value={href}
            onChange={(event) => setHref(event.target.value)}
          />
          <Button size="sm" variant="primary" type="submit">
            Aplicar
          </Button>
          <Button size="sm" onClick={() => setLinking(false)}>
            Cancelar
          </Button>
        </form>
      )}
    </div>
  );
};

// Editor del contenido de una página. Entrega el documento como árbol de nodos, no como HTML.
export const PageEditor: React.FC<{
  content: PageNode;
  imageRules: ImageRules;
  editable: boolean;
  onChange: (content: PageNode) => void;
}> = ({ content, imageRules, editable, onChange }) => {
  const [error, setError] = useState<string | null>(null);

  const editor = useEditor({
    extensions: [
      StarterKit.configure({
        heading: { levels: [2, 3] },
        code: false,
        codeBlock: false,
        // Un clic dentro del editor edita el enlace, no navega
        link: { openOnClick: false, autolink: true, protocols: ['mailto', 'tel'] },
      }),
      Image.configure({ inline: false }),
    ],
    content,
    editable,
    // La barra refleja el formato de donde está el cursor
    shouldRerenderOnTransaction: true,
    editorProps: { attributes: { class: 'page-content', 'aria-label': 'Contenido de la página' } },
    onUpdate: ({ editor: current }) => onChange(current.getJSON() as PageNode),
  });

  if (!editor) return null;

  return (
    <div>
      {editable && <Toolbar editor={editor} imageRules={imageRules} onError={setError} />}
      {error && (
        <div className="px-4 pt-3">
          <Alert>{error}</Alert>
        </div>
      )}
      <EditorContent editor={editor} />
    </div>
  );
};
