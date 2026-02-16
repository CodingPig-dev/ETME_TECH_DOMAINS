const FORCE_REDIRECT = true;

const API_BASE = 'https://epi.etme-tech.me/web/get.php?domain=';

chrome.tabs.onUpdated.addListener((tabId, changeInfo, tab) => {
  chrome.storage.local.get('consent', (result) => {
    if (!result.consent) return; // Do not intercept if not consented
    try {
      if (changeInfo.status !== 'complete' || !tab.url) return;
      const url = new URL(tab.url);
      if (!/(^|\.)google\./i.test(url.hostname)) return;
      if (url.pathname !== '/search') return;
      const q = url.searchParams.get('q');
      if (!q) return;
      let decoded = q;
      for (let i = 0; i < 5; i++) {
        try {
          const d = decodeURIComponent(decoded);
          if (d === decoded) break;
          decoded = d;
        } catch (e) { break; }
      }
      const extracted = extractIdFromText(decoded);
      console.log('ETME raw q=', q, 'decoded=', decoded, 'extracted=', extracted);
      let id = extracted || null;
      if (!id) {
        if (!FORCE_REDIRECT && !decoded.includes('@etme') && !decoded.toLowerCase().includes('etme:')) return;
        if (decoded.includes('@')) id = decoded.split('@')[0];
        else id = decoded;
        id = id.replace(/^[a-z]+:(?:\/\/)?/i, '');
      }
      if (!id) return;

      id = id.trim();
      id = id.split(/[\/ %?#\s]/)[0];
      id = id.replace(/\.+$/, '');
      const lastLabel = id.includes('.') ? id.split('.').pop() : '';
      console.log('ETME check id=', id, 'lastLabel=', lastLabel);
      const relaxLength = decoded && (/^etme:/i.test(decoded) || /@etme$/i.test(decoded));
      if (!lastLabel) {
        if (!relaxLength) return;
      }
      if (!relaxLength) {
        if (!/^[A-Za-z]{4,63}$/.test(lastLabel)) return;
      } else {
        if (!/^[A-Za-z]{0,63}$/.test(lastLabel)) return;
      }
      if (tab._etmeHandled) return;
      tab._etmeHandled = true;

      handleIdForTab(tabId, id);
    } catch (err) {
      console.error('background onUpdated error', err);
    }
  });
});

function removeCspMetaAndBaseTags(html) {
  let out = html.replace(/<meta[^>]*http-equiv=["']?content-security-policy["']?[^>]*>/ig, '');
  out = out.replace(/<meta[^>]*name=["']?content-security-policy["']?[^>]*>/ig, '');
  out = out.replace(/<base[^>]*>/ig, '');
  return out;
}

function insertBaseTag(html, baseHref) {
  const baseTag = `<base href="${baseHref}">`;
  if (/<head[^>]*>/i.test(html)) {
    return html.replace(/<head([^>]*)>/i, `<head$1>${baseTag}`);
  }
  if (/<body[^>]*>/i.test(html)) {
    return html.replace(/<body([^>]*)>/i, `<head>${baseTag}</head><body$1>`);
  }
  return `<!doctype html>\n<head>${baseTag}</head>\n` + html;
}

function extractIdFromText(input) {
  if (!input) return null;
  let s = input.trim();
  s = s.replace(/^['"]|['"]$/g, '');

  for (let i = 0; i < 5; i++) {
    try {
      const d = decodeURIComponent(s);
      if (d === s) break;
      s = d;
    } catch (e) { break; }
  }

  const m1 = s.match(/etme:\/\/([^\/?#&\s]+)/i);
  if (m1) return m1[1];
  const m2 = s.match(/etme:(?:\/\/)?([^\/?#&\s]+)/i);
  if (m2) return m2[1];

  try {
    const u = new URL(s);
    const mm = u.href.match(/etme:\/\/([^\/?#&\s]+)/i);
    if (mm) return mm[1];
    for (const pair of u.searchParams) {
      const v = pair[1];
      if (!v) continue;
      const ex = extractIdFromText(v);
      if (ex) return ex;
    }
  } catch (e) {}

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
    const ex = extractIdFromText(qv);
    if (ex) return ex;
  }

  return null;
}

async function handleIdForTab(tabId, id) {
  try {
    const apiUrl = API_BASE + encodeURIComponent(id);
    const apiRes = await fetch(apiUrl);
    if (!apiRes.ok) {
      console.error('API fetch failed', apiRes.status);
      return;
    }
    const apiJson = await apiRes.json();
    const targetUrl = apiJson && apiJson.url;
    if (!targetUrl) {
      console.log('API did not return url for', id);
      return;
    }
    const pageRes = await fetch(targetUrl);
    if (!pageRes.ok) {
      console.error('Failed to fetch target page', pageRes.status);
      return;
    }
    const html = await pageRes.text();
    let cleaned = removeCspMetaAndBaseTags(html);
    cleaned = insertBaseTag(cleaned, targetUrl);

    const xfo = pageRes.headers.get('x-frame-options') || pageRes.headers.get('X-Frame-Options') || null;
    const csp = pageRes.headers.get('content-security-policy') || pageRes.headers.get('Content-Security-Policy') || null;
    const lowerXfo = xfo ? xfo.toLowerCase() : '';
    const lowerCsp = csp ? csp.toLowerCase() : '';
    const blockedByFrame = (lowerXfo && (lowerXfo.includes('deny') || lowerXfo.includes('sameorigin') || lowerXfo.includes('same-origin'))) || (lowerCsp && lowerCsp.includes('frame-ancestors'));

    const key = `preview:${id}`;
    const value = { html: cleaned, sourceUrl: targetUrl, createdAt: Date.now(), headers: { 'x-frame-options': xfo, 'content-security-policy': csp }, blockedByFrame };
    const toSet = {};
    toSet[key] = value;
    await chrome.storage.local.set(toSet);
    const viewerUrl = chrome.runtime.getURL('viewer.html') + '?domain=' + encodeURIComponent(id);
    await chrome.tabs.update(tabId, { url: viewerUrl });
    setTimeout(async () => {
      try {
        await chrome.storage.local.remove(key);
      } catch (e) { }
    }, 5 * 60 * 1000);

  } catch (err) {
    console.error('handleIdForTab error', err);
  }
}
