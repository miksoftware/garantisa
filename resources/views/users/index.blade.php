<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Usuarios - Gestión Garantisa</title>
    <link rel="stylesheet" href="/css/app.css">
</head>
<body>
<div class="container">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
        <h1 style="margin-bottom:0;">👥 Gestión de Usuarios</h1>
        <div style="display:flex;gap:10px;">
            <a href="/" class="btn" style="text-decoration:none;font-size:0.85rem;padding:8px 16px;">← Volver</a>
            <form method="POST" action="/logout">
                @csrf
                <button type="submit" class="btn" style="background:#dc2626;font-size:0.85rem;padding:8px 16px;">Cerrar Sesión</button>
            </form>
        </div>
    </div>

    @if(session('success'))
        <div style="background:#14532d;border:1px solid #4ade80;border-radius:8px;padding:12px;margin-bottom:15px;color:#86efac;font-size:0.9rem;">
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div style="background:#7f1d1d;border:1px solid #f87171;border-radius:8px;padding:12px;margin-bottom:15px;color:#fca5a5;font-size:0.9rem;">
            {{ session('error') }}
        </div>
    @endif

    {{-- Formulario crear usuario --}}
    <div class="card">
        <h2>➕ Crear Usuario</h2>
        <form method="POST" action="/usuarios">
            @csrf
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:10px;align-items:end;">
                <div>
                    <label style="display:block;color:#94a3b8;font-size:0.8rem;margin-bottom:4px;">Nombre</label>
                    <input type="text" name="name" required placeholder="Juan Pérez" value="{{ old('name') }}"
                        style="width:100%;padding:8px 10px;background:#0f172a;border:1px solid #475569;border-radius:6px;color:#e2e8f0;font-size:0.9rem;">
                </div>
                <div>
                    <label style="display:block;color:#94a3b8;font-size:0.8rem;margin-bottom:4px;">Correo</label>
                    <input type="email" name="email" required placeholder="juan@ejemplo.com" value="{{ old('email') }}"
                        style="width:100%;padding:8px 10px;background:#0f172a;border:1px solid #475569;border-radius:6px;color:#e2e8f0;font-size:0.9rem;">
                </div>
                <div>
                    <label style="display:block;color:#94a3b8;font-size:0.8rem;margin-bottom:4px;">Contraseña</label>
                    <input type="password" name="password" required placeholder="Mínimo 6 caracteres"
                        style="width:100%;padding:8px 10px;background:#0f172a;border:1px solid #475569;border-radius:6px;color:#e2e8f0;font-size:0.9rem;">
                </div>
                <button type="submit" class="btn" style="padding:8px 20px;font-size:0.9rem;">Crear</button>
            </div>
            @if($errors->any())
                <p style="color:#f87171;font-size:0.85rem;margin-top:8px;">{{ $errors->first() }}</p>
            @endif
        </form>
    </div>

    {{-- Lista de usuarios --}}
    <div class="card">
        <h2>📋 Usuarios Registrados ({{ $users->count() }})</h2>
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr style="border-bottom:1px solid #475569;">
                    <th style="text-align:left;padding:10px 8px;color:#94a3b8;font-size:0.8rem;">NOMBRE</th>
                    <th style="text-align:left;padding:10px 8px;color:#94a3b8;font-size:0.8rem;">CORREO</th>
                    <th style="text-align:left;padding:10px 8px;color:#94a3b8;font-size:0.8rem;">CREADO</th>
                    <th style="text-align:right;padding:10px 8px;color:#94a3b8;font-size:0.8rem;">ACCIÓN</th>
                </tr>
            </thead>
            <tbody>
                @foreach($users as $user)
                <tr style="border-bottom:1px solid #1e293b;">
                    <td style="padding:10px 8px;">{{ $user->name }}</td>
                    <td style="padding:10px 8px;color:#94a3b8;">{{ $user->email }}</td>
                    <td style="padding:10px 8px;color:#64748b;font-size:0.85rem;">{{ $user->created_at?->format('d/m/Y H:i') ?? '-' }}</td>
                    <td style="padding:10px 8px;text-align:right;">
                        @if($users->count() > 1)
                        <form method="POST" action="/usuarios/{{ $user->id }}" style="display:inline;" onsubmit="return confirm('¿Eliminar a {{ $user->name }}?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" style="background:#dc2626;color:white;border:none;padding:5px 12px;border-radius:5px;font-size:0.8rem;cursor:pointer;">Eliminar</button>
                        </form>
                        @else
                        <span style="color:#64748b;font-size:0.8rem;">Único usuario</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
