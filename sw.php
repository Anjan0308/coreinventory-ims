<?php
header('Content-Type: application/javascript');
header('Cache-Control: no-cache');
?>
const CACHE = 'stockaxis-mobile-v1';
const ASSETS = ['./index.php','./style.css'];
self.addEventListener('install', e => e.waitUntil(caches.open(CACHE).then(c => c.addAll(ASSETS))));
self.addEventListener('fetch', e => {
  if (e.request.method === 'POST') return;
  e.respondWith(caches.match(e.request).then(r => r || fetch(e.request)));
});
