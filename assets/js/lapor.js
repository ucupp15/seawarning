/* Laporan warga (v12): peta pemilih lokasi, lokasi GPS, pratinjau dan pengecilan foto, serta peta daftar laporan.
   Halaman tetap dapat dipakai tanpa JavaScript: lokasi diambil dari pilihan kampung. */
(function () {
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  var $ = function (id) { return document.getElementById(id); };

  /* ---------- peta ---------- */
  var host = $('lap-map'), raw = $('lap-map-data'), map = null, D = null, pin = null;
  if (host && raw && window.L) {
    D = JSON.parse(raw.textContent);
    map = L.map(host, { zoomSnap: 0.5, scrollWheelZoom: false, minZoom: 12, maxZoom: 19 });
    var nat = L.Browser.retina ? 16 : 17, opt = { maxNativeZoom: nat, maxZoom: 19, detectRetina: true, attribution: D.attr };
    var sat = L.tileLayer(D.sat, opt).addTo(map); L.tileLayer(D.lab, opt).addTo(map);
    map.fitBounds(D.bounds, { padding: [10, 10] });
    host.addEventListener('click', function () { map.scrollWheelZoom.enable(); });
    var loaded = 0, failed = 0, shown = false, msg = $('lap-map-msg');
    function fail() { if (shown || loaded) return; shown = true; host.classList.add('nosat'); if (msg) msg.innerHTML = '<div class="note warn"><div>Citra satelit tidak dapat dimuat (tidak ada internet). Garis kampung tetap tampil; Anda juga dapat memilih kampung.</div></div>'; }
    sat.on('tileload', function () { loaded++; }); sat.on('tileerror', function () { if (++failed >= 4) fail(); }); setTimeout(fail, 7000);
    (D.polys || []).forEach(function (p) { try { L.geoJSON(p.geo, { style: { color: '#ffffff', weight: 1.5, fillOpacity: 0.06, fillColor: '#7FDCF2' }, interactive: false }).addTo(map); } catch (e) {} });
    (D.kampung || []).forEach(function (k) { L.circleMarker([k.lat, k.lng], { radius: 4, color: '#fff', weight: 1.5, fillColor: '#0D84A8', fillOpacity: 1, interactive: false }).bindTooltip(esc(k.nama), { permanent: false }).addTo(map); });

    if (D.mode === 'daftar') {
      var g = L.featureGroup().addTo(map);
      (D.points || []).forEach(function (p) {
        var m = L.circleMarker([p.lat, p.lng], { radius: 6 + Math.min(10, p.cm / 10), color: '#fff', weight: 2, fillColor: p.c, fillOpacity: 0.9 }).addTo(g);
        m.bindPopup('<div style="min-width:160px"><img src="' + esc(p.img) + '" alt="" style="width:100%;border-radius:8px;margin-bottom:6px"><b>' + esc(p.st) + '</b>, ±' + esc(p.cm) + ' cm<br>' + esc(p.w) + (p.kp ? '<br>' + esc(p.kp) : '') + '<br><a href="#lap-' + esc(p.id) + '">Lihat rincian</a></div>');
      });
      if (g.getLayers().length) map.fitBounds(g.getBounds().pad(0.4), { maxZoom: 17 });
    }
  }

  /* ---------- formulir laporan ---------- */
  var form = $('lapor-form'); if (!form) return;
  var lat = $('lp-lat'), lng = $('lp-lng'), acc = $('lp-acc'), src = $('lp-src'), info = $('lp-loc'), gps = $('lp-gps'), kp = $('lp-kp');
  function inBox(a, b) { return D && a >= D.bbox[0][0] && a <= D.bbox[1][0] && b >= D.bbox[0][1] && b <= D.bbox[1][1]; }
  function nearest(a, b) { var best = null, bd = Infinity; (D && D.kampung || []).forEach(function (k) { var d = Math.pow(k.lat - a, 2) + Math.pow((k.lng - b) * Math.cos(a * Math.PI / 180), 2); if (d < bd) { bd = d; best = k; } }); return best; }
  function setLoc(a, b, how, accuracy) {
    lat.value = a.toFixed(6); lng.value = b.toFixed(6); src.value = how; acc.value = accuracy ? Math.round(accuracy) : '';
    if (map) { if (pin) pin.setLatLng([a, b]); else { pin = L.marker([a, b], { draggable: true, icon: L.divIcon({ className: '', iconSize: [30, 40], iconAnchor: [15, 38], html: '<svg viewBox="0 0 30 40" width="30" height="40"><path d="M15 1C7.3 1 1 7.2 1 14.9 1 25.4 15 39 15 39s14-13.6 14-24.1C29 7.2 22.7 1 15 1z" fill="#D64933" stroke="#fff" stroke-width="2"/><circle cx="15" cy="15" r="5" fill="#fff"/></svg>' }) }).addTo(map); pin.on('dragend', function () { var p = pin.getLatLng(); setLoc(p.lat, p.lng, 'peta'); }); } }
    var k = nearest(a, b); if (k && kp) kp.value = String(k.id);
    var ok = inBox(a, b);
    info.textContent = (ok ? 'Lokasi dipilih' : 'Lokasi di luar Pulo Panjang, geser penanda') + ': ' + a.toFixed(5) + '; ' + b.toFixed(5) + (accuracy ? ' (±' + Math.round(accuracy) + ' m)' : '') + (k && ok ? ', dekat ' + k.nama : '') + '.';
    info.style.color = ok ? '' : 'var(--bad)';
  }
  if (map) map.on('click', function (e) { setLoc(e.latlng.lat, e.latlng.lng, 'peta'); });
  if (navigator.geolocation && window.isSecureContext) {
    gps.hidden = false;
    gps.addEventListener('click', function () {
      info.textContent = 'Mencari lokasi…'; gps.disabled = true;
      navigator.geolocation.getCurrentPosition(function (p) {
        gps.disabled = false; setLoc(p.coords.latitude, p.coords.longitude, 'gps', p.coords.accuracy);
        if (map) map.setView([p.coords.latitude, p.coords.longitude], 17);
      }, function (e) { gps.disabled = false; info.textContent = e.code === 1 ? 'Izin lokasi ditolak. Ketuk peta atau pilih kampung.' : 'Lokasi tidak ditemukan. Ketuk peta atau pilih kampung.'; }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
    });
  }

  /* foto: pratinjau, lalu perkecil di ponsel (maks 1600 px, JPEG 0,82) agar unggahan ringan di sinyal lemah */
  var inp = $('lp-foto'), prev = $('lp-prev'), btn = $('lp-kirim'), busy = false;
  inp.addEventListener('change', function () {
    var f = inp.files && inp.files[0]; if (!f || !/^image\//.test(f.type)) return;
    var url = URL.createObjectURL(f); prev.src = url; prev.hidden = false;
    if (f.size < 1.2 * 1048576 || !window.DataTransfer || !HTMLCanvasElement.prototype.toBlob) return;
    var img = new Image(); busy = true; btn.disabled = true;
    img.onload = function () {
      var k = Math.min(1, 1600 / Math.max(img.naturalWidth, img.naturalHeight)), c = document.createElement('canvas');
      c.width = Math.round(img.naturalWidth * k); c.height = Math.round(img.naturalHeight * k); c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      c.toBlob(function (b) { busy = false; btn.disabled = false; if (!b || b.size >= f.size) return; try { var dt = new DataTransfer(); dt.items.add(new File([b], (f.name || 'foto').replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' })); inp.files = dt.files; } catch (e) {} }, 'image/jpeg', 0.82);
    };
    img.onerror = function () { busy = false; btn.disabled = false; };
    img.src = url;
  });
  form.addEventListener('submit', function (e) {
    if (busy) { e.preventDefault(); return; }
    if (!lat.value && !(kp && kp.value)) { e.preventDefault(); info.textContent = 'Tentukan lokasi dulu: pakai lokasi saya, ketuk peta, atau pilih kampung.'; info.style.color = 'var(--bad)'; info.scrollIntoView({ block: 'center' }); return; }
    btn.disabled = true; btn.lastChild.textContent = 'Mengirim…';
  });
})();
