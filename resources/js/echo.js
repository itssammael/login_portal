import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import axios from 'axios';

window.Pusher = Pusher;
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;

const broadcaster = import.meta.env.VITE_BROADCAST_CONNECTION || (import.meta.env.VITE_REVERB_APP_KEY ? 'reverb' : 'pusher');
const appKey = import.meta.env.VITE_REVERB_APP_KEY || import.meta.env.VITE_PUSHER_APP_KEY;

// Detect if current session is loaded over HTTPS (e.g. Cloudflare Tunnel or reverse proxy)
const isPageHttps = typeof window !== 'undefined' && window.location.protocol === 'https:';
const configuredScheme = import.meta.env.VITE_REVERB_SCHEME || import.meta.env.VITE_PUSHER_SCHEME || 'http';
const isTls = isPageHttps || configuredScheme === 'https';

// Tunneling configuration support
const isTunneling = (
    import.meta.env.VITE_PUSHER_APP_TUNNELING === 'true' ||
    import.meta.env.PUSHER_APP_TUNNELING === 'true'
);
const tunnelUrl = import.meta.env.VITE_PUSHER_APP_TUNNEL_URL || import.meta.env.PUSHER_APP_TUNNEL_URL;

let rawHost;
if (isTunneling && tunnelUrl) {
    rawHost = tunnelUrl.replace(/^https?:\/\//i, '').split('/')[0].split(':')[0];
} else if (isPageHttps) {
    // When accessing via Cloudflare Tunnel, default to current public hostname
    rawHost = window.location.hostname;
} else {
    rawHost = import.meta.env.VITE_REVERB_HOST || import.meta.env.VITE_PUSHER_HOST || window.location.hostname;
}

const wsHost = rawHost === 'localhost' ? '127.0.0.1' : rawHost;
const defaultTlsPort = (typeof window !== 'undefined' && window.location.port) ? Number(window.location.port) : 443;
const defaultPlainPort = 8080;
const configuredPort = Number(import.meta.env.VITE_REVERB_PORT || import.meta.env.VITE_PUSHER_PORT);
const resolvedTlsPort = (configuredPort && configuredPort !== 8080) ? configuredPort : defaultTlsPort;
const resolvedPlainPort = configuredPort || defaultPlainPort;

window.Echo = new Echo({
    broadcaster: broadcaster,
    key: appKey,
    wsHost: wsHost,
    wsPort: isTls ? resolvedTlsPort : resolvedPlainPort,
    wssPort: resolvedTlsPort,
    forceTLS: isTls,
    cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER || 'mt1',
    enabledTransports: ['ws', 'wss'],
    authorizer: (channel) => {
        return {
            authorize: (socketId, callback) => {
                axios.post('/broadcasting/auth', {
                    socket_id: socketId,
                    channel_name: channel.name,
                })
                    .then((response) => {
                        callback(null, response.data);
                    })
                    .catch((error) => {
                        console.error('[Echo Authorizer Error]', error);
                        callback(error);
                    });
            },
        };
    },
});

export default window.Echo;