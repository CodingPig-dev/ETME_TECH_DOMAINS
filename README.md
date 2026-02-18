# ETME TECH DOMAINS

ETME TECH DOMAINS is a Chrome extension that detects and resolves ETME domain patterns directly from Google search queries.

## Overview

This extension validates ETME domains (e.g., `@etme` or `etme://`), resolves them via an API, and securely displays the content in an integrated viewer.

---

## Installation

1. Clone the repository and navigate into it:
   ```bash
   git clone https://github.com/CodingPig-dev/ETME_TECH_DOMAINS.git
   cd ETME_TECH_DOMAINS
   ```
2. Open Google Chrome and go to: `chrome://extensions/`.
3. Enable **Developer mode**.
4. Select **Load unpacked** and choose the `chrome/` folder.

---

## How to Use

1. Open Google and enter a valid ETME query (e.g., `example@etme` or `etme://domain.tech`).
2. The extension automatically detects, validates, and resolves the domain.
3. The results are displayed in a secure embedded viewer.

### Supported ETME Patterns
- Example with `@etme`: `example.bruh@etme`
- Example with `etme://`: `etme://example.bruh`
- Direct domains: `example.bruh` (must meet minimum label requirements).

### What Happens in the Viewer?
- The viewer fetches HTML content from the resolved ETME URL.
- It sanitizes the HTML (removing unsafe elements like scripts or iframes).
- The content is then displayed inside the embedded browser frame.

---

## Adding New Domains

To add a new domain mapping, visit the web interface at: `https://epi.etme-tech.me/web/new.html` or as an etme url `new.domain`

### Steps:
1. Open the URL in your browser.
2. Fill in the form fields:
   - **Domain**: Enter the domain name you want to map (e.g., `mynewdomain.com`). It must be a valid domain format.
   - **URL**: Enter the target URL where the domain should redirect (e.g., `https://example.com/page`). It must be a valid URL.
3. (Optional) If you have a device ID, it will be used for rate limiting; otherwise, your IP address is used.
4. Click submit to save the mapping.

**Note**: There is a rate limit of 1 new domain per hour per device to prevent abuse. If you exceed this, you'll see an error message.

---

## Backend

The backend API resolves domain inputs into HTML content:
- **Endpoint**: `curl -i "https://epi.etme-tech.me/web/get.php?domain=test.bruh" -H "X-ETME-Consent: 1"`
- **Validation Rules**:
    - Domain labels must include at least 4 characters unless prefixed with `etme:` or suffixed with `@etme`.
    - The maximum label length is 63 characters.

---

## Permissions

This extension requires certain permissions to function. Here's why each one is needed:

- **storage**: Used to store user consent for the privacy policy and temporary data for previews (e.g., cached resolved URLs and headers). This ensures the extension only activates with explicit user approval and improves performance by avoiding repeated API calls.
- **tabs**: Used to update the current tab to a loading page during resolution and then to the viewer page. This provides a seamless user experience without opening new windows.
- **webNavigation**: Used to detect when a Google search page is fully loaded, allowing the extension to check for ETME patterns in the query and intercept accordingly.
- **host_permissions** (`<all_urls>`): Used to fetch HTML content from any resolved ETME URL and display it securely in the embedded viewer. This broad permission is necessary because ETME domains can point to any website, but all content is sanitized to prevent security risks.

These permissions are only used for the extension's core functionality and respect user privacy through consent requirements.
