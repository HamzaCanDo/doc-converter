(() => {
    const fileInput = document.getElementById('file');
    const urlInput = document.getElementById('url');
    const formatSelect = document.getElementById('upload_format');
    const convertForm = document.getElementById('convert-form');
    const processBtn = document.getElementById('process-btn');
    const processBtnText = document.getElementById('process-btn-text');
    const loadingWrap = document.getElementById('loading-wrap');
    const loadingBar = document.getElementById('loading-bar');
    const loadingPercent = document.getElementById('loading-percent');
    const loadingMessage = document.getElementById('loading-message');
    const ajaxError = document.getElementById('ajax-error');
    const uploadDownloadWrap = document.getElementById('upload-download-wrap');
    const uploadDownloadBtn = document.getElementById('upload-download-btn');
    const uploadDownloadMessage = document.getElementById('upload-download-message');
    const uploadFileName = document.getElementById('upload-file-name');
    const allowedFormats = {
        docx: ['pdf', 'xlsx', 'json', 'md'],
        pdf: ['xlsx', 'json', 'md'],
        xlsx: ['pdf', 'json', 'md'],
    };
    const maxUploadBytes = 40 * 1024 * 1024;
    const uploadProgressCap = 85;
    const uploadProgressThrottleMs = 80;
    let processingTicker = null;
    let lastProgressPercent = -1;
    let lastProgressMessage = '';
    let lastProgressUpdate = 0;

    const clearProcessingTicker = () => {
        if (processingTicker) {
            clearInterval(processingTicker);
            processingTicker = null;
        }
    };

    const setLoadingProgress = (percent, message) => {
        const safePercent = Math.max(0, Math.min(100, Math.round(percent)));
        const nextMessage = message || '';
        const hasPercentChanged = safePercent !== lastProgressPercent;
        const hasMessageChanged = nextMessage !== lastProgressMessage;

        if (!hasPercentChanged && !hasMessageChanged) {
            return;
        }

        if (hasPercentChanged && loadingBar) {
            loadingBar.style.width = `${safePercent}%`;
        }
        if (hasPercentChanged && loadingPercent) {
            loadingPercent.textContent = `${safePercent}%`;
        }
        if (hasMessageChanged && loadingMessage) {
            loadingMessage.textContent = nextMessage;
        }

        if (hasPercentChanged) {
            lastProgressPercent = safePercent;
            lastProgressUpdate = Date.now();
        }

        if (hasMessageChanged) {
            lastProgressMessage = nextMessage;
        }
    };

    const resetLoadingProgress = () => {
        lastProgressPercent = -1;
        lastProgressMessage = '';
        lastProgressUpdate = 0;
        setLoadingProgress(0, 'Processing conversion, please wait...');
    };

    const setBusyState = (isBusy) => {
        if (!processBtn || !processBtnText || !loadingWrap) {
            return;
        }

        processBtn.disabled = isBusy;
        processBtn.style.opacity = isBusy ? '0.75' : '';
        processBtn.style.cursor = isBusy ? 'not-allowed' : '';
        processBtnText.textContent = isBusy ? 'Processing...' : 'Process';

        if (isBusy) {
            loadingWrap.classList.add('active');
            loadingWrap.setAttribute('aria-busy', 'true');
        } else {
            loadingWrap.classList.remove('active');
            loadingWrap.setAttribute('aria-busy', 'false');
            resetLoadingProgress();
        }
    };

    const showAjaxError = (message) => {
        if (!ajaxError) {
            return;
        }
        ajaxError.textContent = message;
        ajaxError.style.display = 'block';
    };

    const hideAjaxError = () => {
        if (!ajaxError) {
            return;
        }
        ajaxError.textContent = '';
        ajaxError.style.display = 'none';
    };

    const hideUploadDownload = () => {
        if (!uploadDownloadWrap || !uploadDownloadBtn) {
            return;
        }

        uploadDownloadWrap.classList.remove('active');
        uploadDownloadBtn.classList.remove('active');
        uploadDownloadBtn.href = '#';
        uploadDownloadBtn.textContent = 'Download File';

        if (processBtn) {
            processBtn.style.display = 'inline-flex';
        }

        if (uploadDownloadMessage) {
            uploadDownloadMessage.textContent = 'Processing complete. Your file is ready.';
        }

        if (uploadFileName) {
            uploadFileName.textContent = '';
        }
    };

    const showUploadDownload = (url, format, fileName) => {
        if (!uploadDownloadWrap || !uploadDownloadBtn || !processBtn) {
            return;
        }

        const upperFormat = (format || 'file').toString().toUpperCase();
        uploadDownloadBtn.href = url;
        uploadDownloadBtn.textContent = `Download ${upperFormat}`;
        uploadDownloadBtn.classList.add('active');
        processBtn.style.display = 'none';
        uploadDownloadWrap.classList.add('active');

        if (uploadDownloadMessage) {
            uploadDownloadMessage.textContent = `Processing complete. Your ${upperFormat} file is ready.`;
        }

        if (uploadFileName) {
            uploadFileName.textContent = fileName ? `File: ${fileName}` : '';
        }
    };

    const updateFormatOptions = () => {
        const name = fileInput?.files?.[0]?.name || '';
        const ext = name.split('.').pop().toLowerCase();
        const allowed = allowedFormats[ext] || null;

        for (const option of formatSelect.options) {
            if (option.value === '') {
                option.disabled = false;
                continue;
            }

            option.disabled = !allowed || !allowed.includes(option.value);
        }

        if (allowed && !allowed.includes(formatSelect.value)) {
            formatSelect.value = '';
        }
    };

    const resetCompletedUploadState = () => {
        hideAjaxError();
        hideUploadDownload();
    };

    if (fileInput && formatSelect) {
        fileInput.addEventListener('change', () => {
            const selectedFile = fileInput.files?.[0] || null;
            if (selectedFile && selectedFile.size > maxUploadBytes) {
                fileInput.value = '';
                updateFormatOptions();
                resetCompletedUploadState();
                showAjaxError('File is larger than 40 MB. Please choose a smaller file.');
                return;
            }

            updateFormatOptions();
            resetCompletedUploadState();
        });

        formatSelect.addEventListener('change', resetCompletedUploadState);
        updateFormatOptions();
    }

    if (urlInput) {
        urlInput.addEventListener('input', resetCompletedUploadState);
    }

    if (convertForm && processBtn && processBtnText && loadingWrap) {
        convertForm.addEventListener('submit', (event) => {
            const hasFile = fileInput?.files?.length > 0;
            const selectedFile = fileInput?.files?.[0] || null;
            const hasUrl = Boolean(urlInput?.value?.trim());

            if (!hasFile && !hasUrl) {
                event.preventDefault();
                showAjaxError('Paste a document URL or upload a file first.');
                return;
            }

            if (!hasFile) {
                hideAjaxError();
                hideUploadDownload();
                setBusyState(true);
                setLoadingProgress(10, 'Processing document link...');
                return;
            }

            if (!formatSelect?.value) {
                event.preventDefault();
                showAjaxError('Please choose an output format.');
                return;
            }

            if (selectedFile && selectedFile.size > maxUploadBytes) {
                event.preventDefault();
                showAjaxError('File is larger than 40 MB. Please choose a smaller file.');
                return;
            }

            event.preventDefault();
            hideAjaxError();
            hideUploadDownload();
            clearProcessingTicker();
            setBusyState(true);
            setLoadingProgress(0, 'Uploading file...');
            const selectedFileName = selectedFile?.name || '';

            const xhr = new XMLHttpRequest();
            xhr.open('POST', convertForm.action, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.setRequestHeader('Accept', 'application/json');

            xhr.upload.onprogress = (progressEvent) => {
                if (progressEvent.lengthComputable) {
                    const percent = (progressEvent.loaded / progressEvent.total) * uploadProgressCap;
                    const roundedPercent = Math.round(percent);
                    const now = Date.now();
                    const enoughTimePassed = now - lastProgressUpdate >= uploadProgressThrottleMs;
                    const movedForward = roundedPercent > lastProgressPercent;

                    if (enoughTimePassed || movedForward || roundedPercent >= uploadProgressCap) {
                        setLoadingProgress(percent, 'Uploading file...');
                    }
                }
            };

            xhr.upload.onload = () => {
                setLoadingProgress(uploadProgressCap + 3, 'Converting file...');
                clearProcessingTicker();
                processingTicker = setInterval(() => {
                    const current = lastProgressPercent < 0 ? (uploadProgressCap + 3) : lastProgressPercent;
                    if (current < 98) {
                        setLoadingProgress(current + 1, 'Converting file...');
                    }
                }, 400);
            };

            xhr.onerror = () => {
                clearProcessingTicker();
                setBusyState(false);
                showAjaxError('Upload failed. Please check your connection and try again.');
            };

            xhr.onload = () => {
                clearProcessingTicker();

                let payload = null;
                try {
                    payload = JSON.parse(xhr.responseText || '{}');
                } catch (error) {
                    payload = null;
                }

                if (xhr.status >= 200 && xhr.status < 300 && payload?.download_url) {
                    setLoadingProgress(100, 'Processing complete. Download button is ready.');
                    setBusyState(false);
                    showUploadDownload(payload.download_url, payload.format, payload?.file_name || selectedFileName);

                    return;
                }

                setBusyState(false);
                const message = payload?.errors
                    ? Object.values(payload.errors).flat()[0]
                    : (payload?.message || 'Conversion failed. Please try again.');
                showAjaxError(message);
            };

            const formData = new FormData(convertForm);
            xhr.send(formData);
        });
    }
})();
