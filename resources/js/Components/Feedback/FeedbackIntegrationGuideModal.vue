<script setup>
import { computed, ref } from 'vue';

const props = defineProps({ form: { type: Object, required: true } });
const emit = defineEmits(['close']);
const backendTab = ref('cURL');
const frontendTab = ref('HTML');
const copied = ref('');

const origin = typeof window === 'undefined' ? 'https://example.com' : window.location.origin;
const clientId = computed(() => props.form.event?.embed?.client_id || 'YOUR_CLIENT_ID');
const publicId = computed(() => props.form.event?.embed?.public_id || 'YOUR_PUBLIC_ID');
const endpoint = computed(() => `${origin}/api/feedback/embed/sessions`);
const sessionUrl = computed(() => `${origin}/embed/feedback/${publicId.value}?token=YOUR_SINGLE_USE_TOKEN`);
const snippets = computed(() => ({
    cURL: `curl -X POST '${endpoint.value}' \\
  -H 'Content-Type: application/json' \\
  -H 'X-Client-Id: ${clientId.value}' \\
  -H 'X-Client-Secret: YOUR_CLIENT_SECRET' \\
  -d '{"respondent_id":"optional-user-id"}'`,
    'Node.js': `const response = await fetch('${endpoint.value}', {\n  method: 'POST',\n  headers: { 'Content-Type': 'application/json', 'X-Client-Id': '${clientId.value}', 'X-Client-Secret': process.env.FEEDBACK_CLIENT_SECRET },\n  body: JSON.stringify({ respondent_id: user?.id ?? null }),\n});\nconst iframeUrl = (await response.json()).data.iframe_url;`,
    Python: `response = requests.post('${endpoint.value}', headers={\n    'X-Client-Id': '${clientId.value}',\n    'X-Client-Secret': os.environ['FEEDBACK_CLIENT_SECRET'],\n}, json={'respondent_id': getattr(current_user, 'id', None)})\niframe_url = response.json()['data']['iframe_url']`,
    'PHP/Laravel': `$response = Http::withHeaders([\n    'X-Client-Id' => '${clientId.value}',\n    'X-Client-Secret' => config('services.feedback.client_secret'),\n])->post('${endpoint.value}', ['respondent_id' => auth()->id()]);\n$iframeUrl = $response->json('data.iframe_url');`,
    Go: `requestBody := strings.NewReader(\`{"respondent_id":"optional-user-id"}\`)\nrequest, _ := http.NewRequest("POST", "${endpoint.value}", requestBody)\nrequest.Header.Set("X-Client-Id", "${clientId.value}")\nrequest.Header.Set("X-Client-Secret", os.Getenv("FEEDBACK_CLIENT_SECRET"))\nresponse, err := http.DefaultClient.Do(request)`,
    'C# .NET': `using var request = new HttpRequestMessage(HttpMethod.Post, "${endpoint.value}");\nrequest.Headers.Add("X-Client-Id", "${clientId.value}");\nrequest.Headers.Add("X-Client-Secret", configuration["Feedback:ClientSecret"]);\nrequest.Content = JsonContent.Create(new { respondent_id = user?.Id });\nvar response = await httpClient.SendAsync(request);`,
    HTML: `<!-- Set src to the fresh iframe_url returned by your backend. -->\n<iframe\n  src="${sessionUrl.value}"\n  title="Public Feedback Form"\n  width="100%"\n  height="750"\n  frameborder="0"\n  loading="lazy"\n  style="border: 0; border-radius: 12px;"\n></iframe>`,
    Responsive: `<div style="max-width:900px;margin:auto;padding:16px 0">\n  <iframe src="${sessionUrl.value}" title="Public Feedback Form"\n    width="100%" height="750" loading="lazy"\n    style="display:block;border:0;border-radius:12px"></iframe>\n</div>`,
    'React/Vue': `// Fetch iframe_url from YOUR backend; never expose the client secret in a browser.\nconst { iframe_url } = await fetch('/api/feedback-session').then(r => r.json());\n\n// React: <iframe src={iframe_url} title="Public Feedback Form" width="100%" height="750" />\n// Vue:   <iframe :src="iframeUrl" title="Public Feedback Form" width="100%" height="750" />`,
}));

const copy = async (key) => {
    await navigator.clipboard?.writeText(snippets.value[key]);
    copied.value = key;
    window.setTimeout(() => { copied.value = ''; }, 1800);
};
</script>

<template>
    <div class="fixed inset-0 z-50 overflow-y-auto bg-gray-950/65 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-label="Feedback integration guide">
        <div class="mx-auto flex min-h-full max-w-5xl items-center justify-center">
            <div class="w-full overflow-hidden rounded-3xl border border-cream-500 bg-cream-100 shadow-2xl">
                <header class="flex items-start justify-between gap-4 border-b border-cream-500 bg-white px-5 py-5 sm:px-7"><div><p class="text-xs font-bold uppercase tracking-[0.16em] text-forest-700">Integration guide</p><h2 class="text-lg font-black text-gray-950">{{ form.event?.name }}</h2></div><button type="button" class="rounded-xl p-2 text-gray-500 hover:bg-cream-200" aria-label="Close guide" @click="emit('close')">✕</button></header>
                <div class="max-h-[72vh] space-y-7 overflow-y-auto p-5 sm:p-7">
                    <ol class="grid gap-3 text-sm text-gray-700 sm:grid-cols-3"><li class="rounded-2xl border border-cream-500 bg-white p-4"><strong class="block text-forest-900">1. Request a session</strong><span class="text-xs">Your private backend sends its client credentials.</span></li><li class="rounded-2xl border border-cream-500 bg-white p-4"><strong class="block text-forest-900">2. Receive iframe URL</strong><span class="text-xs">The service returns a five-minute, single-use URL.</span></li><li class="rounded-2xl border border-cream-500 bg-white p-4"><strong class="block text-forest-900">3. Render the iframe</strong><span class="text-xs">Set that fresh URL as the iframe source immediately.</span></li></ol>
                    <section class="space-y-3"><div class="flex flex-wrap items-center justify-between gap-2"><h3 class="font-black text-gray-950">Server-side session request</h3><div class="flex flex-wrap gap-1"><button v-for="tab in ['cURL', 'Node.js', 'Python', 'PHP/Laravel', 'Go', 'C# .NET']" :key="tab" type="button" class="rounded-lg px-2.5 py-1 text-xs font-bold" :class="backendTab === tab ? 'bg-forest-900 text-white' : 'bg-cream-200 text-gray-600'" @click="backendTab = tab">{{ tab }}</button></div></div><div class="relative"><pre class="overflow-x-auto rounded-2xl bg-gray-950 p-4 pr-24 text-xs leading-5 text-emerald-100"><code>{{ snippets[backendTab] }}</code></pre><button type="button" class="absolute right-3 top-3 rounded-lg bg-white/10 px-3 py-1.5 text-xs font-bold text-white hover:bg-white/20" @click="copy(backendTab)">{{ copied === backendTab ? '✓ Copied' : 'Copy' }}</button></div></section>
                    <section class="space-y-3"><div class="flex flex-wrap items-center justify-between gap-2"><h3 class="font-black text-gray-950">Frontend iframe</h3><div class="flex gap-1"><button v-for="tab in ['HTML', 'Responsive', 'React/Vue']" :key="tab" type="button" class="rounded-lg px-2.5 py-1 text-xs font-bold" :class="frontendTab === tab ? 'bg-forest-900 text-white' : 'bg-cream-200 text-gray-600'" @click="frontendTab = tab">{{ tab }}</button></div></div><div class="relative"><pre class="overflow-x-auto rounded-2xl bg-gray-950 p-4 pr-24 text-xs leading-5 text-emerald-100"><code>{{ snippets[frontendTab] }}</code></pre><button type="button" class="absolute right-3 top-3 rounded-lg bg-white/10 px-3 py-1.5 text-xs font-bold text-white hover:bg-white/20" @click="copy(frontendTab)">{{ copied === frontendTab ? '✓ Copied' : 'Copy' }}</button></div></section>
                    <p class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900"><strong>Security:</strong> keep <code>FEEDBACK_CLIENT_SECRET</code> only on your server. Never put it in HTML, JavaScript, or a mobile app bundle.</p>
                </div>
            </div>
        </div>
    </div>
</template>
