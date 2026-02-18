function getQueryParam(name) {
  const params = new URLSearchParams(location.search);
  return params.get(name);
}

function parseDomainInput(input) {
  if (!input) return input;
  let s = input.trim();
  s = s.replace(/^['"]|['"]$/g, '');

  for (let i = 0; i < 5; i++) {
    try {
      const d = decodeURIComponent(s);
      if (d === s) break;
      s = d;
    } catch (e) {
      break;
    }
  }

  function extractFrom(text) {
    if (!text) return null;
    const m = text.match(/etme:\/\/([^\/?#&\s]+)/i);
    if (m) return m[1];
    try {
      const d = decodeURIComponent(text);
      const mm = d.match(/etme:\/\/([^\/?#&\s]+)/i);
      if (mm) return mm[1];
    } catch (e) {}
    return null;
  }

  const qMatch = s.match(/[?&]q=([^&]+)/i);
  if (qMatch && qMatch[1]) {
    let qv = qMatch[1];
    for (let i = 0; i < 5; i++) {
      try {
        const d = decodeURIComponent(qv);
        if (d === qv) break;
        qv = d;
      } catch (e) { break; }
    }
    const ex = extractFrom(qv);
    if (ex) return ex;
  }

  try {
    const maybeUrl = new URL(s);
    const foundHref = extractFrom(maybeUrl.href);
    if (foundHref) return foundHref;
    for (const pair of maybeUrl.searchParams) {
      let v = pair[1];
      if (!v) continue;
      for (let i = 0; i < 5; i++) {
        try {
          const d = decodeURIComponent(v);
          if (d === v) break;
          v = d;
        } catch (e) {
          break;
        }
      }
      const ex = extractFrom(v);
      if (ex) return ex;
    }
  } catch (e) {}

  const direct = extractFrom(s);
  if (direct) return direct;

  const atIdx = s.search(/@etme$/i);
  if (atIdx !== -1) {
    let candidate = s.slice(0, atIdx);
    candidate = candidate.replace(/^[a-z]+:\/\//i, '');
    const m = candidate.match(/^([^\/?#]+)/);
    if (m) return m[1];
    return candidate;
  }

  try {
    const u = new URL(s);
    if (u.hostname) return u.hostname;
    return s;
  } catch (e) {}

  s = s.replace(/^[a-z]+:\/\//i, '');
  const m = s.match(/^([^\/?#]+)/);
  return m ? m[1] : s;
}

function setFaviconLink(href) {
  let link = document.querySelector('link[rel~="icon"]');
  if (!link) {
    link = document.createElement('link');
    link.rel = 'icon';
    document.head.appendChild(link);
  }
  link.href = href;
}

function sanitizeHtmlForPreview(html, sourceUrl) {
  try {
    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');

    doc.querySelectorAll('script, iframe, object, embed').forEach(n => n.remove());

    doc.querySelectorAll('*').forEach(el => {
      const attrs = Array.from(el.attributes || []);
      for (const a of attrs) {
        if (/^on/i.test(a.name)) el.removeAttribute(a.name);
      }
    });

    doc.querySelectorAll('meta[http-equiv], meta[name], base').forEach(n => {
      const he = (n.getAttribute('http-equiv') || '').toLowerCase();
      const name = (n.getAttribute('name') || '').toLowerCase();
      if (he === 'content-security-policy' || name === 'content-security-policy' || n.tagName.toLowerCase() === 'base') n.remove();
    });

    doc.querySelectorAll('img').forEach(img => {
      let w = null;
      let h = null;
      const wa = img.getAttribute('width');
      const ha = img.getAttribute('height');
      if (wa && !isNaN(parseInt(wa, 10))) w = parseInt(wa, 10);
      if (ha && !isNaN(parseInt(ha, 10))) h = parseInt(ha, 10);
      const style = (img.getAttribute('style') || '').toLowerCase();
      const mw = style.match(/width\s*:\s*([0-9]+)px/);
      const mh = style.match(/height\s*:\s*([0-9]+)px/);
      if (!w && mw && mw[1]) w = parseInt(mw[1], 10);
      if (!h && mh && mh[1]) h = parseInt(mh[1], 10);
      if (!w) w = 200;
      if (!h) h = 200;
      const h1 = doc.createElement('h1');
      h1.textContent = 'BRUH';
      const fontSize = Math.max(12, Math.floor(h * 0.4));
      h1.setAttribute('style', `display:inline-block;width:${w}px;height:${h}px;line-height:${h}px;text-align:center;margin:0;font-size:${fontSize}px;background:#eee;color:#111;overflow:hidden;`);
      img.replaceWith(h1);
    });

    const topbar = doc.createElement('div');
    topbar.setAttribute('style', 'position:relative;padding:8px;background:#f1f1f1;border-bottom:1px solid #ccc;font-family:Arial,sans-serif;');
    const openLink = doc.createElement('a');
    openLink.href = sourceUrl || '#';
    openLink.target = '_blank';
    openLink.rel = 'noopener';
    openLink.textContent = 'Open original';
    openLink.setAttribute('style', 'margin-right:12px;color:#06c;text-decoration:none;');
    topbar.appendChild(openLink);

    if (doc.body && doc.body.firstChild) doc.body.insertBefore(topbar, doc.body.firstChild);
    else if (doc.body) doc.body.appendChild(topbar);

    return '<!doctype html>\n' + (doc.documentElement ? doc.documentElement.outerHTML : doc.body.innerHTML);
  } catch (err) {
    console.error('sanitizeHtmlForPreview failed', err);
    return null;
  }
}

function extractTitleAndFaviconFromHtml(html, sourceUrl) {
  try {
    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');
    let title = (doc && doc.title) || null;
    if (!title) {
      const og = doc.querySelector('meta[property="og:title"]') || doc.querySelector('meta[name="twitter:title"]');
      if (og && og.getAttribute('content')) title = og.getAttribute('content');
    }
    let favicon = null;
    const iconEl = doc && doc.querySelector && (doc.querySelector('link[rel~="icon"]') || doc.querySelector('link[rel~="shortcut icon"]') || doc.querySelector('link[rel~="apple-touch-icon"]'));
    if (iconEl && (iconEl.getAttribute('href') || iconEl.href)) {
      try { favicon = new URL(iconEl.getAttribute('href') || iconEl.href, sourceUrl).href; } catch (e) { }
    }
    if (!favicon) {
      try { favicon = new URL('/favicon.ico', sourceUrl).href; } catch (e) { favicon = null; }
    }
    return { title, favicon };
  } catch (err) {
    return { title: null, favicon: null };
  }
}

async function showPreviewForDomain(domain) {
  const key = `preview:${domain}`;
  chrome.storage.local.get(key, (items) => {
    const entry = items[key];
    const iframe = document.getElementById('previewFrame');

    if (entry && entry.title) document.title = entry.title;
    if (entry && entry.favicon) setFaviconLink(entry.favicon);

    if (!entry) {
      try {
        if (domain && typeof domain === 'string' && domain.toLowerCase().endsWith('.bruh')) {
          const mappedHost = domain.replace(/\.bruh$/i, '.com');
          const mappedUrl = 'https://' + mappedHost + '/';
          console.log('viewer: no stored preview for', domain, 'mapping to', mappedUrl, 'and trying iframe first');
          if (iframe) {
            let done = false;
            function clean() {
              if (!iframe) return;
              iframe.removeEventListener('load', onMappedLoad);
              iframe.removeEventListener('error', onMappedError);
            }
            function onMappedLoad() {
              if (done) return;
              done = true;
              try {
              } catch (e) {
                console.warn('viewer: iframe loaded but cross-origin (or blocked). Keeping viewer open.');
              }
              clean();
            }
            function onMappedError() {
              if (done) return;
              done = true;
              clean();
              console.warn('viewer: iframe error while loading mapped url, redirecting top to', mappedUrl);
              try { window.location.href = mappedUrl; } catch (e) { }
            }
            iframe.addEventListener('load', onMappedLoad);
            iframe.addEventListener('error', onMappedError);
            iframe.src = mappedUrl;
          } else {
            try { window.location.href = mappedUrl; } catch (e) { }
          }
          return;
        }
      } catch (e) { }
      if (iframe) iframe.srcdoc = '<p>Preview not available. The preview may have expired or failed to fetch.</p>';
      return;
    }

    if (entry && entry.blockedByFrame) {
      console.warn('viewer: framing blocked detected for', entry.sourceUrl, 'redirecting directly');
      try { window.location.href = entry.sourceUrl; } catch (e) { }
      return;
    }

    if (iframe) {
      let done = false;
      function cleanListeners() {
        if (!iframe) return;
        iframe.removeEventListener('load', onLoad);
        iframe.removeEventListener('error', onError);
      }

      function onLoad() {
        console.log('viewer: onLoad called for iframe');
        if (done) return;
        done = true;
        try {
          let doc = null;
          try {
            doc = iframe.contentDocument || (iframe.contentWindow && iframe.contentWindow.document);
          } catch (e) {
            console.log('viewer: cannot access iframe content, cross-origin');
          }
          console.log('viewer: doc =', doc);

          // Fetch the source URL to get the latest title and favicon
          fetch(entry.sourceUrl).then(response => {
            if (!response.ok) throw new Error('Fetch failed');
            return response.text();
          }).then(html => {
            const { title, favicon } = extractTitleAndFaviconFromHtml(html, entry.sourceUrl);
            if (title) document.title = title;
            if (favicon) setFaviconLink(favicon);
          }).catch(e => {
            console.log('viewer: failed to fetch for title/favicon, keeping stored values', e);
          });

          try {
            const title = doc && doc.title;
            console.log('viewer: title =', title);
          } catch (e) {
            console.log('viewer: cross-origin access failed, but staying in iframe');
          }
        } catch (err) {
          console.error('viewer onLoad error, not redirecting', err);
        }
      }

      function onError() {
        console.log('viewer: onError called for iframe, redirecting');
        if (done) return;
        done = true;
        cleanListeners();
        console.warn('viewer: iframe error event fired, redirecting to original', entry.sourceUrl);
        try { window.location.href = entry.sourceUrl; } catch (e) { }
      }

      iframe.addEventListener('load', onLoad);
      iframe.addEventListener('error', onError);
      iframe.src = entry.sourceUrl;
    } else {
      try { window.location.href = entry.sourceUrl; } catch (e) { }
    }
  });
}

function fetchWithConsent(url, options) {
  return new Promise((resolve, reject) => {
    chrome.storage.local.get('consent', async (res) => {
      try {
        const consent = !!(res && res.consent);
        const hdrs = (options && options.headers) ? new Headers(options.headers) : new Headers();
        if (consent) {
          hdrs.set('X-ETME-Consent', '1');
          console.debug('viewer fetchWithConsent: added X-ETME-Consent for', url);
        }
        const opts = Object.assign({}, options || {}, { headers: hdrs });
        const r = await fetch(url, opts);
        resolve(r);
      } catch (e) { reject(e); }
    });
  });
}

chrome.storage.local.get('consent', (result) => {
  if (!result.consent) {
    document.body.textContent = 'You must consent to the privacy policy to use this extension. Please click the extension icon to agree.';
    return;
  }

  (function () {
    let rawDomain = getQueryParam('domain');
    const topQ = getQueryParam('q');
    if (!rawDomain || rawDomain === 'null' || rawDomain === 'undefined' || (typeof rawDomain === 'string' && rawDomain.trim() === '')) {
      document.body.innerHTML = '<div style="padding:16px;font-family:Arial,sans-serif;"><h3>No valid domain provided</h3><p>The extension did not receive a domain to preview. Please try again from the search results.</p></div>';
      return;
    }
    if (rawDomain && topQ && rawDomain.indexOf('q=') === -1) {
      rawDomain = rawDomain + (rawDomain.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(topQ);
    }
    if (!rawDomain) rawDomain = 'unknown';
    console.log('viewer: raw domain param ->', rawDomain);
    const domain = parseDomainInput(rawDomain);
    console.log('viewer: parsed domain ->', domain);
    showPreviewForDomain(domain);
  })();
});

if (location.pathname && location.pathname.endsWith('/null')) {
  document.body.innerHTML = '<div style="padding:16px;font-family:Arial,sans-serif;"><h3>Invalid viewer URL</h3><p>The viewer was opened with an invalid URL ("/null"). Please reload the extension (chrome://extensions) and try again from the search results. If the problem persists, check the background service worker console for logs.</p></div>';
  throw new Error('viewer opened at /null - aborting script');
}
