document.addEventListener('DOMContentLoaded', function() {
    const copyButton = document.getElementById('wpscd-copy-service-email');
    const emailSpan = document.getElementById('wpscd-service-email');
    const feedbackSpan = document.getElementById('wpscd-copy-feedback');

    if (copyButton && emailSpan && feedbackSpan) {
        copyButton.addEventListener('click', function(e) {
            e.preventDefault();
            const email = emailSpan.innerText;
            navigator.clipboard.writeText(email).then(function() {
                // Success!
                feedbackSpan.textContent = 'Copied!';
                setTimeout(() => { feedbackSpan.textContent = ''; }, 2000); // Clear feedback after 2s
            }, function(err) {
                // Error
                console.error('Could not copy text: ', err);
                feedbackSpan.textContent = 'Copy failed';
                setTimeout(() => { feedbackSpan.textContent = ''; }, 2000);
            });
        });
    }
}); 