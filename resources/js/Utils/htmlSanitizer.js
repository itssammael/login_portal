/**
 * HTML Sanitizer & Clean Utilities for Form Builder Rich Content
 */

const ALLOWED_TAGS = new Set([
    'P', 'B', 'STRONG', 'I', 'EM', 'U', 'S', 'STRIKE', 'SPAN', 'DIV',
    'UL', 'OL', 'LI', 'TABLE', 'THEAD', 'TBODY', 'TR', 'TH', 'TD',
    'BR', 'HR', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6'
]);

const ALLOWED_STYLES = new Set([
    'text-align', 'font-weight', 'font-style', 'text-decoration'
]);

const ALLOWED_ALIGNMENTS = new Set([
    'left', 'center', 'right', 'justify'
]);

/**
 * Sanitize an inline style string to only allow safe text formatting properties.
 *
 * @param {string} styleStr
 * @returns {string}
 */
function sanitizeStyle(styleStr) {
    if (!styleStr || typeof styleStr !== 'string') return '';
    const cleanStyles = [];
    const declarations = styleStr.split(';');

    for (const decl of declarations) {
        const parts = decl.split(':');
        if (parts.length === 2) {
            const prop = parts[0].trim().toLowerCase();
            const val = parts[1].trim().toLowerCase();

            if (prop === 'text-align' && ALLOWED_ALIGNMENTS.has(val)) {
                cleanStyles.push(`text-align: ${val}`);
            } else if (prop === 'font-weight' && (val === 'bold' || val === 'normal' || val === '700' || val === '400')) {
                cleanStyles.push(`font-weight: ${val}`);
            } else if (prop === 'font-style' && (val === 'italic' || val === 'normal')) {
                cleanStyles.push(`font-style: ${val}`);
            } else if (prop === 'text-decoration' && (val === 'underline' || val === 'line-through' || val === 'none')) {
                cleanStyles.push(`text-decoration: ${val}`);
            }
        }
    }

    return cleanStyles.join('; ');
}

/**
 * Recursively clean a DOM node, removing dangerous tags and attributes.
 *
 * @param {Node} node
 */
function cleanNode(node) {
    if (node.nodeType === Node.TEXT_NODE) {
        return;
    }

    if (node.nodeType !== Node.ELEMENT_NODE) {
        node.parentNode?.removeChild(node);
        return;
    }

    const tagName = node.tagName.toUpperCase();

    // Disallow dangerous elements outright
    if (['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'FORM', 'INPUT', 'TEXTAREA', 'BUTTON', 'LINK', 'META'].includes(tagName)) {
        node.parentNode?.removeChild(node);
        return;
    }

    // If tag is not in allowed list, unwrap its children or remove
    if (!ALLOWED_TAGS.has(tagName)) {
        const parent = node.parentNode;
        if (parent) {
            while (node.firstChild) {
                parent.insertBefore(node.firstChild, node);
            }
            parent.removeChild(node);
        }
        return;
    }

    // Clean attributes
    const attrs = Array.from(node.attributes);
    for (const attr of attrs) {
        const name = attr.name.toLowerCase();

        // Disallow all event handlers (onclick, onerror, etc.)
        if (name.startsWith('on')) {
            node.removeAttribute(name);
            continue;
        }

        // Only allow safe attributes
        if (name === 'style') {
            const safeStyle = sanitizeStyle(attr.value);
            if (safeStyle) {
                node.setAttribute('style', safeStyle);
            } else {
                node.removeAttribute('style');
            }
        } else if (name === 'class') {
            // Keep safe Tailwind classes for alignment or tables
            const classes = attr.value.split(/\s+/).filter(c => {
                return /^(text-(left|center|right|justify)|font-(bold|semibold|normal)|italic|underline|line-through|border.*|bg-.*|p-.*|px-.*|py-.*|divide-.*|rounded.*|w-.*|overflow-.*)$/.test(c);
            });
            if (classes.length > 0) {
                node.setAttribute('class', classes.join(' '));
            } else {
                node.removeAttribute('class');
            }
        } else if (['colspan', 'rowspan', 'scope'].includes(name) && ['TH', 'TD'].includes(tagName)) {
            const num = parseInt(attr.value, 10);
            if (num > 0 && num <= 20) {
                node.setAttribute(name, String(num));
            } else {
                node.removeAttribute(name);
            }
        } else {
            // Remove any other attribute
            node.removeAttribute(name);
        }
    }

    // Recursively clean children
    const children = Array.from(node.childNodes);
    for (const child of children) {
        cleanNode(child);
    }
}

/**
 * Sanitize an HTML string, keeping only safe formatting tags and styles.
 *
 * @param {string|null|undefined} rawHtml
 * @returns {string}
 */
export function sanitizeHtml(rawHtml) {
    if (!rawHtml || typeof rawHtml !== 'string') {
        return '';
    }

    // Fast check: if no html tags at all, return as-is
    if (!/[<>]/.test(rawHtml)) {
        return rawHtml;
    }

    if (typeof window === 'undefined' || !window.DOMParser) {
        // Fallback for SSR or non-browser environments: strip tags
        return stripHtml(rawHtml);
    }

    try {
        const parser = new DOMParser();
        const doc = parser.parseFromString(`<body>${rawHtml}</body>`, 'text/html');
        const body = doc.body;

        cleanNode(body);

        return body.innerHTML;
    } catch {
        return stripHtml(rawHtml);
    }
}

/**
 * Strip all HTML tags, returning plain text.
 *
 * @param {string|null|undefined} rawHtml
 * @returns {string}
 */
export function stripHtml(rawHtml) {
    if (!rawHtml || typeof rawHtml !== 'string') {
        return '';
    }

    if (typeof window !== 'undefined' && window.DOMParser) {
        try {
            const parser = new DOMParser();
            const doc = parser.parseFromString(`<body>${rawHtml}</body>`, 'text/html');
            return doc.body.textContent || '';
        } catch {
            // regex fallback below
        }
    }

    return rawHtml.replace(/<[^>]+>/g, '').replace(/&nbsp;/g, ' ').trim();
}

/**
 * Determine if a string contains HTML markup.
 *
 * @param {string|null|undefined} str
 * @returns {boolean}
 */
export function hasHtmlTags(str) {
    if (!str || typeof str !== 'string') return false;
    return /<[a-z][\s\S]*>/i.test(str);
}
