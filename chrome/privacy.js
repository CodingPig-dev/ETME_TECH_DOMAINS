document.getElementById('activate').addEventListener('click', () => {
    const checked = document.getElementById('consent').checked;
    if (checked) {
        chrome.storage.local.set({ consent: true }, () => {
            // notify background to update in-memory consent immediately
            try { chrome.runtime.sendMessage({ type: 'consent_set', value: true }); } catch (e) {}
            alert('Extension activated!');
            window.close();
        });
    } else {
        alert('Please agree to the privacy policy first.');
    }
});

// Check if already consented
chrome.storage.local.get('consent', (result) => {
    if (result.consent) {
        document.getElementById('activate').textContent = 'Extension Active';
        document.getElementById('activate').disabled = true;
        document.getElementById('consent').checked = true;
        const consentSection = document.querySelector('.consent-section');
        if (consentSection) {
            consentSection.style.display = 'none';
            const msg = document.createElement('p');
            msg.textContent = 'The extension is already active.';
            msg.style.color = '#4CAF50';
            msg.style.fontSize = '16px';
            const linksSection = document.querySelector('.links-section');
            if (linksSection) {
                linksSection.parentNode.insertBefore(msg, linksSection);
            } else {
                document.querySelector('.container').appendChild(msg);
            }
        }
    }
});
