const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
const fileName = document.getElementById('fileName');
const btnUpload = document.getElementById('btnUpload');
const uploadCard = document.getElementById('uploadCard');
const progressCard = document.getElementById('progressCard');
const logCard = document.getElementById('logCard');
const logContainer = document.getElementById('logContainer');
const progressFill = document.getElementById('progressFill');
const statusText = document.getElementById('statusText');

let selectedFile = null;
let stats = { total: 0, pending: 0, success: 0, failed: 0 };

// Drag & drop
dropZone.addEventListener('click', () => fileInput.click());
dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.style.borderColor = '#22d3ee'; });
dropZone.addEventListener('dragleave', () => { dropZone.style.borderColor = '#475569'; });
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.style.borderColor = '#475569';
    if (e.dataTransfer.files.length) handleFile(e.dataTransfer.files[0]);
});
fileInput.addEventListener('change', e => { if (e.target.files.length) handleFile(e.target.files[0]); });

function handleFile(file) {
    selectedFile = file;
    fileName.textContent = file.name;
    btnUpload.disabled = false;
}

// Upload
btnUpload.addEventListener('click', async () => {
    if (!selectedFile) return;
    btnUpload.disabled = true;
    btnUpload.textContent = 'Subiendo...';

    const formData = new FormData();
    formData.append('file', selectedFile);

    try {
        const res = await fetch('/upload', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: formData,
        });
        const data = await res.json();

        if (!res.ok) {
            alert(data.error || 'Error al subir archivo');
            btnUpload.disabled = false;
            btnUpload.textContent = 'Subir y Procesar';
            return;
        }

        addLog('info', `${data.message}`);
        stats.total = data.total;
        stats.pending = data.total;
        updateStats();

        progressCard.classList.remove('hidden');
        logCard.classList.remove('hidden');

        // Iniciar procesamiento SSE
        startProcessing(data.batch_id);
    } catch (err) {
        alert('Error de conexión: ' + err.message);
        btnUpload.disabled = false;
        btnUpload.textContent = 'Subir y Procesar';
    }
});

function startProcessing(batchId) {
    statusText.textContent = 'Conectando...';
    const evtSource = new EventSource(`/process/${batchId}`);

    evtSource.addEventListener('status', e => {
        const d = JSON.parse(e.data);
        addLog('info', d.message);
        statusText.textContent = d.message;
    });

    evtSource.addEventListener('processing', e => {
        const d = JSON.parse(e.data);
        addLog('processing', d.message);
        statusText.textContent = d.message;
        updateProgress(d.current, d.total);
    });

    evtSource.addEventListener('success', e => {
        const d = JSON.parse(e.data);
        stats.success++;
        stats.pending = Math.max(0, stats.pending - 1);
        updateStats();
        addLog('success', d.message);
        statusText.textContent = d.message;
        updateProgress(d.current, d.total);
    });

    evtSource.addEventListener('failed', e => {
        const d = JSON.parse(e.data);
        stats.failed++;
        stats.pending = Math.max(0, stats.pending - 1);
        updateStats();
        addLog('failed', d.message);
        statusText.textContent = d.message;
        updateProgress(d.current, d.total);
    });

    evtSource.addEventListener('error_msg', e => {
        const d = JSON.parse(e.data);
        addLog('failed', d.message);
        statusText.textContent = d.message;
        evtSource.close();
        btnUpload.disabled = false;
        btnUpload.textContent = 'Subir y Procesar';
    });

    evtSource.addEventListener('complete', e => {
        const d = JSON.parse(e.data);
        addLog('info', d.message);
        statusText.textContent = d.message;
        evtSource.close();
        btnUpload.disabled = false;
        btnUpload.textContent = 'Subir y Procesar';
    });

    evtSource.onerror = () => {
        addLog('failed', 'Conexión perdida con el servidor');
        evtSource.close();
        btnUpload.disabled = false;
        btnUpload.textContent = 'Subir y Procesar';
    };
}

function updateStats() {
    document.getElementById('statTotal').textContent = stats.total;
    document.getElementById('statPending').textContent = stats.pending;
    document.getElementById('statSuccess').textContent = stats.success;
    document.getElementById('statFailed').textContent = stats.failed;
}

function updateProgress(current, total) {
    const pct = Math.round((current / total) * 100);
    progressFill.style.width = pct + '%';
    progressFill.textContent = pct + '%';
}

function addLog(type, message) {
    const time = new Date().toLocaleTimeString();
    const div = document.createElement('div');
    div.className = `log-entry ${type}`;
    div.textContent = `[${time}] ${message}`;
    logContainer.appendChild(div);
    logContainer.scrollTop = logContainer.scrollHeight;
}

// Logs history
async function loadLogs() {
    const container = document.getElementById('logsHistoryList');
    try {
        const res = await fetch('/logs');
        const logs = await res.json();

        if (logs.length === 0) {
            container.innerHTML = '<p style="color:#64748b;">No hay logs aún. Ejecuta un proceso para generar el primer log.</p>';
            return;
        }

        container.innerHTML = logs.map(log =>
            `<div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid #334155;">
                <span style="color:#e2e8f0;">${log.name}</span>
                <span style="color:#64748b;font-size:0.8rem;">${log.size} | ${log.date}</span>
                <a href="/logs/${log.name}" target="_blank" style="color:#38bdf8;text-decoration:none;font-size:0.85rem;">Ver</a>
            </div>`
        ).join('');
    } catch (e) {
        container.innerHTML = '<p style="color:#f87171;">Error cargando logs</p>';
    }
}

// Cargar logs al iniciar
loadLogs();
