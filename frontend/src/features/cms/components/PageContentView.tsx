import React from 'react';
import { Link } from 'react-router-dom';
import type { PageNode } from '../api';

// Pinta el documento de una página recorriendo su árbol de nodos. No inserta
// HTML: un nodo que no esté en esta lista simplemente no se dibuja.

function withMarks(node: PageNode, key: number): React.ReactNode {
  let element: React.ReactNode = node.text ?? '';

  for (const mark of node.marks ?? []) {
    if (mark.type === 'bold') element = <strong>{element}</strong>;
    else if (mark.type === 'italic') element = <em>{element}</em>;
    else if (mark.type === 'underline') element = <u>{element}</u>;
    else if (mark.type === 'strike') element = <s>{element}</s>;
    else if (mark.type === 'link') {
      const href = String(mark.attrs?.href ?? '');
      // Los enlaces internos navegan sin recargar; los externos abren otra pestaña
      element =
        href.startsWith('/') && !href.startsWith('//') ? (
          <Link to={href}>{element}</Link>
        ) : /^(https?:\/\/|mailto:|tel:)/i.test(href) ? (
          <a href={href} target="_blank" rel="noopener noreferrer nofollow">
            {element}
          </a>
        ) : (
          element
        );
    }
  }

  return <React.Fragment key={key}>{element}</React.Fragment>;
}

function render(node: PageNode, key: number): React.ReactNode {
  const children = (node.content ?? []).map(render);

  switch (node.type) {
    case 'text':
      return withMarks(node, key);
    case 'paragraph':
      return <p key={key}>{children.length > 0 ? children : <br />}</p>;
    case 'heading':
      return node.attrs?.level === 3 ? <h3 key={key}>{children}</h3> : <h2 key={key}>{children}</h2>;
    case 'bulletList':
      return <ul key={key}>{children}</ul>;
    case 'orderedList':
      return <ol key={key}>{children}</ol>;
    case 'listItem':
      return <li key={key}>{children}</li>;
    case 'blockquote':
      return <blockquote key={key}>{children}</blockquote>;
    case 'horizontalRule':
      return <hr key={key} />;
    case 'hardBreak':
      return <br key={key} />;
    case 'image': {
      const src = String(node.attrs?.src ?? '');
      // Solo archivos públicos de la propia academia
      return /^\/api\/v1\/public-files\/[0-9a-f-]{36}$/.test(src) ? <img key={key} src={src} alt={String(node.attrs?.alt ?? '')} loading="lazy" /> : null;
    }
    default:
      return null;
  }
}

export const PageContentView: React.FC<{ content: PageNode }> = ({ content }) => <div className="page-content">{(content.content ?? []).map(render)}</div>;
