/* Peta satelit Esri (Leaflet). Satu-satunya bagian aplikasi yang memakai JavaScript, karena peta interaktif
   memang memerlukannya. Data zona dan titik dirender oleh PHP ke <script id="sat-data">. */
(function () {
  var host = document.getElementById('sat-map'), raw = document.getElementById('sat-data');
  if (!host || !raw || !window.L) return;
  var D = JSON.parse(raw.textContent), msg = document.getElementById('sat-msg');
  var map = L.map(host, { zoomSnap: 0.5, zoomDelta: 0.5, wheelPxPerZoomLevel: 90, scrollWheelZoom: false, minZoom: 11, maxZoom: 19 });
  var nat = L.Browser.retina ? 16 : 17;
  var mk = function (u) { return L.tileLayer(u, { maxNativeZoom: nat, maxZoom: 19, detectRetina: true, attribution: D.attr }); };
  var sat = mk(D.sat).addTo(map), lab = L.tileLayer(D.lab, { maxNativeZoom: nat, maxZoom: 19, detectRetina: true, opacity: 0.95 }).addTo(map);
  L.control.layers({ 'Citra satelit': sat, 'Topografi': mk(D.topo), 'Peta jalan': mk(D.street) }, { 'Nama tempat': lab }, { position: 'topright' }).addTo(map);
  L.control.scale({ imperial: false, position: 'bottomleft' }).addTo(map);
  var Co = L.Control.extend({ options: { position: 'bottomright' }, onAdd: function () { var d = L.DomUtil.create('div', 'lf-coord'); d.textContent = 'Arahkan kursor ke peta'; map.on('mousemove', function (e) { d.textContent = e.latlng.lat.toFixed(5) + '; ' + e.latlng.lng.toFixed(5); }); return d; } });
  new Co().addTo(map);
  var Fs = L.Control.extend({ options: { position: 'topleft' }, onAdd: function () { var b = L.DomUtil.create('div', 'leaflet-bar'), a = L.DomUtil.create('a', '', b); a.href = '#'; a.title = 'Layar penuh'; a.setAttribute('role', 'button'); a.textContent = '⛶';
    L.DomEvent.on(a, 'click', function (e) { L.DomEvent.stop(e); if (document.fullscreenElement) document.exitFullscreen(); else if (host.requestFullscreen) host.requestFullscreen(); }); return b; } });
  new Fs().addTo(map); document.addEventListener('fullscreenchange', function () { setTimeout(function () { map.invalidateSize(); }, 120); });
  host.addEventListener('click', function () { map.scrollWheelZoom.enable(); }); host.addEventListener('mouseleave', function () { map.scrollWheelZoom.disable(); });

  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  var loaded = 0, failed = 0, shown = false;
  function fail() { if (shown || loaded) return; shown = true; host.classList.add('nosat'); if (msg) msg.insertAdjacentHTML('afterbegin', '<div class="note warn"><div>Citra satelit Esri tidak dapat dimuat. Pastikan komputer Anda terhubung ke internet. <a href="' + D.gmaps + '" target="_blank" rel="noopener noreferrer">Lihat lokasinya di Google Maps</a>.</div></div>'); }
  sat.on('tileload', function () { loaded++; }); sat.on('tileerror', function () { if (++failed >= 4) fail(); }); setTimeout(fail, 7000);

  /* Peta polos: hanya citra Esri dan nama kampung. Warna, simbol, dan status kerawanan baru tampil untuk
     kampung yang diklik (halaman dimuat ulang dengan ?sel=, sehingga panel rincian ikut terisi). */
  var group = L.featureGroup().addTo(map);
  function icon(rank, color, sel) {
    var s = sel ? 48 : 40, shape = rank === 0 ? '<circle cx="20" cy="20" r="12"/>' : rank === 1 ? '<polygon points="20,6 33.5,31 6.5,31"/>' : '<polygon points="14,7 26,7 33,14 33,26 26,33 14,33 7,26 7,14"/>';
    return L.divIcon({ className: 'hz' + (sel ? ' sel' : ''), iconSize: [s, s], iconAnchor: [s / 2, s / 2], html: '<svg viewBox="0 0 40 40" width="' + s + '" height="' + s + '"><g fill="' + color + '" stroke="#fff" stroke-width="3.2" stroke-linejoin="round">' + shape + '</g><g fill="none" stroke="rgba(0,0,0,.55)" stroke-width=".9" stroke-linejoin="round">' + shape + '</g></svg>' + (sel ? '<span class="pulse"></span>' : '') });
  }
  var dot = L.divIcon({ className: 'hz-dot', iconSize: [14, 14], iconAnchor: [7, 7], html: '<span style="display:block;width:14px;height:14px;border-radius:50%;background:#fff;border:3px solid #0A2233;box-sizing:border-box"></span>' });
  var pt = {}; D.points.forEach(function (q) { pt[q.nama] = q; });
  D.polys.forEach(function (p) {
    var on = !!p.sel, q = pt[p.nama];
    var layer = L.geoJSON(p.geo, { style: function () {
      return on ? { color: 'rgba(255,255,255,.92)', weight: 4, fillColor: p.fill, fillOpacity: 0.45, dashArray: p.rank >= 2 ? '' : (p.rank === 1 ? '6 4' : '1 5') }
                : { color: 'rgba(255,255,255,0)', weight: 2, fillColor: '#ffffff', fillOpacity: 0.01 }; } }).addTo(group);
    layer.bindTooltip(on ? esc(p.nama) + ': ' + esc(p.text) : esc(p.nama) + ' (klik untuk melihat kerawanan)', { sticky: true }).on('click', function () { location.href = p.href; });
    if (q) {
      var m = L.marker(q.latlng, { icon: on ? icon(q.rank, q.fill, true) : dot, title: on ? q.nama + ': ' + q.text : q.nama, alt: on ? q.nama + ': ' + q.text : q.nama, riseOnHover: true, zIndexOffset: on ? 2000 : 0 }).addTo(map);
      m.bindTooltip(esc(q.short) + (on && q.sub ? '<br><span style="font-weight:500;opacity:.85">' + esc(q.sub) + '</span>' : ''), { permanent: true, direction: 'bottom', offset: [0, on ? 14 : 8], className: 'lf-lab' });
      m.on('click', function () { location.href = q.href; });
    }
  });
  if (D.polys.length) map.fitBounds(group.getBounds().pad(0.06), { maxZoom: 17 }); else map.fitBounds(D.bounds, { padding: [6, 6] });
  setTimeout(function () { map.invalidateSize(); }, 60);
})();
