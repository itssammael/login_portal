/**
 * HTML Sanitizer & Clean Utilities for Form Builder Rich Content
 */

const ALLOWED_TAGS = new Set([
    'P', 'B', 'STRONG', 'I', 'EM', 'U', 'S', 'STRIKE', 'SPAN', 'DIV',
    'UL', 'OL', 'LI', 'TABLE', 'THEAD', 'TBODY', 'TR', 'TH', 'TD',
    'BR', 'HR', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6',
    'FONT', 'MARK', 'SUB', 'SUP', 'CODE', 'PRE', 'CAPTION', 'COL', 'COLGROUP'
]);

const ALLOWED_STYLES = new Set([
    'text-align', 'font-weight', 'font-style', 'text-decoration',
    'color', 'background-color', 'font-size', 'font-family',
    'line-height', 'width', 'min-width', 'max-width', 'border', 'border-color'
]);

const ALLOWED_ALIGNMENTS = new Set([
    'left', 'center', 'right', 'justify'
]);

// Map HTML font size numbers (1-7) to CSS rem values
const FONT_SIZE_MAP = {
    '1': '0.75rem',
    '2': '0.875rem',
    '3': '1rem',
    '4': '1.125rem',
    '5': '1.25rem',
    '6': '1.5rem',
    '7': '2rem',
};

/**
 * Validate that a color string is safe (hex, rgb, rgba, hsl, or named css color).
 *
 * @param {string} val
 * @returns {boolean}
 */
function isValidColor(val) {
    if (!val || typeof val !== 'string') return false;
    val = val.trim();
    // Disallow url, expression, or javascript
    if (/[\(\)\/\\;]/.test(val) && !/^rgba?\([0-9\s.,%]+\)$/i.test(val) && !/^hsla?\([0-9\s.,%]+\)$/i.test(val)) {
        return false;
    }
    return /^#([0-9a-f]{3,8})$/i.test(val)
        || /^rgba?\([0-9\s.,%]+\)$/i.test(val)
        || /^hsla?\([0-9\s.,%]+\)$/i.test(val)
        || /^[a-z]+$/i.test(val);
}

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
        if (parts.length >= 2) {
            const prop = parts[0].trim().toLowerCase();
            const val = parts.slice(1).join(':').trim();
            const valLower = val.toLowerCase();

            if (prop === 'text-align' && ALLOWED_ALIGNMENTS.has(valLower)) {
                cleanStyles.push(`text-align: ${valLower}`);
            } else if (prop === 'font-weight' && /^(bold|normal|lighter|bolder|[1-9]00)$/i.test(val)) {
                cleanStyles.push(`font-weight: ${valLower}`);
            } else if (prop === 'font-style' && /^(italic|normal|oblique)$/i.test(val)) {
                cleanStyles.push(`font-style: ${valLower}`);
            } else if (prop === 'text-decoration' && /^(underline|line-through|none)(\s+(solid|dotted|dashed))?$/i.test(val)) {
                cleanStyles.push(`text-decoration: ${valLower}`);
            } else if ((prop === 'color' || prop === 'background-color') && isValidColor(val)) {
                cleanStyles.push(`${prop}: ${val}`);
            } else if (prop === 'font-size' && /^(\d+(\.\d+)?(px|pt|rem|em|%)|small|medium|large|x-large|xx-large|smaller|larger)$/i.test(val)) {
                cleanStyles.push(`font-size: ${valLower}`);
            } else if (prop === 'font-family' && /^[a-zA-Z0-9\s,\-_'"]+$/.test(val) && val.length < 100) {
                cleanStyles.push(`font-family: ${val}`);
            } else if (prop === 'line-height' && /^\d+(\.\d+)?(px|rem|em|%)?$/.test(val)) {
                cleanStyles.push(`line-height: ${val}`);
            } else if ((prop === 'width' || prop === 'min-width' || prop === 'max-width') && /^(\d+(\.\d+)?(px|rem|em|%)|auto)$/i.test(val)) {
                cleanStyles.push(`${prop}: ${valLower}`);
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

    // Convert legacy <font> tags into <span style="..."> for universal cross-browser preservation
    if (tagName === 'FONT') {
        const span = node.ownerDocument.createElement('span');
        const fontStyles = [];

        if (node.hasAttribute('color') && isValidColor(node.getAttribute('color'))) {
            fontStyles.push(`color: ${node.getAttribute('color')}`);
        }
        if (node.hasAttribute('size')) {
            const sizeVal = node.getAttribute('size');
            if (FONT_SIZE_MAP[sizeVal]) {
                fontStyles.push(`font-size: ${FONT_SIZE_MAP[sizeVal]}`);
            } else if (/^\d+(px|pt|rem|em|%)$/i.test(sizeVal)) {
                fontStyles.push(`font-size: ${sizeVal}`);
            }
        }
        if (node.hasAttribute('face')) {
            const faceVal = node.getAttribute('face');
            if (/^[a-zA-Z0-9\s,\-_'"]+$/.test(faceVal)) {
                fontStyles.push(`font-family: ${faceVal}`);
            }
        }
        if (node.hasAttribute('style')) {
            const existingStyle = sanitizeStyle(node.getAttribute('style'));
            if (existingStyle) {
                fontStyles.push(existingStyle);
            }
        }

        if (fontStyles.length > 0) {
            span.setAttribute('style', fontStyles.join('; '));
        }

        // Move children to new span
        while (node.firstChild) {
            span.appendChild(node.firstChild);
        }
        node.parentNode?.replaceChild(span, node);
        cleanNode(span);
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

    // Convert align attribute on block elements to style="text-align: ..."
    if (node.hasAttribute('align')) {
        const alignVal = (node.getAttribute('align') || '').toLowerCase().trim();
        if (ALLOWED_ALIGNMENTS.has(alignVal)) {
            const currentStyle = node.getAttribute('style') || '';
            const updatedStyle = currentStyle ? `${currentStyle}; text-align: ${alignVal}` : `text-align: ${alignVal}`;
            node.setAttribute('style', updatedStyle);
        }
        node.removeAttribute('align');
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
            // Keep safe Tailwind classes for alignment, typography, colors, borders, and tables
            const classes = attr.value.split(/\s+/).filter(c => {
                return /^(form-rich-table|form-rich-content|text-(left|center|right|justify|xs|sm|base|lg|xl|2xl|3xl)|text-[a-z0-9\-_/]+|font-(bold|semibold|normal|medium|extrabold|black|serif|mono|sans)|italic|underline|line-through|border.*|bg-.*|p-.*|px-.*|py-.*|pt-.*|pb-.*|pl-.*|pr-.*|m-.*|my-.*|mx-.*|mt-.*|mb-.*|divide-.*|rounded.*|w-.*|min-w-.*|max-w-.*|h-.*|overflow-.*|hover:.*|shadow-.*)$/.test(c);
            });
            if (classes.length > 0) {
                node.setAttribute('class', classes.join(' '));
            } else {
                node.removeAttribute('class');
            }
        } else if (['colspan', 'rowspan', 'scope'].includes(name) && ['TH', 'TD'].includes(tagName)) {
            const num = parseInt(attr.value, 10);
            if (num > 0 && num <= 50) {
                node.setAttribute(name, String(num));
            } else if (name === 'scope' && ['col', 'row', 'colgroup', 'rowgroup'].includes(attr.value.toLowerCase())) {
                node.setAttribute('scope', attr.value.toLowerCase());
            } else {
                node.removeAttribute(name);
            }
        } else if (['border', 'cellpadding', 'cellspacing', 'width'].includes(name) && tagName === 'TABLE') {
            const val = attr.value.trim();
            if (/^\d+(%|px)?$/.test(val)) {
                node.setAttribute(name, val);
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
