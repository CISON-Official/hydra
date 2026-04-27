/* viewer.js — PDF.js canvas renderer, no iframe/blob URL framing needed */
(function () {
    const body          = document.body;
    const certificateId = body.dataset.certificateId;
    const sessionToken  = body.dataset.sessionToken;
    const tokenExpiry   = Number(body.dataset.tokenExpiry);

    const viewer        = document.getElementById("pdfViewer");

    let pdfBytes = null;   // cached ArrayBuffer for download

    /* ── helpers ── */

    function setLoading(msg) {
        viewer.innerHTML = `
            <div class="loading">
                <div class="spinner"></div>
                <p>${msg || "Loading certificate..."}</p>
            </div>`;
    }

    function setError(msg, retryFn) {
        viewer.innerHTML = `
            <div class="loading">
                <p class="error">Failed to load certificate</p>
                <p>${msg}</p>
                <button class="btn" id="retryBtn">Retry</button>
            </div>`;
        document.getElementById("retryBtn").addEventListener("click", retryFn);
    }

    /* ── render all pages onto <canvas> elements via PDF.js ── */

    async function renderAllPages(arrayBuffer) {
        const pdfjsLib = window["pdfjs-dist/build/pdf"];
        pdfjsLib.GlobalWorkerOptions.workerSrc =
            "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js";

        const pdfDoc = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;

        const wrap = document.createElement("div");
        wrap.style.cssText =
            "overflow-y:auto; max-height:700px; padding:12px; background:#f3f4f6;";

        for (let i = 1; i <= pdfDoc.numPages; i++) {
            const page     = await pdfDoc.getPage(i);
            const viewport = page.getViewport({ scale: 1.5 });

            const canvas        = document.createElement("canvas");
            canvas.width        = viewport.width;
            canvas.height       = viewport.height;
            canvas.style.cssText =
                "display:block; margin:0 auto 12px; max-width:100%; " +
                "border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,0.15);";

            await page.render({
                canvasContext: canvas.getContext("2d"),
                viewport,
            }).promise;

            wrap.appendChild(canvas);
        }

        viewer.innerHTML = "";
        viewer.appendChild(wrap);
    }

    /* ── fetch PDF bytes and render ── */

    async function loadPDF() {
        setLoading("Loading certificate...");
        try {
            const res = await fetch(
                `/api/certificates/${certificateId}/stream?token=${sessionToken}`
            );
            if (!res.ok) throw new Error(`${res.status} ${res.statusText}`);

            const arrayBuffer = await res.arrayBuffer();
            pdfBytes = arrayBuffer.slice(0);   // keep a copy for later download

            await renderAllPages(arrayBuffer);
        } catch (err) {
            setError(err.message, loadPDF);
        }
    }

    /* ── download: blob URL is only used for <a> download, never framed ── */

    async function downloadPDF() {
        try {
            let bytes = pdfBytes;

            if (!bytes) {
                const res = await fetch(
                    `/api/certificates/${certificateId}/download?token=${sessionToken}&inline=false`
                );
                if (!res.ok) throw new Error(`${res.status}`);
                bytes = await res.arrayBuffer();
            }

            const blob = new Blob([bytes], { type: "application/pdf" });
            const url  = URL.createObjectURL(blob);
            const a    = document.createElement("a");
            a.href     = url;
            a.download = `certificate_${certificateId}.pdf`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(() => URL.revokeObjectURL(url), 5000);
        } catch {
            alert("Download failed. Please refresh and try again.");
        }
    }

    /* ── wire up buttons ── */

    document.getElementById("downloadBtn").addEventListener("click", downloadPDF);
    document.getElementById("refreshBtn").addEventListener("click", loadPDF);

    if (tokenExpiry > 300) {
        setTimeout(() => {
            alert("Session expiring soon. Refresh the page.");
        }, (tokenExpiry - 60) * 1000);
    }

    loadPDF();
})();