(function () {
    const dropzone = document.getElementById('dropzone');
    const fileInput = document.getElementById('fileInput');
    const selectedFiles = document.getElementById('selectedFiles');

    document.querySelectorAll('.copy-url').forEach((button) => {
        button.addEventListener('click', async () => {
            const url = button.getAttribute('data-url') || '';
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(url);
                } else {
                    copyWithFallback(url);
                }

                const originalText = button.textContent;
                button.textContent = 'Zkopirovano';
                button.disabled = true;
                window.setTimeout(() => {
                    button.textContent = originalText;
                    button.disabled = false;
                }, 1400);
            } catch (error) {
                window.prompt('Zkopiruj URL:', url);
            }
        });
    });

    if (!dropzone || !fileInput || !selectedFiles) {
        return;
    }

    function renderFiles() {
        selectedFiles.innerHTML = '';
        Array.from(fileInput.files).forEach((file, index) => {
            const row = document.createElement('div');
            row.className = 'selected-file';

            const info = document.createElement('div');
            info.innerHTML = '<strong></strong><small></small>';
            info.querySelector('strong').textContent = file.name;
            info.querySelector('small').textContent = formatBytes(file.size);

            const label = document.createElement('label');
            label.textContent = 'Alt text';

            const input = document.createElement('input');
            input.name = 'alt_texts[]';
            input.maxLength = 500;
            input.placeholder = 'Alt pro ' + file.name;
            input.autocomplete = 'off';
            input.dataset.index = String(index);

            label.appendChild(input);
            row.append(info, label);
            selectedFiles.appendChild(row);
        });
    }

    function formatBytes(bytes) {
        const units = ['B', 'KB', 'MB', 'GB'];
        let size = Number(bytes);
        for (const unit of units) {
            if (size < 1024 || unit === 'GB') {
                return (Math.round(size * 10) / 10) + ' ' + unit;
            }
            size /= 1024;
        }

        return bytes + ' B';
    }

    dropzone.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', renderFiles);

    ['dragenter', 'dragover'].forEach((eventName) => {
        dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropzone.classList.add('is-dragover');
        });
    });

    ['dragleave', 'drop'].forEach((eventName) => {
        dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropzone.classList.remove('is-dragover');
        });
    });

    dropzone.addEventListener('drop', (event) => {
        if (event.dataTransfer && event.dataTransfer.files.length > 0) {
            fileInput.files = event.dataTransfer.files;
            renderFiles();
        }
    });

    function copyWithFallback(value) {
        const input = document.createElement('textarea');
        input.value = value;
        input.setAttribute('readonly', 'readonly');
        input.style.position = 'fixed';
        input.style.left = '-9999px';
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        document.body.removeChild(input);
    }
})();
