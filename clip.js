/**
 * Internet Clipboard - client side.
 * Handles file collection (drop / paste), sharing, copying, downloading and deleting.
 */
(function () {
    'use strict';

    const MAX_NAME_LENGTH = 200;

    const textBox  = document.getElementById('clip-text');
    const fileList = document.getElementById('file-list');
    const message  = document.getElementById('message');
    const list     = document.getElementById('clip-list');
    const buttons  = document.querySelectorAll('#share-private, #share-public');

    let pendingFiles = [];

    /* ---------- Messages ---------- */

    function showMessage(text) {
        message.textContent = text;
        message.hidden = false;
    }

    function hideMessage() {
        message.hidden = true;
    }

    /** Briefly shows a status on a button, then restores its label. */
    function flash(button, label) {
        const original = button.dataset.label || button.textContent;
        button.dataset.label = original;
        button.textContent = label;
        setTimeout(() => { button.textContent = original; }, 1500);
    }

    /* ---------- Collecting files ---------- */

    function addFiles(files) {
        for (const file of files) {
            if ([...file.name].length > MAX_NAME_LENGTH) {
                showMessage('"' + file.name.slice(0, 40) + '..." was not added: file names may have at most '
                    + MAX_NAME_LENGTH + ' characters. Rename the file and try again.');
                continue;
            }
            pendingFiles.push(file);
        }
        renderFileList();
    }

    function renderFileList() {
        fileList.replaceChildren();
        pendingFiles.forEach((file, index) => {
            const item = document.createElement('li');
            const name = document.createElement('span');
            const remove = document.createElement('button');
            name.textContent = file.name;
            remove.type = 'button';
            remove.textContent = 'Remove';
            remove.className = 'small';
            remove.setAttribute('aria-label', 'Remove ' + file.name);
            remove.addEventListener('click', () => {
                pendingFiles.splice(index, 1);
                renderFileList();
            });
            item.append(name, remove);
            fileList.append(item);
        });
    }

    textBox.addEventListener('dragover', (event) => {
        if ([...event.dataTransfer.types].includes('Files')) {
            event.preventDefault();
            textBox.classList.add('drag-over');
        }
    });
    textBox.addEventListener('dragleave', () => textBox.classList.remove('drag-over'));
    textBox.addEventListener('drop', (event) => {
        textBox.classList.remove('drag-over');
        if (event.dataTransfer.files.length) {
            event.preventDefault();
            addFiles(event.dataTransfer.files);
        }
    });

    textBox.addEventListener('paste', (event) => {
        const files = [...event.clipboardData.files];
        if (!files.length) {
            return;                                   // plain text: let the browser paste it
        }
        event.preventDefault();
        const stamp = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 12);
        addFiles(files.map((file) => {
            // Pasted screenshots are all called "image.png"; make the names distinguishable.
            if (file.name !== 'image.png') {
                return file;
            }
            return new File([file], 'image-' + stamp + '-' + Math.random().toString(36).slice(2, 6) + '.png', { type: file.type });
        }));
    });

    /* ---------- Sharing ---------- */

    async function share(isPublic) {
        hideMessage();
        if (!textBox.value.trim() && !pendingFiles.length) {
            showMessage('Enter some text or add a file first.');
            return;
        }

        const data = new FormData();
        data.append('action', 'save');
        data.append('public', isPublic ? '1' : '0');
        data.append('text', textBox.value);
        pendingFiles.forEach((file) => data.append('files[]', file, file.name));

        buttons.forEach((button) => { button.disabled = true; });
        try {
            const response = await fetch('clip.php', { method: 'POST', body: data });
            const result = await response.json();
            if (!result.ok) {
                throw new Error(result.error);
            }
            // Clear the input first, so browsers don't restore it after the reload.
            textBox.value = '';
            pendingFiles = [];
            renderFileList();
            location.reload();
        } catch (error) {
            showMessage(error.message || 'Saving failed. Please try again.');
            buttons.forEach((button) => { button.disabled = false; });
        }
    }

    document.getElementById('share-private').addEventListener('click', () => share(false));
    document.getElementById('share-public').addEventListener('click', () => share(true));

    /* ---------- Copying ---------- */

    async function writeText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return;
        }
        // Fallback for pages not served over HTTPS.
        const helper = document.createElement('textarea');
        helper.value = text;
        helper.style.position = 'fixed';
        helper.style.opacity = '0';
        document.body.append(helper);
        helper.select();
        const ok = document.execCommand('copy');
        helper.remove();
        if (!ok) {
            throw new Error('Copy not possible');
        }
    }

    /**
     * How can a file of this mime type be copied in this browser?
     * 'text'    - content is written as text
     * 'direct'  - written as-is (PNG always; SVG where ClipboardItem.supports() allows it)
     * 'convert' - other images are converted to PNG first
     * null      - not copyable; the button stays hidden
     */
    function copyMode(mime) {
        if (mime.startsWith('text/') || mime === 'application/json' || mime === 'application/xml') {
            return 'text';
        }
        if (!window.isSecureContext || !navigator.clipboard || !window.ClipboardItem) {
            return null;
        }
        if (mime === 'image/png') {
            return 'direct';
        }
        if (mime === 'image/svg+xml') {
            return ClipboardItem.supports && ClipboardItem.supports(mime) ? 'direct' : null;
        }
        return mime.startsWith('image/') ? 'convert' : null;
    }

    async function toPng(blob) {
        const url = URL.createObjectURL(blob);
        try {
            const image = new Image();
            image.src = url;
            await image.decode();
            const canvas = document.createElement('canvas');
            canvas.width = image.naturalWidth;
            canvas.height = image.naturalHeight;
            canvas.getContext('2d').drawImage(image, 0, 0);
            return await new Promise((resolve, reject) => {
                canvas.toBlob((png) => (png ? resolve(png) : reject(new Error('Conversion failed'))), 'image/png');
            });
        } finally {
            URL.revokeObjectURL(url);
        }
    }

    async function fetchFile(url) {
        const response = await fetch(url);
        if (!response.ok) {
            throw new Error('Download failed');
        }
        return response;
    }

    async function copyFile(button) {
        const { url, mime } = button.dataset;
        const mode = copyMode(mime);
        try {
            if (mode === 'text') {
                await writeText(await (await fetchFile(url)).text());
            } else {
                // A promise is passed so Safari keeps the click's permission while the file loads.
                const type = mode === 'convert' ? 'image/png' : mime;
                const blob = fetchFile(url).then((response) => response.blob())
                    .then((original) => (mode === 'convert' ? toPng(original) : original.slice(0, original.size, type)));
                await navigator.clipboard.write([new ClipboardItem({ [type]: blob })]);
            }
            flash(button, 'Copied');
        } catch (error) {
            flash(button, 'Failed');
        }
    }

    // Show the file copy button only where copying works.
    list.querySelectorAll('button[data-action="copy-file"]').forEach((button) => {
        button.hidden = copyMode(button.dataset.mime) === null;
    });

    /* ---------- Downloading and deleting ---------- */

    function downloadText(text, id) {
        const url = URL.createObjectURL(new Blob([text], { type: 'text/plain;charset=utf-8' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = 'clip' + id + '.txt';
        document.body.append(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }

    async function deleteItem(item) {
        const data = new FormData();
        data.append('action', 'delete');
        data.append('id', item.dataset.id);
        try {
            const response = await fetch('clip.php', { method: 'POST', body: data });
            const result = await response.json();
            if (!result.ok) {
                throw new Error(result.error);
            }
            item.remove();
        } catch (error) {
            showMessage(error.message || 'Deleting failed. Please try again.');
        }
    }

    list.addEventListener('click', async (event) => {
        const button = event.target.closest('button[data-action]');
        if (!button) {
            return;
        }
        const item = button.closest('.item');
        switch (button.dataset.action) {
            case 'copy-text':
                try {
                    await writeText(item.querySelector('textarea').value);
                    flash(button, 'Copied');
                } catch (error) {
                    flash(button, 'Failed');
                }
                break;
            case 'download-text':
                downloadText(item.querySelector('textarea').value, item.dataset.id);
                break;
            case 'copy-file':
                copyFile(button);
                break;
            case 'delete':
                deleteItem(item);
                break;
        }
    });
})();
