<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Gestión Garantisa</title>
    <link rel="stylesheet" href="/css/app.css">
</head>
<body>
<div class="container" style="max-width:450px;margin-top:80px;">
    <h1 style="font-size:1.3rem;">🔐 Iniciar Sesión</h1>

    @if($errors->any())
        <div style="background:#7f1d1d;border:1px solid #f87171;border-radius:8px;padding:12px;margin-bottom:15px;color:#fca5a5;font-size:0.9rem;">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="card">
        <form method="POST" action="/login">
            @csrf
            <div style="margin-bottom:15px;">
                <label for="email" style="display:block;color:#94a3b8;font-size:0.85rem;margin-bottom:5px;">Correo electrónico</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus
                    style="width:100%;padding:10px 12px;background:#0f172a;border:1px solid #475569;border-radius:8px;color:#e2e8f0;font-size:0.95rem;">
            </div>
            <div style="margin-bottom:15px;">
                <label for="password" style="display:block;color:#94a3b8;font-size:0.85rem;margin-bottom:5px;">Contraseña</label>
                <input type="password" id="password" name="password" required
                    style="width:100%;padding:10px 12px;background:#0f172a;border:1px solid #475569;border-radius:8px;color:#e2e8f0;font-size:0.95rem;">
            </div>
            <div style="margin-bottom:15px;">
                <label style="color:#94a3b8;font-size:0.85rem;cursor:pointer;">
                    <input type="checkbox" name="remember" style="margin-right:6px;">
                    Recordarme
                </label>
            </div>
            <button type="submit" class="btn" style="width:100%;">Entrar</button>
        </form>
    </div>
</div>
</body>
</html>
