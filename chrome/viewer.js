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

    const initialMeta = extractTitleAndFaviconFromHtml(entry.html, entry.sourceUrl);
    if (initialMeta.title) document.title = initialMeta.title;
    if (initialMeta.favicon) setFaviconLink(initialMeta.favicon);

    if (entry && entry.blockedByFrame) {
      console.warn('viewer: framing blocked detected for', entry.sourceUrl, 'navigating current tab to original');
      try { window.location.href = entry.sourceUrl; } catch (e) { }
      return;
    }

    try {
      let done = false;
      function cleanListeners() {
        if (!iframe) return;
        iframe.removeEventListener('load', onLoad);
        iframe.removeEventListener('error', onError);
      }

      function onLoad() {
        if (done) return;
        done = true;
        try {
          const doc = iframe.contentDocument || (iframe.contentWindow && iframe.contentWindow.document);
          if (doc) {
            try {
              const title = doc.title;
              if (title) document.title = title;
              const iconEl = doc.querySelector && doc.querySelector('link[rel~="icon"]');
              if (iconEl && (iconEl.getAttribute('href') || iconEl.href)) {
                try { setFaviconLink(new URL(iconEl.getAttribute('href') || iconEl.href, entry.sourceUrl).href); } catch (e) { }
              } else {
                try { setFaviconLink(new URL('/favicon.ico', entry.sourceUrl).href); } catch (e) { }
              }
            } catch (e) { }
          }
        } catch (e) { }
        console.log('viewer: iframe load event fired');
        cleanListeners();
        try { chrome.storage.local.remove(key); } catch (e) { }
      }

      function onError() {
        if (done) return;
        done = true;
        cleanListeners();
        console.warn('viewer: iframe error event fired, redirecting to original', entry.sourceUrl);
        try { window.location.href = entry.sourceUrl; } catch (e) { }
      }

      if (iframe) {
        iframe.addEventListener('load', onLoad);
        iframe.addEventListener('error', onError);
        iframe.src = entry.sourceUrl;
      } else {
        if (iframe) iframe.srcdoc = `<p>No iframe element found. <a href="${entry.sourceUrl}" target="_blank" rel="noopener">Open original</a></p>`;
      }

    } catch (err) {
      console.error('Error preparing preview', err);
      if (iframe) iframe.srcdoc = '<p>Failed to render preview.</p>';
      try { chrome.storage.local.remove(key); } catch (e) { }
    }
  });
}

(function () {
  let rawDomain = getQueryParam('domain');
  const topQ = getQueryParam('q');
  if (rawDomain && topQ && rawDomain.indexOf('q=') === -1) {
    rawDomain = rawDomain + (rawDomain.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(topQ);
  }
  if (!rawDomain) rawDomain = 'unknown';
  console.log('viewer: raw domain param ->', rawDomain);
  const domain = parseDomainInput(rawDomain);
  console.log('viewer: parsed domain ->', domain);
  showPreviewForDomain(domain);
})();
