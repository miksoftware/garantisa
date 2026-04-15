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
        // Refrescar tabla de batches
        loadBatches();
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

// ==================== BATCHES HISTORY ====================

async function loadBatches() {
    const container = document.getElementById('batchesTable');
    try {
        const res = await fetch('/batches');
        const batches = await res.json();

        if (batches.length === 0) {
            container.innerHTML = '<p style="color:#64748b;">No hay ejecuciones aún. Sube un archivo Excel para comenzar.</p>';
            return;
        }

        let html = `<table class="batch-table">
            <thead><tr>
                <th>Fecha</th>
                <th>Total</th>
                <th>Exitosos</th>
                <th>Fallidos</th>
                <th>Progreso</th>
                <th>Estado</th>
                <th>Acciones</th>
            </tr></thead><tbody>`;

        batches.forEach(b => {
            const successPct = b.total > 0 ? (b.success / b.total * 100) : 0;
            const failedPct = b.total > 0 ? (b.failed / b.total * 100) : 0;
            const pendingPct = b.total > 0 ? ((b.pending + b.processing) / b.total * 100) : 0;

            let badge = '';
            if (b.pending > 0 || b.processing > 0) {
                badge = '<span class="badge badge-running">En proceso</span>';
            } else if (b.failed > 0) {
                badge = '<span class="badge badge-partial">Parcial</span>';
            } else {
                badge = '<span class="badge badge-complete">Completado</span>';
            }

            const date = new Date(b.started_at).toLocaleDateString('es-CO', { dateStyle: 'short' });

            html += `<tr>
                <td>${date}</td>
                <td style="font-weight:600;">${b.total}</td>
                <td style="color:#4ade80;font-weight:600;">${b.success}</td>
                <td style="color:#f87171;font-weight:600;">${b.failed}</td>
                <td>
                    <div class="mini-bar">
                        <div class="bar-success" style="width:${successPct}%"></div>
                        <div class="bar-failed" style="width:${failedPct}%"></div>
                        <div class="bar-pending" style="width:${pendingPct}%"></div>
                    </div>
                </td>
                <td>${badge}</td>
                <td>
                    <button class="btn-sm btn-view" onclick="viewBatch('${b.batch_id}', ${b.total}, ${b.success}, ${b.failed}, ${b.pending + b.processing})">Ver</button>
                    ${b.failed > 0 ? `<button class="btn-sm btn-retry" onclick="retryBatch('${b.batch_id}')">🔄 Reintentar (${b.failed})</button>` : ''}
                    ${(b.pending + b.processing) > 0 ? `<button class="btn-sm btn-continue" onclick="continueBatch('${b.batch_id}')">▶ Continuar (${b.pending + b.processing})</button>` : ''}
                </td>
            </tr>`;
        });

        html += '</tbody></table>';
        container.innerHTML = html;
    } catch (e) {
        container.innerHTML = '<p style="color:#f87171;">Error cargando historial</p>';
    }
}

// ==================== MODAL DETALLE ====================

function createDonutSVG(total, success, failed, pending) {
    const radius = 60;
    const circumference = 2 * Math.PI * radius;

    const successPct = total > 0 ? success / total : 0;
    const failedPct = total > 0 ? failed / total : 0;
    const pendingPct = total > 0 ? pending / total : 0;

    const successLen = successPct * circumference;
    const failedLen = failedPct * circumference;
    const pendingLen = pendingPct * circumference;

    const successOffset = 0;
    const failedOffset = successLen;
    const pendingOffset = successLen + failedLen;

    return `
    <div class="donut-chart">
        <svg viewBox="0 0 150 150">
            <circle cx="75" cy="75" r="${radius}" fill="none" stroke="#334155" stroke-width="18"/>
            <circle cx="75" cy="75" r="${radius}" fill="none" stroke="#4ade80" stroke-width="18"
                stroke-dasharray="${successLen} ${circumference - successLen}"
                stroke-dashoffset="-${successOffset}"/>
            <circle cx="75" cy="75" r="${radius}" fill="none" stroke="#f87171" stroke-width="18"
                stroke-dasharray="${failedLen} ${circumference - failedLen}"
                stroke-dashoffset="-${failedOffset}"/>
            <circle cx="75" cy="75" r="${radius}" fill="none" stroke="#fbbf24" stroke-width="18"
                stroke-dasharray="${pendingLen} ${circumference - pendingLen}"
                stroke-dashoffset="-${pendingOffset}"/>
        </svg>
        <div class="donut-center">
            <div class="donut-number">${total}</div>
            <div class="donut-label">Total</div>
        </div>
    </div>
    <div class="donut-legend">
        <div class="donut-legend-item">
            <div class="donut-legend-color" style="background:#4ade80;"></div>
            <span>Exitosos: <strong style="color:#4ade80;">${success}</strong> (${total > 0 ? Math.round(success/total*100) : 0}%)</span>
        </div>
        <div class="donut-legend-item">
            <div class="donut-legend-color" style="background:#f87171;"></div>
            <span>Fallidos: <strong style="color:#f87171;">${failed}</strong> (${total > 0 ? Math.round(failed/total*100) : 0}%)</span>
        </div>
        <div class="donut-legend-item">
            <div class="donut-legend-color" style="background:#fbbf24;"></div>
            <span>Pendientes: <strong style="color:#fbbf24;">${pending}</strong> (${total > 0 ? Math.round(pending/total*100) : 0}%)</span>
        </div>
    </div>`;
}

async function viewBatch(batchId, total, success, failed, pending) {
    const modal = document.getElementById('batchModal');
    const modalStats = document.getElementById('modalStats');
    const modalDonut = document.getElementById('modalDonut');
    const modalActions = document.getElementById('modalActions');
    const modalDetail = document.getElementById('modalDetail');

    modal.classList.add('active');

    // Stats cards
    modalStats.innerHTML = `
        <div class="stat total"><div class="number">${total}</div><div class="label">Total</div></div>
        <div class="stat success"><div class="number">${success}</div><div class="label">Exitosos</div></div>
        <div class="stat failed"><div class="number">${failed}</div><div class="label">Fallidos</div></div>
        <div class="stat pending"><div class="number">${pending}</div><div class="label">Pendientes</div></div>
    `;

    // Donut
    modalDonut.innerHTML = createDonutSVG(total, success, failed, pending);

    // Retry button
    if (failed > 0) {
        modalActions.innerHTML = `<button class="btn btn-retry" style="font-size:0.9rem;" onclick="retryBatch('${batchId}')">🔄 Reintentar ${failed} Fallidos</button>`;
    } else if (pending > 0) {
        modalActions.innerHTML = `<button class="btn btn-continue" style="font-size:0.9rem;" onclick="continueBatch('${batchId}')">▶ Continuar ${pending} Pendientes</button>`;
    } else {
        modalActions.innerHTML = '<p style="color:#4ade80;font-size:0.9rem;">✓ Todos los registros procesados correctamente</p>';
    }

    // Show both buttons if both failed and pending exist
    if (failed > 0 && pending > 0) {
        modalActions.innerHTML = `
            <button class="btn btn-retry" style="font-size:0.9rem;margin-right:0.5rem;" onclick="retryBatch('${batchId}')">🔄 Reintentar ${failed} Fallidos</button>
            <button class="btn btn-continue" style="font-size:0.9rem;" onclick="continueBatch('${batchId}')">▶ Continuar ${pending} Pendientes</button>
        `;
    }

    // Load detail table
    modalDetail.innerHTML = '<p style="color:#94a3b8;">Cargando detalle...</p>';
    try {
        const res = await fetch(`/batches/${batchId}`);
        const logs = await res.json();

        if (logs.length === 0) {
            modalDetail.innerHTML = '<p style="color:#64748b;">Sin registros</p>';
            return;
        }

        let html = `<table class="detail-table">
            <thead><tr><th>Cédula</th><th>Acción</th><th>Estado</th><th>Error</th></tr></thead><tbody>`;

        logs.forEach(l => {
            const statusClass = `status-${l.status}`;
            const statusLabel = { success: '✓ Exitoso', failed: '✗ Fallido', pending: '⏳ Pendiente', processing: '⚙ Procesando' }[l.status] || l.status;
            html += `<tr>
                <td>${l.cedula}</td>
                <td>${l.accion}</td>
                <td class="${statusClass}">${statusLabel}</td>
                <td style="color:#94a3b8;font-size:0.75rem;">${l.error_message || '-'}</td>
            </tr>`;
        });

        html += '</tbody></table>';
        modalDetail.innerHTML = html;
    } catch (e) {
        modalDetail.innerHTML = '<p style="color:#f87171;">Error cargando detalle</p>';
    }
}

function closeModal() {
    document.getElementById('batchModal').classList.remove('active');
}

// Cerrar modal con Escape o click fuera
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
document.addEventListener('click', e => {
    const modal = document.getElementById('batchModal');
    if (e.target === modal) closeModal();
});

// ==================== REINTENTAR FALLIDOS ====================

async function continueBatch(batchId) {
    if (!confirm('¿Continuar procesando los registros pendientes de este batch?')) return;

    try {
        const res = await fetch(`/batches/${batchId}/continue`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Content-Type': 'application/json',
            },
        });
        const data = await res.json();

        if (!res.ok) {
            alert(data.error || 'Error al continuar');
            return;
        }

        // Cerrar modal si está abierto
        closeModal();

        // Mostrar progreso
        stats = { total: data.total, pending: data.total, success: 0, failed: 0 };
        updateStats();
        progressCard.classList.remove('hidden');
        logCard.classList.remove('hidden');
        logContainer.innerHTML = '';
        progressFill.style.width = '0%';

        addLog('info', `▶ ${data.message}`);

        // Iniciar procesamiento SSE
        startProcessing(batchId);
    } catch (e) {
        alert('Error de conexión: ' + e.message);
    }
}

async function retryBatch(batchId) {
    if (!confirm('¿Reintentar todos los registros fallidos de este batch?')) return;

    try {
        const res = await fetch(`/batches/${batchId}/retry`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Content-Type': 'application/json',
            },
        });
        const data = await res.json();

        if (!res.ok) {
            alert(data.error || 'Error al reintentar');
            return;
        }

        // Cerrar modal si está abierto
        closeModal();

        // Mostrar progreso
        stats = { total: data.total, pending: data.total, success: 0, failed: 0 };
        updateStats();
        progressCard.classList.remove('hidden');
        logCard.classList.remove('hidden');
        logContainer.innerHTML = '';
        progressFill.style.width = '0%';

        addLog('info', `🔄 ${data.message}`);

        // Iniciar procesamiento SSE
        startProcessing(batchId);
    } catch (e) {
        alert('Error de conexión: ' + e.message);
    }
}

// Cargar batches al iniciar
loadBatches();
