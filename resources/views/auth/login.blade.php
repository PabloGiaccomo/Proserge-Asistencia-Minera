<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Proserge</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-container {
            width: 100%;
            max-width: 400px;
        }
        
        .login-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 40px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4);
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 32px;
        }
        
        .login-logo {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, #19D3C5 0%, #14b5a8 100%);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            box-shadow: 0 4px 12px rgba(25, 211, 197, 0.3);
        }
        
        .login-logo svg {
            width: 28px;
            height: 28px;
            color: white;
        }
        
        .login-title {
            color: #0f172a;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .login-subtitle {
            color: #64748b;
            font-size: 14px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            color: #334155;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 6px;
        }
        
        .form-input {
            width: 100%;
            padding: 12px 14px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            color: #0f172a;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #19D3C5;
            box-shadow: 0 0 0 3px rgba(25, 211, 197, 0.15);
            background: #fff;
        }
        
        .form-input::placeholder {
            color: #94a3b8;
        }
        
        .btn-primary {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #19D3C5 0%, #14b5a8 100%);
            border: none;
            border-radius: 12px;
            color: white;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(25, 211, 197, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(25, 211, 197, 0.4);
        }
        
        .btn-quick {
            width: 100%;
            padding: 10px;
            background: #f1f5f9;
            border: 1px dashed #cbd5e1;
            border-radius: 8px;
            color: #475569;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            margin-bottom: 12px;
        }
        
        .btn-quick:hover {
            background: #e2e8f0;
            border-color: #94a3b8;
        }
        
        .alert {
            padding: 12px 14px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
        }
        
        .alert-error svg {
            width: 18px;
            height: 18px;
        }
        
        .divider {
            display: flex;
            align-items: center;
            margin: 20px 0;
            color: #94a3b8;
            font-size: 12px;
        }
        
        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e2e8f0;
        }
        
        .divider span {
            padding: 0 12px;
        }
        
        .footer-text {
            text-align: center;
            margin-top: 24px;
            color: #94a3b8;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="login-logo">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 21h18"/>
                        <path d="M5 21V7l8-4v18"/>
                        <path d="M19 21V11l-6-4"/>
                    </svg>
                </div>
                <h1 class="login-title">Proserge</h1>
                <p class="login-subtitle">Sistema de Gestion Operativa</p>
            </div>
            
            @if(session('error'))
                <div class="alert alert-error">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    {{ session('error') }}
                </div>
            @endif
            
            <form action="{{ route('login.post') }}" method="POST">
                @csrf
                <div class="form-group">
                    <label class="form-label" for="email">Correo Electronico</label>
                    <input type="email" id="email" name="email" class="form-input" 
                           placeholder="correo@ejemplo.com" 
                           value="{{ old('email', 'admin@proserge.com') }}" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="password">Contrasena</label>
                    <input type="password" id="password" name="password" class="form-input" 
                           placeholder="........" value="admin123" required>
                </div>
                
                <button type="submit" class="btn-primary">
                    Iniciar Sesion
                </button>
            </form>
            
            <div class="divider">
                <span>Acceso rapido</span>
            </div>
            
            <button type="button" class="btn-quick" onclick="document.getElementById('email').value='admin@proserge.com'; document.getElementById('password').value='admin123';">
                [Llenar admin]
            </button>
            
            <p class="footer-text">
                Proserge v1.0.0
            </p>
        </div>
    </div>
</body>
</html>