for (const field of document.querySelectorAll('[data-photo-field]')) {
    const input = field.querySelector('input[type="file"]');
    const preview = field.querySelector('[data-photo-preview]');
    const selection = field.querySelector('[data-photo-selection]');
    const clear = field.querySelector('[data-photo-clear]');
    const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    let previewUrl;

    function updatePreview() {
        preview.hidden = true;
        preview.removeAttribute('src');
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        previewUrl = undefined;

        const file = input.files[0];
        clear.hidden = !file;
        selection.textContent = file ? file.name : '';
        if (!file) return;

        if (file.size === 0 || file.size > 5 * 1024 * 1024) {
            selection.textContent += ' · Choose a non-empty photo up to 5 MiB.';
            return;
        }
        if (!allowedTypes.includes(file.type)) {
            selection.textContent += ' · Preview is available for JPEG, PNG, and WebP.';
            return;
        }
        previewUrl = URL.createObjectURL(file);
        preview.src = previewUrl;
    }

    preview.addEventListener('load', () => { preview.hidden = false; });
    preview.addEventListener('error', () => { preview.hidden = true; });
    input.addEventListener('change', updatePreview);
    clear.addEventListener('click', () => {
        input.value = '';
        updatePreview();
        input.focus();
    });
}
