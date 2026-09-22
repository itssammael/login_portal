import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import axios from 'axios';

window.Pusher = Pusher;
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;

const broadcaster = import.meta.env.VITE_BROADCAST_CONNECTION || (import.meta.env.VITE_REVERB_APP_KEY ? 'reverb' : 'pusher');
const appKey = import.meta.env.VITE_REVERB_APP_KEY || import.meta.env.VITE_PUSHER_APP_KEY;
const rawHost = import.meta.env.VITE_REVERB_HOST || import.meta.env.VITE_PUSHER_HOST || window.location.hostname;
const wsHost = rawHost === 'localhost' ? '127.0.0.1' : rawHost;
const port = Number(import.meta.env.VITE_REVERB_PORT || import.meta.env.VITE_PUSHER_PORT || 8080);
const scheme = import.meta.env.VITE_REVERB_SCHEME || import.meta.env.VITE_PUSHER_SCHEME || 'http';
const isTls = scheme === 'https';

window.Echo = new Echo({
    broadcaster: broadcaster,
    key: appKey,
    wsHost: wsHost,
    wsPort: port,
    wssPort: port,
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
