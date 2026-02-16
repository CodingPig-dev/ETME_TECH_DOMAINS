document.getElementById('activate').addEventListener('click', () => {
    const checked = document.getElementById('consent').checked;
    if (checked) {
        chrome.storage.local.set({ consent: true }, () => {
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
    }
});
