export const PHOTO_ACCEPT = 'image/jpeg,image/png,image/webp';
export const PHOTO_ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
export const PHOTO_ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];
export const PHOTO_MAX_BYTES = 5 * 1024 * 1024; // 5 MB

/**
 * Check whether a value is a browser File or Blob instance.
 */
export function isFileValue(value) {
    if (!value || typeof value !== 'object') {
        return false;
    }
    if (typeof File !== 'undefined' && value instanceof File) {
        return true;
    }
    if (typeof Blob !== 'undefined' && value instanceof Blob) {
        return true;
    }
    return false;
}

/**
 * Format file byte size into human-readable KB / MB string.
 */
export function formatPhotoSize(bytes) {
    const size = Number(bytes || 0);
    if (!size || size <= 0) {
        return '0 KB';
    }
    if (size >= 1024 * 1024) {
        return `${(size / (1024 * 1024)).toFixed(2)} MB`;
    }
    return `${Math.max(1, Math.round(size / 1024))} KB`;
}

/**
 * Validate a selected photo File on the client before previewing or submitting.
 * Returns an error string if invalid, or null when valid.
 */
export function validateClientPhotoFile(file, questionLabel = 'Photo upload') {
    const label = String(questionLabel || 'Photo upload').trim() || 'Photo upload';

    if (!isFileValue(file)) {
        return `Please select a valid image file for '${label}'.`;
    }

    const fileName = String(file.name || '').trim();
    const ext = fileName.includes('.') ? fileName.split('.').pop().toLowerCase() : '';
    const mime = String(file.type || '').toLowerCase();

    if (mime === 'image/svg+xml' || ext === 'svg' || ext === 'svgz') {
        return `The '${label}' field does not allow SVG files. Please upload a JPG, PNG, or WebP photo.`;
    }

    const mimeAllowed = PHOTO_ALLOWED_MIMES.includes(mime);
    const extAllowed = ext !== '' && PHOTO_ALLOWED_EXTENSIONS.includes(ext);

    if (!mimeAllowed && !extAllowed) {
        return `The '${label}' field must be a JPG, PNG, or WebP image.`;
    }

    if (file.size <= 0) {
        return `The selected file for '${label}' is empty.`;
    }

    if (file.size > PHOTO_MAX_BYTES) {
        return `The '${label}' photo must not exceed 5 MB (selected file is ${formatPhotoSize(file.size)}).`;
    }

    return null;
}

/**
 * Check whether an answers map contains at least one File/Blob upload.
 */
export function hasFileAnswers(answers = {}) {
    if (!answers || typeof answers !== 'object') {
        return false;
    }
    return Object.values(answers).some((val) => isFileValue(val));
}

/**
 * Build either a standard JSON payload (when no files are attached) or a
 * multipart FormData payload (when at least one photo File is present),
 * preserving non-file answers, checkbox arrays, _other inputs, participant
 * fields, and embed session tokens.
 */
export function buildFeedbackSubmissionPayload({
    answers = {},
    participantData = null,
    token = null,
} = {}) {
    if (!hasFileAnswers(answers)) {
        const cleanAnswers = {};
        Object.entries(answers || {}).forEach(([key, val]) => {
            if (isFileValue(val)) {
                return;
            }
            cleanAnswers[key] = val;
        });

        return {
            ...(token !== null && token !== undefined ? { token } : {}),
            ...(participantData && typeof participantData === 'object' ? participantData : {}),
            answers: cleanAnswers,
        };
    }

    const formData = new FormData();

    if (token !== null && token !== undefined) {
        formData.append('token', String(token));
    }

    if (participantData && typeof participantData === 'object') {
        Object.entries(participantData).forEach(([key, val]) => {
            if (val !== null && val !== undefined) {
                formData.append(key, String(val));
            } else {
                formData.append(key, '');
            }
        });
    }

    Object.entries(answers || {}).forEach(([fieldId, val]) => {
        if (isFileValue(val)) {
            formData.append(`answers[${fieldId}]`, val);
            return;
        }

        if (Array.isArray(val)) {
            val.forEach((item) => {
                if (item !== null && item !== undefined && !isFileValue(item)) {
                    formData.append(`answers[${fieldId}][]`, String(item));
                }
            });
            return;
        }

        if (val !== null && val !== undefined && typeof val !== 'object') {
            formData.append(`answers[${fieldId}]`, String(val));
        }
    });

    return formData;
}

