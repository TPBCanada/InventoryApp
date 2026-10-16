/* ---------- Inventory autosuggest (reusable) ----------------
 * - Works on any input tagged with data-suggest="inventory" (or legacy #q)
 * - Fetches SKUs and location codes in parallel (server-side LIKE)
 * - Click/Enter on SKU -> fill input & submit form
 * - Click/Enter on Location -> navigate to place.php with parsed parts
 * ------------------------------------------------------------*/
(function(){
  function buildUrl(params){
    const url = new URL(window.location.href);
    for (const [k,v] of Object.entries(params)) {
      if (v === null) url.searchParams.delete(k);
      else url.searchParams.set(k, String(v));
    }
    return url.toString();
  }

  // Per-input suggest wiring
  function attachSuggest(input, {form, box} = {}){
    if (!input) return;
    const targetForm = form || input.form || document.getElementById('searchForm');
    let targetBox = box || document.getElementById(input.dataset.suggestBox || 'suggestBox');
    const autoSubmit = input.dataset.suggestSubmit !== 'false';
    if (!targetBox) {
      targetBox = document.createElement('div');
      targetBox.id = input.dataset.suggestBox || 'suggestBox';
      targetBox.className = 'suggest-box inv-suggest';
      input.insertAdjacentElement('afterend', targetBox);
    } else {
      targetBox.classList.add('suggest-box', 'inv-suggest');
    }
    if (!targetForm || !targetBox) return;

    let t=null, blurTimer=null, ticket=0, sel=-1;
    const controllers = { sku:null, loc:null };

    function hide(){ targetBox.style.display='none'; targetBox.innerHTML=''; sel=-1; blurTimer=null; }
    function show(){ targetBox.style.display='block'; }

    function render(skus, locs){
      targetBox.innerHTML = '';
      sel = -1;
      const frag = document.createDocumentFragment();

      if (Array.isArray(skus) && skus.length) {
        const g = document.createElement('div'); g.className='suggest-group';
        const title = document.createElement('div'); title.className='suggest-title'; title.textContent='SKUs';
        g.appendChild(title);
        skus.forEach((r, i) => {
          const it = document.createElement('div'); it.className='suggest-item'; it.dataset.kind='sku'; it.dataset.index=String(i);
          const sku = document.createElement('span'); sku.className='sku-chip'; sku.textContent = r.sku_num;
          it.appendChild(sku);
          if (r.desc) { const d = document.createElement('span'); d.style.color='#6c757d'; d.textContent = r.desc; it.appendChild(d); }
          it.addEventListener('mousedown', (e)=>{ e.preventDefault(); choose({kind:'sku', value:r.sku_num}); });
          g.appendChild(it);
        });
        frag.appendChild(g);
      }

      if (Array.isArray(locs) && locs.length) {
        const g = document.createElement('div'); g.className='suggest-group';
        const title = document.createElement('div'); title.className='suggest-title'; title.textContent='Locations';
        g.appendChild(title);
        locs.forEach((r, i) => {
          const it = document.createElement('div'); it.className='suggest-item'; it.dataset.kind='loc'; it.dataset.index=String(i);
          it._loc = r; // keep full object for Enter handling
          const code = document.createElement('span'); code.className='loc-chip'; code.textContent = r.code;
          it.appendChild(code);
          it.addEventListener('mousedown', (e)=>{ e.preventDefault(); choose({kind:'loc', value:r}); });
          g.appendChild(it);
        });
        frag.appendChild(g);
      }

      if (!frag.children.length) { hide(); return; }
      targetBox.appendChild(frag);
      show();
    }

    function choose(selObj){
      if (!selObj) return;
      if (selObj.kind === 'sku') {
        input.value = selObj.value;
        hide();
        if (autoSubmit && targetForm) {
          if (targetForm.requestSubmit) targetForm.requestSubmit(); else targetForm.submit();
        }
      } else if (selObj.kind === 'loc') {
        const r = selObj.value; // {code,row_code,bay_num,level_code,side}
        const params = {
          row_code: r.row_code,
          bay_num: r.bay_num,
          level_code: r.level_code,
          side: r.side
        };
        const qs = new URLSearchParams(params).toString();
        window.location.href = 'place.php?' + qs;
      }
    }

    async function fetchSuggest(kind, q, myTicket){
      // cancel previous in-flight of the same kind
      controllers[kind]?.abort?.();
      const controller = new AbortController();
      controllers[kind] = controller;

      const url = buildUrl({ suggest: '1', type: kind, q, ajax: null, path: null });
      let attempts = 0;
      const maxAttempts = 3;
      const initialDelay = 150;
      
      while(attempts < maxAttempts) {
        try {
          const res = await fetch(url, { headers: { 'Accept': 'application/json' }, signal: controller.signal });
          
          if (res.status === 429 || res.status >= 500) { // retryable
            attempts++;
            if (attempts < maxAttempts) {
              const delay = initialDelay * Math.pow(2, attempts - 1);
              await new Promise(resolve => setTimeout(resolve, delay));
              continue;
            }
          }

          if (!res.ok) throw new Error('HTTP '+res.status);
          
          const data = await res.json();
          if (ticket !== myTicket) return null; // stale
          return Array.isArray(data.items) ? data.items : [];

        } catch(error) {
          if (error.name === 'AbortError') return null;
          if (attempts >= maxAttempts - 1) throw error;
          attempts++;
          const delay = initialDelay * Math.pow(2, attempts - 1);
          await new Promise(resolve => setTimeout(resolve, delay));
        }
      }
      return null;
    }

    function looksLikeLocation(q){
      // heuristic: contains at least one dash like "R10-01" or ends with "-F"/"-B"
      return /-/.test(q) || /^[A-Za-z]+\d{0,}-/.test(q);
    }

    input.addEventListener('input', () => {
      clearTimeout(t);
      const q = input.value.trim();
      if (!q) { hide(); return; }
      const my = ++ticket;
      t = setTimeout(async () => {
        try {
          const wantLoc = looksLikeLocation(q);
          const [skus, locs] = await Promise.all([
            fetchSuggest('sku', q, my),
            wantLoc ? fetchSuggest('loc', q, my) : Promise.resolve([])
          ]);
          if (ticket !== my) return; // stale
          render(skus||[], locs||[]);
        } catch(e){
          console.error(e); hide();
        }
      }, 150);
    });

    input.addEventListener('focus', () => {
      if (input.value.trim()) {
        input.dispatchEvent(new Event('input')); // trigger suggestions for current text
      } else {
        // Show some initial SKUs (empty q) — ask for empty suggest
        const my = ++ticket;
        Promise.all([fetchSuggest('sku','',my), Promise.resolve([])]).then(([skus])=>{
          if (ticket !== my) return;
          render(skus||[], []);
        }).catch(()=>hide());
      }
    });

    // Delay hide so click can register
    input.addEventListener('blur', () => {
      blurTimer = setTimeout(hide, 150);
    });
    targetBox.addEventListener('mousedown', () => {
      if (blurTimer) clearTimeout(blurTimer);
    }); // keep box open during click

    // Keyboard navigation
    input.addEventListener('keydown', (e) => {
      if (targetBox.style.display === 'none') return;
      const items = Array.from(targetBox.querySelectorAll('.suggest-item'));
      if (!items.length) return;

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        sel = (sel + 1) % items.length;
        items.forEach(el => el.classList.remove('active'));
        items[sel].classList.add('active');
        items[sel].scrollIntoView({block:'nearest'});
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        sel = (sel - 1 + items.length) % items.length;
        items.forEach(el => el.classList.remove('active'));
        items[sel].classList.add('active');
        items[sel].scrollIntoView({block:'nearest'});
      } else if (e.key === 'Enter') {
        if (sel >= 0) {
          e.preventDefault();
          const el = items[sel];
          if (el.dataset.kind === 'sku') {
            choose({kind:'sku', value: el.querySelector('.sku-chip')?.textContent || ''});
          } else {
            choose({kind:'loc', value: el._loc || null});
          }
        }
      } else if (e.key === 'Escape') {
        hide();
      }
    });
  }

  function autoInit(){
    const inputs = Array.from(document.querySelectorAll('[data-suggest=\"inventory\"]'));
    if (!inputs.length) {
      const legacy = document.getElementById('q');
      if (legacy) inputs.push(legacy);
    }
    inputs.forEach((inp) => {
      const box = inp.dataset.suggestBox ? document.getElementById(inp.dataset.suggestBox) : null;
      attachSuggest(inp, { box });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoInit);
  } else {
    autoInit();
  }
})();
