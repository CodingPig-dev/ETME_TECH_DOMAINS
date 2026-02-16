ETME TECH Domains - Chrome Extension (MV3)

What it does
- Detects Google search URLs where the query contains "@etme", "etme:", or similar patterns.
- Extracts the domain ID (e.g., "test.bruh" from "test.bruh@etme" or from "etme:test.bruh").
- Validates the domain: the last label (part after the last dot) must be 4-63 alphanumeric characters, unless the query starts with "etme:" or ends with "@etme" (in which case no minimum length).
- Calls the API at https://epi.etme-tech.me/web/get.php?domain=<id> which must return JSON like {"url":"https://example.com"}.
- Fetches the target page HTML, opens an extension viewer that displays the extracted domain and renders the fetched HTML (scripts removed).

Limitations
- The browser address bar cannot be changed to show "test.bruh"; the extension displays it inside the viewer UI.
- The extension uses broad host permissions ("<all_urls>") so it can fetch arbitrary pages returned by the API. You can restrict these permissions if you know the set of hosts in advance.
- The fetched HTML has scripts removed to avoid executing remote code; some pages may rely on JS and not render fully.

Installation
1. Open chrome://extensions
2. Enable Developer mode
3. "Load unpacked" and select this project folder

Notes for developers
- Files: manifest.json, background.js, viewer.html, viewer.js, styles.css
- The background service worker stores previews in chrome.storage.local and removes them after loading or after 5 minutes.
