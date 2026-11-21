/* Lets the phone install the till as an app. It always asks the server, so sales are never stale;
   only when there is no connection does it show the last saved copy of the page. */
const PAGE = "caja-pagina-v1";
self.addEventListener("install", e => { self.skipWaiting(); });
self.addEventListener("activate", e => { e.waitUntil(self.clients.claim()); });
self.addEventListener("fetch", e => {
  const r = e.request;
  if (r.method !== "GET" || r.mode !== "navigate") return;
  e.respondWith(fetch(r).then(res => {
    const copy = res.clone(); caches.open(PAGE).then(c => c.put("./", copy)).catch(()=>{});
    return res;
  }).catch(() => caches.match("./").then(m => m || Response.error())));
});
