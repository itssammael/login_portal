import { ref, watch, onMounted, onBeforeUnmount } from 'vue';

const COOKIE_MAX_AGE_SECONDS = 86400; // 24 hours
const CHUNK_SIZE = 3200; // Safe encoded length per cookie (< 4096 bytes limit)
const MAX_CHUNKS = 10;

/**
 * Sanitize a key so it is safe to use inside a cookie name.
 */
function normalizeCookieKey(rawKey) {
    return `fb_draft_${String(rawKey || 'default').replace(/[^a-zA-Z0-9_-]/g, '_')}`;
}

/**
 * Determine cookie attributes including 24-hour expiration and iframe-safe SameSite policy.
 */
function buildCookieAttributes(maxAgeSeconds) {
    const expiresDate = new Date(Date.now() + maxAgeSeconds * 1000).toUTCString();
    const isHttps = typeof window !== 'undefined' && window.location?.protocol === 'https:';
    const isIframe = typeof window !== 'undefined' && window.parent && window.parent !== window;

    let sameSite = 'SameSite=Lax';
    if (isIframe && isHttps) {
        sameSite = 'SameSite=None; Secure';
    } else if (isHttps) {
        sameSite = 'SameSite=Lax; Secure';
    }

    return `Path=/; Max-Age=${maxAgeSeconds}; Expires=${expiresDate}; ${sameSite}`;
}

/**
 * Read a single cookie value by name from document.cookie.
 */
function getRawCookie(name) {
    if (typeof document === 'undefined' || !document.cookie) {
        return null;
    }
    const prefix = `${name}=`;
    const parts = document.cookie.split(';');
    for (let i = 0; i < parts.length; i++) {
        const part = parts[i].trim();
        if (part.startsWith(prefix)) {
            return part.substring(prefix.length);
        }
    }
    return null;
}

/**
 * Write a single cookie value with 24-hour expiration.
 */
function setRawCookie(name, value, maxAgeSeconds = COOKIE_MAX_AGE_SECONDS) {
    if (typeof document === 'undefined') {
        return;
    }
    document.cookie = `${name}=${value}; ${buildCookieAttributes(maxAgeSeconds)}`;
}

/**
 * Expire and delete a single cookie immediately.
 */
function deleteRawCookie(name) {
    if (typeof document === 'undefined') {
        return;
    }
    const isHttps = typeof window !== 'undefined' && window.location?.protocol === 'https:';
    const isIframe = typeof window !== 'undefined' && window.parent && window.parent !== window;
    const sameSite = isIframe && isHttps ? 'SameSite=None; Secure' : (isHttps ? 'SameSite=Lax; Secure' : 'SameSite=Lax');

    document.cookie = `${name}=; Path=/; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT; ${sameSite}`;
}

/**
 * Check if a value is considered non-empty user input.
 */
function hasMeaningfulValue(val, defaultVal = '') {
    if (val === null || val === undefined || val === '') {
        return false;
    }
    if (Array.isArray(val)) {
        return val.length > 0;
    }
    if (defaultVal !== undefined && defaultVal !== '' && String(val) === String(defaultVal)) {
        return false;
    }
    return true;
}

/**
 * Composable for saving and restoring unsubmitted form progress in 24-hour cookies.
 *
 * @param {string|import('vue').Ref<string>} formIdentifier
 * @param {Object} state
 * @param {import('vue').Ref<Object>} state.answers
 * @param {import('vue').Ref<Object>} [state.participantData]
 * @param {import('vue').Ref<number>} [state.currentPageIdx]
 * @param {import('vue').Ref<Array<number>>} [state.pageHistory]
 * @param {Function} [state.getDefaults]
 */
export function useFormDraftCookie(formIdentifier, {
    answers,
    participantData = null,
    currentPageIdx = null,
    pageHistory = null,
    getDefaults = () => ({}),
} = {}) {
    const hasRestoredDraft = ref(false);
    const lastSavedAt = ref(null);
    const isOffline = ref(typeof navigator !== 'undefined' ? !navigator.onLine : false);

    let debounceTimer = null;
    let isRestoring = false;
    let isClearedAfterSubmit = false;

    const getCookieKey = () => {
        const raw = typeof formIdentifier === 'function'
            ? formIdentifier()
            : (formIdentifier?.value ?? formIdentifier);
        return normalizeCookieKey(raw);
    };

    /**
     * Remove the main cookie, all chunk cookies, and fallback storage.
     */
    const removeStoredCookieData = (baseKey) => {
        deleteRawCookie(baseKey);
        for (let i = 0; i < MAX_CHUNKS; i++) {
            deleteRawCookie(`${baseKey}_${i}`);
        }
        try {
            if (typeof window !== 'undefined' && window.localStorage) {
                window.localStorage.removeItem(baseKey);
            }
        } catch {
            // Ignore storage access errors in strict privacy contexts
        }
    };

    /**
     * Read and reassemble JSON payload from 24h cookie (with chunking & fallback check).
     */
    const readDraftPayload = () => {
        const baseKey = getCookieKey();
        let encoded = getRawCookie(baseKey);

        if (encoded && encoded.startsWith('chunks:')) {
            const count = parseInt(encoded.substring(7), 10);
            if (!Number.isNaN(count) && count > 0 && count <= MAX_CHUNKS) {
                const chunks = [];
                for (let i = 0; i < count; i++) {
                    const part = getRawCookie(`${baseKey}_${i}`);
                    if (part === null) {
                        encoded = null;
                        break;
                    }
                    chunks.push(part);
                }
                if (encoded !== null) {
                    encoded = chunks.join('');
                }
            } else {
                encoded = null;
            }
        }

        if (encoded) {
            try {
                const parsed = JSON.parse(decodeURIComponent(encoded));
                if (parsed && typeof parsed === 'object') {
                    // Check 24-hour TTL
                    if (parsed.savedAt && Date.now() - Number(parsed.savedAt) > COOKIE_MAX_AGE_SECONDS * 1000) {
                        removeStoredCookieData(baseKey);
                        return null;
                    }
                    return parsed;
                }
            } catch {
                removeStoredCookieData(baseKey);
            }
        }

        // Fallback check if third-party iframe cookies were blocked by browser policy
        try {
            if (typeof window !== 'undefined' && window.localStorage) {
                const fallbackRaw = window.localStorage.getItem(baseKey);
                if (fallbackRaw) {
                    const parsed = JSON.parse(fallbackRaw);
                    if (parsed && parsed.savedAt && Date.now() - Number(parsed.savedAt) <= COOKIE_MAX_AGE_SECONDS * 1000) {
                        return parsed;
                    }
                    window.localStorage.removeItem(baseKey);
                }
            }
        } catch {
            // Ignore localStorage errors
        }

        return null;
    };

    /**
     * Extract only non-empty answers and participant metadata so the cookie stays compact.
     */
    const buildCompactPayload = () => {
        const defaults = getDefaults() || {};
        const currentAnswers = answers?.value || {};
        const nonEmptyAnswers = {};
        let hasAnyAnswer = false;

        Object.keys(currentAnswers).forEach((key) => {
            const val = currentAnswers[key];
            if (hasMeaningfulValue(val, defaults[key])) {
                nonEmptyAnswers[key] = val;
                hasAnyAnswer = true;
            }
        });

        const nonEmptyParticipant = {};
        let hasAnyParticipant = false;
        if (participantData?.value && typeof participantData.value === 'object') {
            Object.keys(participantData.value).forEach((key) => {
                const val = participantData.value[key];
                if (hasMeaningfulValue(val, defaults[key])) {
                    nonEmptyParticipant[key] = val;
                    hasAnyParticipant = true;
                }
            });
        }

        const pageIdx = Number(currentPageIdx?.value || 0);
        const history = Array.isArray(pageHistory?.value) ? [...pageHistory.value] : [0];

        if (!hasAnyAnswer && !hasAnyParticipant && pageIdx === 0) {
            return null;
        }

        return {
            v: 1,
            savedAt: Date.now(),
            answers: nonEmptyAnswers,
            participantData: hasAnyParticipant ? nonEmptyParticipant : undefined,
            currentPageIdx: pageIdx,
            pageHistory: history,
        };
    };

    /**
     * Immediately persist current form progress to 24-hour expiring cookie(s).
     */
    const saveDraftNow = () => {
        if (isRestoring || isClearedAfterSubmit) {
            return;
        }

        const baseKey = getCookieKey();
        const payload = buildCompactPayload();

        if (!payload) {
            removeStoredCookieData(baseKey);
            lastSavedAt.value = null;
            return;
        }

        try {
            const jsonStr = JSON.stringify(payload);
            const encoded = encodeURIComponent(jsonStr);

            // Clear old chunks first
            for (let i = 0; i < MAX_CHUNKS; i++) {
                deleteRawCookie(`${baseKey}_${i}`);
            }

            if (encoded.length <= CHUNK_SIZE) {
                setRawCookie(baseKey, encoded, COOKIE_MAX_AGE_SECONDS);
            } else {
                const chunks = [];
                for (let i = 0; i < encoded.length; i += CHUNK_SIZE) {
                    chunks.push(encoded.slice(i, i + CHUNK_SIZE));
                }
                if (chunks.length <= MAX_CHUNKS) {
                    setRawCookie(baseKey, `chunks:${chunks.length}`, COOKIE_MAX_AGE_SECONDS);
                    chunks.forEach((chunk, index) => {
                        setRawCookie(`${baseKey}_${index}`, chunk, COOKIE_MAX_AGE_SECONDS);
                    });
                }
            }

            // Mirror to localStorage with 24h timestamp in case payload exceeds cookie limits or iframe blocks cookies
            try {
                if (typeof window !== 'undefined' && window.localStorage) {
                    window.localStorage.setItem(baseKey, jsonStr);
                }
            } catch {
                // Ignore localStorage quota/privacy errors
            }

            lastSavedAt.value = new Date(payload.savedAt);
        } catch {
            // Safely ignore serialization errors
        }
    };

    /**
     * Schedule a debounced save when form inputs change.
     */
    const scheduleSaveDraft = () => {
        if (isRestoring) {
            return;
        }
        isClearedAfterSubmit = false;
        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }
        debounceTimer = setTimeout(() => {
            saveDraftNow();
        }, 300);
    };

    /**
     * Restore saved progress from cookie into reactive form state.
     *
     * @param {Object} [options]
     * @param {number} [options.maxPages]
     * @returns {boolean} True if a draft was found and restored
     */
    const restoreDraft = ({ maxPages = 1 } = {}) => {
        const saved = readDraftPayload();
        if (!saved) {
            hasRestoredDraft.value = false;
            return false;
        }

        isRestoring = true;
        let restoredSomething = false;

        try {
            if (saved.answers && typeof saved.answers === 'object' && answers?.value) {
                const updatedAnswers = { ...answers.value };
                Object.keys(saved.answers).forEach((fieldId) => {
                    if (Object.prototype.hasOwnProperty.call(updatedAnswers, fieldId)) {
                        const savedVal = saved.answers[fieldId];
                        if (Array.isArray(updatedAnswers[fieldId])) {
                            if (Array.isArray(savedVal) && savedVal.length > 0) {
                                updatedAnswers[fieldId] = [...savedVal];
                                restoredSomething = true;
                            }
                        } else if (savedVal !== undefined && savedVal !== null && savedVal !== '') {
                            updatedAnswers[fieldId] = savedVal;
                            restoredSomething = true;
                        }
                    }
                });
                answers.value = updatedAnswers;
            }

            if (saved.participantData && typeof saved.participantData === 'object' && participantData?.value) {
                const updatedParticipant = { ...participantData.value };
                Object.keys(saved.participantData).forEach((key) => {
                    if (Object.prototype.hasOwnProperty.call(updatedParticipant, key)) {
                        const val = saved.participantData[key];
                        if (val !== undefined && val !== null && val !== '') {
                            updatedParticipant[key] = val;
                            restoredSomething = true;
                        }
                    }
                });
                participantData.value = updatedParticipant;
            }

            if (currentPageIdx && typeof saved.currentPageIdx === 'number' && saved.currentPageIdx >= 0) {
                const safeMaxIdx = Math.max(0, (Number(maxPages) || 1) - 1);
                const clampedIdx = Math.min(saved.currentPageIdx, safeMaxIdx);
                currentPageIdx.value = clampedIdx;
                if (clampedIdx > 0) {
                    restoredSomething = true;
                }

                if (pageHistory && Array.isArray(saved.pageHistory) && saved.pageHistory.length > 0) {
                    const validHistory = saved.pageHistory
                        .map((idx) => Number(idx))
                        .filter((idx) => !Number.isNaN(idx) && idx >= 0 && idx <= safeMaxIdx);
                    pageHistory.value = validHistory.length > 0 ? validHistory : [0];
                }
            }

            if (restoredSomething) {
                hasRestoredDraft.value = true;
                lastSavedAt.value = saved.savedAt ? new Date(saved.savedAt) : new Date();
            }
        } finally {
            setTimeout(() => {
                isRestoring = false;
            }, 0);
        }

        return restoredSomething;
    };

    /**
     * Clear saved draft cookie immediately (used after successful submission or manual clear).
     */
    const clearDraft = () => {
        isClearedAfterSubmit = true;
        if (debounceTimer) {
            clearTimeout(debounceTimer);
            debounceTimer = null;
        }
        removeStoredCookieData(getCookieKey());
        hasRestoredDraft.value = false;
        lastSavedAt.value = null;
    };

    const handleOnline = () => {
        isOffline.value = false;
    };

    const handleOffline = () => {
        isOffline.value = true;
        saveDraftNow();
    };

    const handleBeforeUnload = () => {
        if (debounceTimer) {
            clearTimeout(debounceTimer);
            debounceTimer = null;
            saveDraftNow();
        }
    };

    if (answers) {
        watch(answers, scheduleSaveDraft, { deep: true });
    }
    if (participantData) {
        watch(participantData, scheduleSaveDraft, { deep: true });
    }
    if (currentPageIdx) {
        watch(currentPageIdx, scheduleSaveDraft);
    }

    onMounted(() => {
        if (typeof window !== 'undefined') {
            isOffline.value = !navigator.onLine;
            window.addEventListener('online', handleOnline);
            window.addEventListener('offline', handleOffline);
            window.addEventListener('beforeunload', handleBeforeUnload);
        }
    });

    onBeforeUnmount(() => {
        if (debounceTimer) {
            clearTimeout(debounceTimer);
            debounceTimer = null;
        }
        if (typeof window !== 'undefined') {
            window.removeEventListener('online', handleOnline);
            window.removeEventListener('offline', handleOffline);
            window.removeEventListener('beforeunload', handleBeforeUnload);
        }
    });

    return {
        hasRestoredDraft,
        lastSavedAt,
        isOffline,
        saveDraftNow,
        restoreDraft,
        clearDraft,
    };
}
