/* Peta evakuasi interaktif (Leaflet + citra Esri).
   Lapisan: zonasi keselamatan 7 peringkat, sabuk rob pesisir (<= 100 m dari garis pantai), arah evakuasi, titik kumpul usulan.
   Seluruh data dirender PHP ke <script id="evk-data">; berkas ini tidak membuat angka sendiri. */
(function () {
  var host = document.getElementById('evk-map'), raw = document.getElementById('evk-data');
  if (!host || !raw || !window.L) return;
  var D = JSON.parse(raw.textContent), msg = document.getElementById('evk-msg');
  var narrow = host.clientWidth < 680;
  var map = L.map(host, { zoomSnap: 0.25, zoomDelta: 0.5, wheelPxPerZoomLevel: 90, scrollWheelZoom: false, minZoom: 11, maxZoom: 19 });
  var nat = L.Browser.retina ? 16 : 17;
  var mk = function (u, o) { return L.tileLayer(u, L.extend({ maxNativeZoom: nat, maxZoom: 19, detectRetina: true, attribution: D.attr }, o || {})); };
  var sat = mk(D.sat).addTo(map), lab = mk(D.lab, { opacity: 0.95 }).addTo(map);
  var topo = mk(D.topo), street = mk(D.street);
  L.control.scale({ imperial: false, position: 'bottomright' }).addTo(map);
  host.addEventListener('click', function () { map.scrollWheelZoom.enable(); });
  host.addEventListener('mouseleave', function () { map.scrollWheelZoom.disable(); });

  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  var loaded = 0, failed = 0, shown = false;
  function fail() {
    if (shown || loaded) return; shown = true; host.classList.add('nosat');
    if (D.pantai.length) L.polygon(D.pantai.map(function (p) { return [p[1], p[0]]; }), { color: '#8fb39a', weight: 2, fillColor: '#2b4a3a', fillOpacity: 1, interactive: false }).addTo(map).bringToBack();
    if (msg) msg.insertAdjacentHTML('afterbegin', '<div class="note warn"><div>Citra satelit Esri tidak dapat dimuat. Pastikan komputer terhubung ke internet. Zona, jalur, dan titik tetap ditampilkan.</div></div>');
  }
  sat.on('tileload', function () { loaded++; }); sat.on('tileerror', function () { if (++failed >= 4) fail(); }); setTimeout(fail, 7000);

  ['zona', 'sabuk', 'jalur', 'titik', 'badge'].forEach(function (n, i) { map.createPane(n); map.getPane(n).style.zIndex = 410 + i * 10; });
  map.getPane('sabuk').style.pointerEvents = 'none'; map.getPane('jalur').style.pointerEvents = 'none';

  /* ---------- geometri bantu (meter lokal) ---------- */
  var lat0 = D.pantai.length ? D.pantai.reduce(function (a, p) { return a + p[1]; }, 0) / D.pantai.length : -5.93;
  var KX = 111320 * Math.cos(lat0 * Math.PI / 180), KY = 110574;
  function xy(ll) { return [ll[1] * KX, ll[0] * KY]; }
  function ll(p) { return [p[1] / KY, p[0] / KX]; }
  function inside(p, ring) { var x = p[0], y = p[1], c = false; for (var i = 0, j = ring.length - 1; i < ring.length; j = i++) { var xi = ring[i][0] * KX, yi = ring[i][1] * KY, xj = ring[j][0] * KX, yj = ring[j][1] * KY; if ((yi > y) !== (yj > y) && x < (xj - xi) * (y - yi) / (yj - yi) + xi) c = !c; } return c; }
  function bezier(a, b, k) {
    var A = xy(a), B = xy(b), dx = B[0] - A[0], dy = B[1] - A[1], C = [(A[0] + B[0]) / 2 - dy * k, (A[1] + B[1]) / 2 + dx * k], pts = [];
    for (var i = 0; i <= 48; i++) { var t = i / 48, u = 1 - t; pts.push([u * u * A[0] + 2 * u * t * C[0] + t * t * B[0], u * u * A[1] + 2 * u * t * C[1] + t * t * B[1]]); }
    return pts;
  }
  function bestRoute(a, b) { // pilih lengkung yang paling banyak berada di daratan pulau
    var best = null, bs = -1;
    [0.1, -0.1, 0.18, -0.18, 0.05, -0.05, 0].forEach(function (k) { var p = bezier(a, b, k), s = 0; p.forEach(function (q) { if (inside(q, D.pantai)) s++; }); if (s > bs + 1) { bs = s; best = p; } });
    return best;
  }

  /* ---------- zonasi kampung ---------- */
  var byId = {}, zonaGroup = L.featureGroup().addTo(map), badgeGroup = L.layerGroup().addTo(map), tkGroup = L.layerGroup().addTo(map);
  var base = function (k, o) { return { pane: 'zona', color: '#ffffff', weight: o ? 4 : 2.2, opacity: 0.95, fillColor: k.warna, fillOpacity: o ? 0.72 : 0.5, lineJoin: 'round' }; };
  D.kampung.forEach(function (k) {
    var o = { k: k, sel: false };
    if (k.geo) {
      o.layer = L.geoJSON(k.geo, { style: function () { return base(k, false); } }).addTo(zonaGroup);
      o.layer.on('mouseover', function () { if (!o.sel) o.layer.setStyle(base(k, true)); }).on('mouseout', function () { if (!o.sel) o.layer.setStyle(base(k, false)); }).on('click', function () { select(k.id, true); });
      o.layer.bindTooltip(esc(k.nama) + ': peringkat ' + k.rank + ', ' + esc(k.label), { sticky: true });
    }
    var anchor = k.pusat || (o.layer ? o.layer.getBounds().getCenter() : null);
    if (anchor && !(k.rank === 1 && D.utama)) {
      var lt = (k.lt === true || k.lt === false) ? k.lt : (k.kunci === 'cukup' || k.kunci === 'siaga');
      o.badge = L.marker(anchor, { pane: 'badge', icon: L.divIcon({ className: 'evk-badge' + (lt ? ' lt' : ''), iconSize: [34, 34], iconAnchor: [17, 17], html: '<span style="--c:' + k.warna + '">' + k.rank + '</span>' }), title: k.nama + ': peringkat ' + k.rank + ', ' + k.label, alt: k.nama, riseOnHover: true }).addTo(badgeGroup);
      o.badge.bindTooltip(esc(k.pendek), { permanent: true, direction: 'top', offset: [0, -16], className: 'lf-lab' }).on('click', function () { select(k.id, true); });
    }
    if (k.tk && k.rank > 1) L.marker(k.tk, { pane: 'titik', icon: L.divIcon({ className: 'evk-tk', iconSize: [14, 14], iconAnchor: [7, 7], html: '<span></span>' }), title: 'Titik kumpul usulan ' + k.nama }).bindTooltip('Titik kumpul usulan: ' + esc(k.pendek), { direction: 'top', offset: [0, -8] }).addTo(tkGroup);
    byId[k.id] = o;
  });

  /* ---------- sabuk rob pesisir ---------- */
  var sabukGroup = L.layerGroup().addTo(map);
  if (D.sabuk) L.geoJSON(D.sabuk, { pane: 'sabuk', interactive: false, style: { color: '#ff6a5c', weight: 1.4, dashArray: '6 5', fillColor: '#d7261e', fillOpacity: 0.42, fillRule: 'evenodd' } }).addTo(sabukGroup);

  /* ---------- jalur evakuasi (arah indikatif) ---------- */
  var jalurGroup = L.layerGroup().addTo(map), tempatGroup = L.layerGroup().addTo(map);
  if (D.utama) {
    D.tempat.forEach(function (t) {
      var a = [t.lat, t.lon], pts = bestRoute(a, D.utama.latlng), line = pts.map(ll);
      L.polyline(line, { pane: 'jalur', color: '#ff7a1a', weight: 11, opacity: 0.3, lineCap: 'round', interactive: false }).addTo(jalurGroup);
      L.polyline(line, { pane: 'jalur', color: '#fff0cf', weight: 3.4, opacity: 1, className: 'evk-flow', interactive: false }).addTo(jalurGroup);
      var n = pts.length, i = Math.floor(n * 0.9), p = pts[i], q = pts[i - 1], ang = Math.atan2(p[0] - q[0], p[1] - q[1]) * 180 / Math.PI;
      L.marker(ll(p), { pane: 'jalur', interactive: false, icon: L.divIcon({ className: 'evk-arrow', iconSize: [30, 30], iconAnchor: [15, 15], html: '<svg width="30" height="30" viewBox="0 0 30 30" style="transform:rotate(' + ang.toFixed(1) + 'deg)"><polygon points="15,2 26,25 15,19 4,25" fill="#ff7a1a" stroke="#fff" stroke-width="2.4" stroke-linejoin="round"/></svg>' }) }).addTo(jalurGroup);
      L.marker(a, { pane: 'badge', icon: L.divIcon({ className: 'evk-pin', iconSize: [0, 0], html: '<div><i></i><span>' + esc(t.nama) + '</span></div>' }), title: t.nama + ' (posisi perkiraan)', alt: t.nama, keyboard: false }).addTo(tempatGroup);
    });
    L.marker(D.utama.latlng, { pane: 'badge', zIndexOffset: 3000, icon: L.divIcon({ className: 'evk-main', iconSize: [56, 56], iconAnchor: [32, 32], html: '<div><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="14.5" cy="4.5" r="1.8" fill="#fff"/><path d="M9 21l2.5-6-2-2 1.5-4 3 1.5 2.5 2.5M8 11l-3 3M12.5 13.5l3.5 1.5"/></svg></div>' }), title: 'Zona aman utama: ' + D.utama.nama }).addTo(map)
      .bindTooltip('Zona aman utama<br>' + esc(D.utama.nama), { permanent: true, direction: 'left', offset: [-30, 0], className: 'lf-lab' });
  }

  /* ---------- kontrol lapisan ---------- */
  var ov = { 'Zonasi keselamatan': zonaGroup, 'Nomor peringkat': badgeGroup, 'Sabuk rob pesisir (100 m)': sabukGroup, 'Arah evakuasi': jalurGroup, 'Titik kumpul usulan': tkGroup, 'Nama pantai dan jetty': tempatGroup, 'Nama tempat Esri': lab };
  L.control.layers({ 'Citra satelit': sat, 'Topografi': topo, 'Peta jalan': street }, ov, { position: 'topright', collapsed: true }).addTo(map);

  /* ---------- legenda ---------- */
  var Leg = L.Control.extend({ options: { position: 'bottomleft' }, onAdd: function () {
    var d = L.DomUtil.create('div', 'evk-leg min'), h = '<h4><span>Legenda</span><button type="button" aria-expanded="false">buka</button></h4><div class="body">';
    D.legenda.forEach(function (g) { h += '<div class="row"><i class="sw" style="background:' + g.warna + '"></i><div><b>' + esc(g.label) + '</b><small>' + esc(g.rank) + ': ' + esc(g.kampung.join(', ')) + '</small></div></div>'; });
    h += '<hr><div class="row"><i class="sw" style="background:rgba(215,38,30,.55);border-style:dashed"></i><div><b>Sabuk rob pesisir</b><small>sampai 100 m dari garis pantai</small></div></div>'
      + '<div class="row"><i class="sw" style="background:#ff7a1a"></i><div><b>Arah evakuasi</b><small>indikatif, jalan belum disurvei</small></div></div>'
      + '<div class="row"><i class="sw" style="background:#fff;border-color:#14803a;transform:rotate(45deg) scale(.7)"></i><div><b>Titik kumpul usulan</b><small>tiap kampung</small></div></div></div>';
    d.innerHTML = h; L.DomEvent.disableClickPropagation(d); L.DomEvent.disableScrollPropagation(d);
    var b = d.querySelector('button'); b.addEventListener('click', function () { var m = d.classList.toggle('min'); b.textContent = m ? 'buka' : 'tutup'; b.setAttribute('aria-expanded', String(!m)); });
    return d; } });
  new Leg().addTo(map);

  /* ---------- layar penuh ---------- */
  var Fs = L.Control.extend({ options: { position: 'topleft' }, onAdd: function () {
    var b = L.DomUtil.create('div', 'leaflet-bar'), a = L.DomUtil.create('a', '', b); a.href = '#'; a.title = 'Layar penuh'; a.setAttribute('role', 'button'); a.textContent = '⛶';
    L.DomEvent.on(a, 'click', function (e) { L.DomEvent.stop(e); if (document.fullscreenElement) document.exitFullscreen(); else if (host.requestFullscreen) host.requestFullscreen(); });
    var r = L.DomUtil.create('a', '', b); r.href = '#'; r.title = 'Tampilkan seluruh pulau'; r.setAttribute('role', 'button'); r.textContent = '⌂'; L.DomEvent.on(r, 'click', function (e) { L.DomEvent.stop(e); select(null); fitAll(); });
    return b; } });
  new Fs().addTo(map); document.addEventListener('fullscreenchange', function () { setTimeout(function () { map.invalidateSize(); }, 120); });

  /* ---------- kartu rincian & pemilihan ---------- */
  var card = document.getElementById('evk-info');
  var buttons = [].slice.call(document.querySelectorAll('.evk-r'));
  function select(id, fly) {
    Object.keys(byId).forEach(function (key) { var o = byId[key]; var on = String(key) === String(id); o.sel = on; if (o.layer) { o.layer.setStyle(base(o.k, on)); if (on && o.layer.bringToFront) o.layer.bringToFront(); } if (o.badge) { var el = o.badge.getElement(); if (el) el.classList.toggle('sel', on); } });
    buttons.forEach(function (b) { b.setAttribute('aria-pressed', String(String(b.dataset.id) === String(id))); });
    if (!card) return; if (id == null || !byId[id]) { card.hidden = true; return; }
    var k = byId[id].k, h = '<h3>' + esc(k.nama) + '</h3><div class="zl" style="--c:' + k.warna + '"><i></i>Peringkat ' + k.rank + ': ' + esc(k.label) + '</div><dl>';
    card.style.setProperty('--c', k.warna);
    k.baris.forEach(function (r) { h += '<div><dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd></div>'; });
    card.innerHTML = h + '</dl>'; card.hidden = false;
    if (fly && byId[id].layer) map.flyToBounds(byId[id].layer.getBounds().pad(0.35), { maxZoom: 17, duration: 0.8 });
  }
  buttons.forEach(function (b) { b.addEventListener('click', function () { var same = b.getAttribute('aria-pressed') === 'true'; if (same) { select(null); fitAll(); } else select(Number(b.dataset.id), true); }); });
  function fitAll() { if (D.pantai.length) map.fitBounds(L.latLngBounds(D.pantai.map(function (p) { return [p[1], p[0]]; })).pad(0.04), { maxZoom: 17 }); else map.fitBounds(zonaGroup.getBounds().pad(0.06)); }
  fitAll(); setTimeout(function () { map.invalidateSize(); fitAll(); }, 80);
})();
