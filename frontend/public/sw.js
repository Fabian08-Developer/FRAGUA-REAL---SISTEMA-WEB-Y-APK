// Mejora (16/10/2026): service worker de la app instalable (PWA) de Fragua Real.
//
// Qué guarda en el teléfono y qué no:
//  - Los archivos de la app (/assets/*, con huella en el nombre): se guardan y se sirven al instante.
//    Una versión nueva trae nombres nuevos, así que nunca se sirve código viejo.
//  - Las fotos públicas del menú (/api/productos/{id}/foto y /api/variantes/{id}/foto): se guardan,
//    porque su dirección cambia (?v=…) cada vez que se sube otra foto.
//  - El resto de la API (pedidos, sesiones, caja, datos personales): NUNCA se guarda; siempre va a la red.
//  - Las páginas (index.html): primero la red, para tener siempre la última versión; sin conexión se
//    muestra /offline.html.
// Al publicar una versión nueva del service worker, VERSION cambia y se borran las copias anteriores.
const VERSION = 'fragua-real-v1'
const CACHE_APP = `${VERSION}-app`
const CACHE_FOTOS = `${VERSION}-fotos`
const MAX_FOTOS = 150
const PRECARGA = ['/offline.html', '/manifest.webmanifest', '/iconos/icono-192.png', '/iconos/icono-512.png']

self.addEventListener('install', (evento) => {
  evento.waitUntil(caches.open(CACHE_APP).then((cache) => cache.addAll(PRECARGA)).then(() => self.skipWaiting()))
})

self.addEventListener('activate', (evento) => {
  evento.waitUntil(
    caches
      .keys()
      .then((nombres) => Promise.all(nombres.filter((n) => !n.startsWith(VERSION)).map((n) => caches.delete(n))))
      .then(() => self.clients.claim()),
  )
})

const FOTO = /^\/api\/(productos|variantes)\/\d+\/foto$/

self.addEventListener('fetch', (evento) => {
  const peticion = evento.request
  if (peticion.method !== 'GET') return
  const url = new URL(peticion.url)
  if (url.origin !== self.location.origin) return

  // Páginas: red primero; sin conexión, la página de aviso.
  if (peticion.mode === 'navigate') {
    evento.respondWith(fetch(peticion).catch(() => caches.match('/offline.html')))
    return
  }

  // Fotos públicas del menú: guardadas, con un límite.
  if (FOTO.test(url.pathname)) {
    evento.respondWith(desdeCache(CACHE_FOTOS, peticion, MAX_FOTOS))
    return
  }

  // Cualquier otra cosa de la API: siempre a la red, sin guardar.
  if (url.pathname.startsWith('/api/')) return

  // Archivos de la app con huella e íconos: guardados.
  if (url.pathname.startsWith('/assets/') || url.pathname.startsWith('/iconos/')) {
    evento.respondWith(desdeCache(CACHE_APP, peticion, 300))
  }
})

async function desdeCache(nombre, peticion, maximo) {
  const cache = await caches.open(nombre)
  const guardada = await cache.match(peticion)
  if (guardada) return guardada
  const respuesta = await fetch(peticion)
  if (respuesta.ok && respuesta.type === 'basic') {
    await cache.put(peticion, respuesta.clone())
    const claves = await cache.keys()
    if (claves.length > maximo) await Promise.all(claves.slice(0, claves.length - maximo).map((c) => cache.delete(c)))
  }
  return respuesta
}
