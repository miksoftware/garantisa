<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Automatización Gestión Garantisa</title>
    <link rel="stylesheet" href="/css/app.css">
</head>
<body>
<div class="container">
    <h1>⚡ Automatización de Gestión - Garantisa</h1>

    <div class="card" id="uploadCard">
        <h2>📁 Subir archivo Excel</h2>
        <div class="upload-zone" id="dropZone">
            <p>Arrastra tu archivo Excel aquí o haz clic para seleccionar</p>
            <p class="file-name" id="fileName"></p>
            <input type="file" id="fileInput" accept=".xlsx,.xls,.csv">
        </div>
        <p style="margin-top:10px;color:#94a3b8;font-size:0.85rem;">
            Columnas requeridas: CÉDULA, ACCIÓN, RESULTADO, COMENTARIO
        </p>
        <div class="actions">
            <button class="btn" id="btnUpload" disabled>Subir y Procesar</button>
        </div>
    </div>

    <div class="card hidden" id="progressCard">
        <h2>📊 Progreso</h2>
        <div class="stats">
            <div class="stat total"><div class="number" id="statTotal">0</div><div class="label">Total</div></div>
            <div class="stat pending"><div class="number" id="statPending">0</div><div class="label">Pendientes</div></div>
            <div class="stat success"><div class="number" id="statSuccess">0</div><div class="label">Exitosos</div></div>
            <div class="stat failed"><div class="number" id="statFailed">0</div><div class="label">Fallidos</div></div>
        </div>
        <div class="progress-bar"><div class="progress-fill" id="progressFill" style="width:0%"></div></div>
        <p id="statusText" style="text-align:center;color:#94a3b8;margin-bottom:15px;"></p>
    </div>

    <div class="card hidden" id="logCard">
        <h2>📋 Log en tiempo real</h2>
        <div class="log-container" id="logContainer"></div>
    </div>

    <div class="card" id="logsHistoryCard">
        <h2>🗂️ Historial de Logs de Debug</h2>
        <p style="color:#94a3b8;font-size:0.85rem;margin-bottom:10px;">
            Cada ejecución genera un archivo .log detallado con peticiones, respuestas, tokens y errores.
            Compártelo para debuggear problemas.
        </p>
        <div id="logsHistoryList" style="font-family:Consolas,monospace;font-size:0.85rem;">Cargando...</div>
        <div class="actions" style="margin-top:10px;">
            <button class="btn" onclick="loadLogs()" style="font-size:0.85rem;padding:8px 16px;">Refrescar</button>
        </div>
    </div>
</div>
<script src="/js/app.js"></script>
</body>
</html>
