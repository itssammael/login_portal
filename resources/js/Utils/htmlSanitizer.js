/**
 * HTML Sanitizer & Clean Utilities for Form Builder Rich Content
 */

const ALLOWED_TAGS = new Set([
    'P', 'B', 'STRONG', 'I', 'EM', 'U', 'INS', 'S', 'STRIKE', 'DEL', 'SPAN', 'DIV',
    'UL', 'OL', 'LI', 'TABLE', 'THEAD', 'TBODY', 'TFOOT', 'TR', 'TH', 'TD',
    'BR', 'HR', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6',
    'SUB', 'SUP', 'BLOCKQUOTE', 'PRE', 'CODE', 'MARK', 'SMALL', 'CAPTION', 'COL', 'COLGROUP'
]);

const ALLOWED_ALIGNMENTS = new Set([
    'left', 'center', 'right', 'justify'
]);

/**
 * Sanitize an inline style string to only allow safe text and table formatting properties.
 *
 * @param {string} styleStr
 * @param {string} [tagName='']
 * @returns {string}
 */
function sanitizeStyle(styleStr, tagName = '') {
    if (!styleStr || typeof styleStr !== 'string') return '';
    const cleanStyles = [];
    const declarations = styleStr.split(';');

    for (const decl of declarations) {
        const parts = decl.split(':');
        if (parts.length >= 2) {
            const prop = parts[0].trim().toLowerCase();
            const val = parts.slice(1).join(':').trim().toLowerCase();

            // Guard against script injection in styles
            if (/url\s*\(|expression\s*\(|javascript\s*:|behavior\s*:/i.test(val)) {
                continue;
            }

            if (prop === 'text-align') {
                const normalized = val.replace(/^-webkit-/, '');
                if (ALLOWED_ALIGNMENTS.has(normalized)) {
                    cleanStyles.push(`text-align: ${normalized}`);
                }
            } else if (prop === 'text-decoration' || prop === 'text-decoration-line') {
                const tokens = val.split(/\s+/).filter(Boolean);
                const allowedDecs = new Set(['underline', 'line-through', 'none', 'initial', 'inherit']);
                if (tokens.length > 0 && tokens.every(t => allowedDecs.has(t))) {
                    cleanStyles.push(`text-decoration: ${tokens.join(' ')}`);
                }
            } else if (prop === 'font-weight') {
                if (/^(bold|normal|bolder|lighter|[1-9]00)$/.test(val)) {
                    cleanStyles.push(`font-weight: ${val}`);
                }
            } else if (prop === 'font-style') {
                if (/^(italic|normal|oblique)$/.test(val)) {
                    cleanStyles.push(`font-style: ${val}`);
                }
            } else if (prop === 'font-size') {
                if (/^(\d+(\.\d+)?(px|rem|em|pt|%)|xx-small|x-small|small|medium|large|x-large|xx-large)$/.test(val)) {
                    cleanStyles.push(`font-size: ${val}`);
                }
            } else if (prop === 'color' || prop === 'background-color') {
                if (/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i.test(val) ||
                    /^(rgb|hsl)a?\(\s*[\d.%\s,]+\)$/i.test(val) ||
                    /^(transparent|currentColor|[a-z]+)$/i.test(val)) {
                    cleanStyles.push(`${prop}: ${val}`);
                }
            } else if (prop === 'border-collapse') {
                if (['collapse', 'separate'].includes(val)) {
                    cleanStyles.push(`border-collapse: ${val}`);
                }
            } else if (/^(border|border-(top|bottom|left|right))$/.test(prop)) {
                if (/^[\d.pxrem\s]+(solid|dashed|dotted|none)\s*(#[0-9a-f]{3,8}|rgba?\([^)]+\)|[a-z]+)?$/i.test(val) || val === 'none') {
                    cleanStyles.push(`${prop}: ${val}`);
                }
            } else if (/^(padding|margin)(-(top|bottom|left|right))?$/.test(prop)) {
                if (/^[\d.pxrem%auto\s]+$/.test(val)) {
                    cleanStyles.push(`${prop}: ${val}`);
                }
            } else if (/^(width|height|min-width|max-width)$/.test(prop)) {
                if (tagName === 'TABLE') {
                    if (prop === 'width') {
                        cleanStyles.push('width: 100%');
                    } else if (prop === 'max-width') {
                        cleanStyles.push('max-width: 100%');
                    }
                    // Strip fixed min-width and height on TABLE so it always fits containers
                    continue;
                }
                if (['TH', 'TD', 'COL', 'COLGROUP'].includes(tagName)) {
                    if (prop === 'width') {
                        if (/^\d+(\.\d+)?%$/.test(val)) {
                            cleanStyles.push(`width: ${val}`);
                        }
                        // Disallow fixed pixel/pt cell/col widths so columns adapt to container
                        continue;
                    }
                    if (prop === 'max-width') {
                        cleanStyles.push('max-width: 100%');
                        continue;
                    }
                    if (prop === 'min-width' || prop === 'height') {
                        // Disallow large min-widths or fixed heights on cells and cols
                        continue;
                    }
                }
                if (/^[\d.pxrem%auto]+$/.test(val)) {
                    cleanStyles.push(`${prop}: ${val}`);
                }
            } else if (prop === 'vertical-align') {
                if (['top', 'middle', 'bottom', 'baseline', 'sub', 'super'].includes(val)) {
                    cleanStyles.push(`vertical-align: ${val}`);
                }
            } else if (prop === 'list-style-type') {
                if (['disc', 'circle', 'square', 'decimal', 'lower-roman', 'upper-roman', 'lower-alpha', 'upper-alpha', 'none'].includes(val)) {
                    cleanStyles.push(`list-style-type: ${val}`);
                }
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
    if (['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'FORM', 'INPUT', 'TEXTAREA', 'BUTTON', 'LINK', 'META', 'APPLET', 'SVG', 'MATH', 'BASE'].includes(tagName)) {
        node.parentNode?.removeChild(node);
        return;
    }

    // Recursively clean children first
    const children = Array.from(node.childNodes);
    for (const child of children) {
        cleanNode(child);
    }

    // If tag is not in allowed list, unwrap its children
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
    let alignVal = null;

    for (const attr of attrs) {
        const name = attr.name.toLowerCase();

        // Disallow all event handlers (onclick, onerror, etc.)
        if (name.startsWith('on')) {
            node.removeAttribute(name);
            continue;
        }

        if (name === 'align') {
            const normalized = attr.value.trim().toLowerCase().replace(/^-webkit-/, '');
            if (ALLOWED_ALIGNMENTS.has(normalized)) {
                alignVal = normalized;
                node.setAttribute('align', normalized);
            } else {
                node.removeAttribute('align');
            }
        } else if (name === 'style') {
            const safeStyle = sanitizeStyle(attr.value, tagName);
            if (safeStyle) {
                node.setAttribute('style', safeStyle);
            } else {
                node.removeAttribute('style');
            }
        } else if (name === 'class') {
            // Keep safe Tailwind & Rich Editor classes
            const classes = attr.value.split(/\s+/).filter(c => {
                return /^(form-rich-(table|content|canvas)|text-(left|center|right|justify|xs|sm|base|lg|xl|[a-z]+-\d+)|font-(bold|semibold|normal|medium|extrabold|serif|mono)|italic|underline|line-through|(border|bg|p|px|py|m|mx|my|w|max-w|min-w|h|rounded|divide|overflow)-[\w\d\-./]+|border|border-collapse|hover:[\w\d\-./]+)$/.test(c);
            });
            if (classes.length > 0) {
                node.setAttribute('class', classes.join(' '));
            } else {
                node.removeAttribute('class');
            }
        } else if (['colspan', 'rowspan'].includes(name) && ['TH', 'TD'].includes(tagName)) {
            const num = parseInt(attr.value, 10);
            if (num > 0 && num <= 100) {
                node.setAttribute(name, String(num));
            } else {
                node.removeAttribute(name);
            }
        } else if (name === 'scope' && ['TH', 'TD'].includes(tagName)) {
            const val = attr.value.toLowerCase().trim();
            if (['row', 'col', 'rowgroup', 'colgroup'].includes(val)) {
                node.setAttribute(name, val);
            } else {
                node.removeAttribute(name);
            }
        } else if (name === 'valign' && ['TR', 'TH', 'TD'].includes(tagName)) {
            const val = attr.value.toLowerCase().trim();
            if (['top', 'middle', 'bottom', 'baseline'].includes(val)) {
                node.setAttribute(name, val);
            } else {
                node.removeAttribute(name);
            }
        } else if (['border', 'cellpadding', 'cellspacing'].includes(name) && tagName === 'TABLE') {
            const num = parseInt(attr.value, 10);
            if (!isNaN(num) && num >= 0 && num <= 50) {
                node.setAttribute(name, String(num));
            } else {
                node.removeAttribute(name);
            }
        } else if (['width', 'height'].includes(name) && ['TABLE', 'TH', 'TD', 'COL', 'COLGROUP'].includes(tagName)) {
            const val = attr.value.trim();
            if (tagName === 'TABLE') {
                if (name === 'width') {
                    node.setAttribute('width', '100%');
                } else {
                    node.removeAttribute('height');
                }
            } else if (['TH', 'TD', 'COL', 'COLGROUP'].includes(tagName)) {
                if (name === 'width' && /^\d+(\.\d+)?%$/.test(val)) {
                    node.setAttribute('width', val);
                } else {
                    node.removeAttribute(name);
                }
            } else if (/^\d+(%|px)?$/.test(val)) {
                node.setAttribute(name, val);
            } else {
                node.removeAttribute(name);
            }
        } else {
            // Remove any other attribute
            node.removeAttribute(name);
        }
    }

    // Ensure all tables have the form-rich-table class and clean sizing attributes
    if (tagName === 'TABLE') {
        const currentClass = node.getAttribute('class') || '';
        if (!currentClass.includes('form-rich-table')) {
            node.setAttribute('class', currentClass ? `form-rich-table ${currentClass}`.trim() : 'form-rich-table');
        }
        if (node.hasAttribute('width')) {
            node.setAttribute('width', '100%');
        }
        if (node.hasAttribute('height')) {
            node.removeAttribute('height');
        }
    }

    // If align attribute was valid and text-align not already in style, sync to style
    if (alignVal) {
        const currentStyle = node.getAttribute('style') || '';
        if (!/text-align\s*:/i.test(currentStyle)) {
            node.setAttribute('style', currentStyle ? `${currentStyle}; text-align: ${alignVal}` : `text-align: ${alignVal}`);
        }
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

        // Clean all children within body without stripping body itself
        const children = Array.from(body.childNodes);
        for (const child of children) {
            cleanNode(child);
        }

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
            return (doc.body.textContent || '').replace(/[\u00a0\s]+/g, ' ').trim();
        } catch {
            // regex fallback below
        }
    }

    return rawHtml.replace(/<[^>]+>/g, '').replace(/(&nbsp;|\u00a0)/gi, ' ').replace(/\s+/g, ' ').trim();
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
