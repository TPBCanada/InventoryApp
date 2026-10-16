// geofence.php: Google Map with an editable circle that mirrors the lat/lng/radius inputs.
(function () {
  'use strict';

  const cfg      = window.geofenceConfig || {};
  const latIn    = document.getElementById('gfLat');
  const lngIn    = document.getElementById('gfLng');
  const radiusIn = document.getElementById('gfRadius');
  const hereBtn  = document.getElementById('gfHere');
  const mapEl    = document.getElementById('gfMap');
  const mapMsg   = document.getElementById('gfMapMsg');
  const address  = document.getElementById('gfAddress');
  const search   = document.getElementById('gfSearch');

  if (!latIn || !lngIn || !radiusIn) return;

  const DEFAULT_CENTER = { lat: 43.6532, lng: -79.3832 }; // Toronto; only used until a location is set
  let map = null;
  let circle = null;
  let syncing = false;

  function say(text) {
    if (!mapMsg) return;
    mapMsg.textContent = text || '';
    mapMsg.hidden = !text;
  }

  function readInputs() {
    const lat = parseFloat(latIn.value);
    const lng = parseFloat(lngIn.value);
    const radius = parseInt(radiusIn.value, 10);
    return {
      center: isFinite(lat) && isFinite(lng) ? { lat: lat, lng: lng } : null,
      radius: isFinite(radius) ? radius : 300
    };
  }

  function writeInputs(center, radius) {
    syncing = true;
    if (center) {
      latIn.value = center.lat.toFixed(6);
      lngIn.value = center.lng.toFixed(6);
    }
    if (radius != null) {
      radiusIn.value = Math.round(Math.min(cfg.maxRadius || 2000, Math.max(cfg.minRadius || 50, radius)));
    }
    syncing = false;
  }

  // --- Map ---

  function setCenter(latLng, pan) {
    const c = { lat: latLng.lat(), lng: latLng.lng() };
    if (circle) {
      circle.setCenter(c);
    }
    writeInputs(c, null);
    if (pan && map) map.panTo(c);
  }

  async function initMap() {
    // loading=async: classes must come from importLibrary, not the google.maps namespace directly.
    const { Map, Circle } = await google.maps.importLibrary('maps');
    const { Geocoder } = await google.maps.importLibrary('geocoding');

    const start = readInputs();
    map = new Map(mapEl, {
      center: start.center || DEFAULT_CENTER,
      zoom: start.center ? 16 : 11,
      mapTypeId: 'hybrid',
      streetViewControl: false,
      fullscreenControl: true
    });

    circle = new Circle({
      map: map,
      center: start.center || DEFAULT_CENTER,
      radius: start.radius,
      editable: true,
      draggable: true,
      strokeColor: '#0bb8de',
      strokeWeight: 2,
      fillColor: '#0dcaf0',
      fillOpacity: 0.2
    });

    if (!start.center) say('No location saved yet. Search an address or click the map to place the fence.');

    circle.addListener('center_changed', function () {
      if (!syncing) writeInputs({ lat: circle.getCenter().lat(), lng: circle.getCenter().lng() }, null);
    });
    circle.addListener('radius_changed', function () {
      if (!syncing) writeInputs(null, circle.getRadius());
    });
    map.addListener('click', function (e) {
      setCenter(e.latLng, false);
      say('');
    });

    [latIn, lngIn, radiusIn].forEach(function (input) {
      input.addEventListener('change', function () {
        const v = readInputs();
        syncing = true;
        if (v.center) { circle.setCenter(v.center); map.panTo(v.center); }
        circle.setRadius(v.radius);
        syncing = false;
      });
    });

    if (search) {
      const geocoder = new Geocoder();
      search.addEventListener('submit', function () {
        const q = address.value.trim();
        if (!q) return;
        say('Searching…');
        geocoder.geocode({ address: q }, function (results, status) {
          if (status !== 'OK' || !results || !results[0]) {
            say(status === 'ZERO_RESULTS' ? 'No match for that address.' : 'Address search failed (' + status + '). Check that the Geocoding API is enabled for this key.');
            return;
          }
          setCenter(results[0].geometry.location, true);
          map.setZoom(17);
          say('Found: ' + results[0].formatted_address + '. Adjust the circle if needed, then save.');
        });
      });
    }
  }

  function loadMaps() {
    if (!mapEl || !cfg.mapsKey) return;
    window.__gfInitMap = function () {
      initMap().catch(function (e) {
        console.error('[geofence] map init failed', e);
        say('The map failed to load: ' + (e && e.message ? e.message : e));
      });
    };
    window.gm_authFailure = function () {
      say('Google rejected the Maps API key. Check the key, that the Maps JavaScript API is enabled, and its website restrictions.');
    };
    const s = document.createElement('script');
    s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(cfg.mapsKey) + '&callback=__gfInitMap&loading=async&v=weekly';
    s.async = true;
    if (cfg.nonce) s.nonce = cfg.nonce;
    s.onerror = function () { say('Could not reach Google Maps.'); };
    document.head.appendChild(s);
  }

  // --- Use my current location ---

  if (hereBtn) {
    hereBtn.addEventListener('click', function () {
      if (!navigator.geolocation) { alert('This browser can’t share its location.'); return; }
      hereBtn.disabled = true;
      hereBtn.textContent = 'Locating…';
      navigator.geolocation.getCurrentPosition(function (pos) {
        const c = { lat: pos.coords.latitude, lng: pos.coords.longitude };
        writeInputs(c, null);
        if (circle) { syncing = true; circle.setCenter(c); syncing = false; map.panTo(c); map.setZoom(17); }
        say('Centred on your location (±' + Math.round(pos.coords.accuracy) + ' m). Save to apply.');
        hereBtn.disabled = false;
        hereBtn.textContent = 'Use my current location';
      }, function () {
        alert('Couldn’t get your location. Allow location access and try again.');
        hereBtn.disabled = false;
        hereBtn.textContent = 'Use my current location';
      }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
    });
  }

  loadMaps();
})();
